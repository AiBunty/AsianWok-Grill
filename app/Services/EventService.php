<?php

declare(strict_types=1);

namespace AWG\Services;

use AWG\Config\Database;
use AWG\Repositories\AppSettingRepository;
use AWG\Repositories\EventBookingRepository;
use AWG\Repositories\EventCheckinLogRepository;
use AWG\Repositories\EventMailLogRepository;
use AWG\Repositories\EventRepository;
use AWG\Repositories\EventTransactionRepository;

final class EventService
{
    private string $qrSecret;

    public function __construct(
        private readonly EventRepository $eventRepo,
        private readonly EventBookingRepository $bookingRepo,
        private readonly EventTransactionRepository $transactionRepo,
        private readonly EventCheckinLogRepository $checkinRepo,
        private readonly OtpService $otpService,
        private readonly MailerService $mailer,
        private readonly ?EventMailLogRepository $mailLogRepo = null,
    ) {
        $this->qrSecret = (string) ($_ENV['EVENT_QR_SECRET'] ?? $_ENV['APP_KEY'] ?? 'awg-event-secret');
        $this->ensureSchema();
        $this->normalizeLegacySeedEvents();
    }

    public function eventsList(bool $includeInactive = false): array
    {
        $this->normalizeLegacySeedEvents();
        $this->autoDeactivateExpiredEvents();

        $events = $includeInactive ? $this->eventRepo->all() : $this->eventRepo->getActive();
        $events = array_map(fn (array $row): array => $this->normalizeEventForApi($row), $events);

        usort($events, static function (array $a, array $b): int {
            $left = (string) ($a['startDateTime'] ?? '');
            $right = (string) ($b['startDateTime'] ?? '');
            return strcmp($right, $left);
        });

        return [
            'ok' => true,
            'events' => $events,
            'count' => count($events),
        ];
    }

    public function eventPopup(string $eventId): array
    {
        return $this->eventDetail($eventId);
    }

    public function eventDetail(string $eventId): array
    {
        $event = $this->findEventByIdOrSlug($eventId);
        if (!$event) {
            return [
                'ok' => false,
                'error' => 'EVENT_NOT_FOUND',
                'message' => 'Event not found.',
            ];
        }

        return [
            'ok' => true,
            'event' => $this->normalizeEventForApi($event),
        ];
    }

    public function sendEventOtp(array $body): array
    {
        try {
            $eventId = trim((string) ($body['eventId'] ?? $body['event_id'] ?? ''));
            $email = strtolower(trim((string) ($body['customerEmail'] ?? $body['email'] ?? '')));
            $customerName = trim((string) ($body['customerName'] ?? $body['customer_name'] ?? 'Guest'));

            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                return ['ok' => false, 'error' => 'INVALID_EMAIL', 'message' => 'Enter a valid email address.'];
            }

            $event = $this->findEventByIdOrSlug($eventId);
            if (!$event) {
                return [
                    'ok' => false,
                    'error' => 'EVENT_NOT_FOUND',
                    'message' => 'Event not found.',
                ];
            }
            $eventId = $this->eventIdFromEvent($event, $eventId);
            if (!$this->isEventOpenForRegistration($event)) {
                return ['ok' => false, 'error' => 'EVENT_NOT_ACTIVE', 'message' => 'This event is not active for registration.'];
            }

            $otpPayload = $this->otpService->sendEventOtp($eventId, $email, $customerName);
            if (($otpPayload['ok'] ?? false) !== true) {
                return $otpPayload;
            }

            $otp = (string) ($otpPayload['otp'] ?? '');
            $mailSent = $this->mailer->sendEventOtp($email, $otp, (string) ($event['title'] ?? 'Event'), [
                'event_id' => $eventId,
                'customer_name' => $customerName,
                'recipient_email' => $email,
            ]);

            if (!$mailSent) {
                return [
                    'ok' => false,
                    'error' => 'OTP_EMAIL_SEND_FAILED',
                    'message' => $this->mailer->getLastError() ?: 'Unable to send OTP email. Please check SMTP configuration.',
                ];
            }

            unset($otpPayload['otp']);
            return $otpPayload;
        } catch (\Throwable $exception) {
            error_log('sendEventOtp failed: ' . $exception->getMessage());
            return [
                'ok' => false,
                'error' => 'OTP_SEND_FAILED',
                'message' => 'Unable to send OTP right now. Please try again shortly.',
            ];
        }
    }

    public function verifyEventOtp(array $body): array
    {
        $eventId = trim((string) ($body['eventId'] ?? $body['event_id'] ?? ''));
        $email = strtolower(trim((string) ($body['customerEmail'] ?? $body['email'] ?? '')));
        $otp = trim((string) ($body['otp'] ?? ''));

        $event = $this->findEventByIdOrSlug($eventId);
        if (!$event) {
            return ['ok' => false, 'error' => 'EVENT_NOT_FOUND', 'message' => 'Event not found.'];
        }
        $eventId = $this->eventIdFromEvent($event, $eventId);

        return $this->otpService->verifyEventOtp($eventId, $email, $otp);
    }

    public function registerFreeEvent(array $body): array
    {
        $eventId = trim((string) ($body['eventId'] ?? $body['event_id'] ?? ''));
        $event = $this->findEventByIdOrSlug($eventId);
        if (!$event) {
            return ['ok' => false, 'error' => 'EVENT_NOT_FOUND', 'message' => 'Event not found.'];
        }
        $eventId = $this->eventIdFromEvent($event, $eventId);
        if (!$this->isEventOpenForRegistration($event)) {
            return ['ok' => false, 'error' => 'EVENT_NOT_ACTIVE', 'message' => 'This event is not active for registration.'];
        }

        $eventType = strtolower((string) ($event['event_type'] ?? $event['eventType'] ?? $event['type'] ?? 'free'));
        if ($eventType !== 'free') {
            return ['ok' => false, 'error' => 'EVENT_IS_PAID', 'message' => 'Use payment flow for paid events.'];
        }

        $validation = $this->validateRegistrationPayload($body);
        if (($validation['ok'] ?? false) !== true) {
            return $validation;
        }

        $customerName = trim((string) ($body['customerName'] ?? $body['customer_name'] ?? $body['name'] ?? ''));
        $email = strtolower(trim((string) ($body['customerEmail'] ?? $body['email'] ?? '')));
        $phone = trim((string) ($body['customerPhone'] ?? $body['phone'] ?? ''));
        $qty = max(1, (int) ($body['qty'] ?? $body['guest_count'] ?? 1));
        $attendeeDetails = $this->normalizeAttendeeDetails($body, $qty, $customerName);
        $attendeeNames = array_values(array_filter(array_map(
            static fn (array $row): string => trim((string) ($row['name'] ?? '')),
            $attendeeDetails
        ), static fn (string $name): bool => $name !== ''));
        $verificationToken = (string) ($body['verificationToken'] ?? $body['verification_token'] ?? '');

        if (!$this->otpService->validateVerificationToken($eventId, $email, $verificationToken)) {
            return ['ok' => false, 'error' => 'OTP_VERIFICATION_REQUIRED', 'message' => 'Please verify your email OTP again before continuing.'];
        }

        $duplicate = $this->transactionRepo->findRecentDuplicate($eventId, $email, $phone);
        if ($duplicate) {
            $duplicateTransactionId = (string) ($duplicate['transaction_id'] ?? '');
            return ['ok' => false, 'error' => 'DUPLICATE_BOOKING', 'message' => 'Existing booking found for this event.', 'transaction_id' => $duplicateTransactionId, 'transactionId' => $duplicateTransactionId];
        }

        $transactionId = 'txn_' . bin2hex(random_bytes(8));
        $transactionData = [
            'transaction_id' => $transactionId,
            'event_id' => $eventId,
            'event_title' => (string) ($event['title'] ?? ''),
            'customer_name' => $customerName,
            'customer_email' => $email,
            'customer_phone' => $phone,
            'qty' => $qty,
            'amount' => 0,
            'currency' => 'INR',
            'gateway' => 'free',
            'order_id' => '',
            'payment_id' => '',
            'status' => 'free_confirmed',
            'email_status' => 'pending',
            'attendee_details' => json_encode($attendeeDetails, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'guest_passes_json' => json_encode($this->buildGuestPasses($transactionId, $qty, $attendeeNames), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ];

        $this->transactionRepo->create($transactionData);
        $transaction = $this->transactionRepo->findByTransactionId($transactionId);
        if (!$transaction) {
            return ['ok' => false, 'error' => 'TRANSACTION_CREATE_FAILED', 'message' => 'Could not create booking transaction.'];
        }

        $qrPayload = $this->buildQrPayloadFromTransaction($transaction, 'free');
        $qrUrl = $this->buildQrUrl($qrPayload);
        $this->transactionRepo->updateQr($transactionId, $qrPayload, $qrUrl);

        $emailContext = $this->buildEventEmailContext($event);

        $emailSent = $this->mailer->sendEventRegistrationConfirmation($email, [
            'event_id' => $eventId,
            'event_title' => (string) ($event['title'] ?? ''),
            'customer_name' => $customerName,
            'qty' => $qty,
            'attendeeNames' => $attendeeNames,
            'event_subtitle' => (string) ($event['subtitle'] ?? ''),
            'createdAt' => (string) ($transaction['created_at'] ?? date('Y-m-d H:i:s')),
            'eventStartAt' => $this->formatEventDateTime((string) ($event['start_date'] ?? ''), (string) ($event['start_time'] ?? '')),
            'eventEndAt' => $this->formatEventDateTime((string) ($event['end_date'] ?? ''), (string) ($event['end_time'] ?? '')),
            'amount' => '0',
            'currency' => (string) ($event['currency'] ?? 'INR'),
            'isFreeRegistration' => true,
            'transaction_id' => $transactionId,
            'transactionId' => $transactionId,
            'verificationUrl' => $this->buildVerificationUrl($transactionId),
            'staffCheckinUrl' => $this->buildStaffCheckinUrl($qrPayload),
            'qr_url' => $qrUrl,
            'qrUrl' => $qrUrl,
            'eventVenue' => $emailContext['venue'],
            'supportPhone' => $emailContext['supportPhone'],
            'policyText' => $emailContext['policyText'],
        ]);
        $this->transactionRepo->updateEmailStatus($transactionId, $emailSent ? 'sent' : 'failed');
        $this->pushCrmEventRegistration($this->transactionRepo->findByTransactionId($transactionId) ?: $transaction);

        return [
            'ok' => true,
            'message' => $emailSent
                ? 'Free event registration complete.'
                : 'Free event registration complete, but email delivery failed. Please retry from admin mail logs.',
            'transaction_id' => $transactionId,
            'status' => 'free_confirmed',
            'qr_url' => $qrUrl,
            'qr_payload' => $qrPayload,
            'email_sent' => $emailSent,
        ];
    }

    public function createEventOrder(array $body): array
    {
        $eventId = trim((string) ($body['eventId'] ?? $body['event_id'] ?? ''));
        $event = $this->findEventByIdOrSlug($eventId);
        if (!$event) {
            return ['ok' => false, 'error' => 'EVENT_NOT_FOUND', 'message' => 'Event not found.'];
        }
        $eventId = $this->eventIdFromEvent($event, $eventId);
        if (!$this->isEventOpenForRegistration($event)) {
            return ['ok' => false, 'error' => 'EVENT_NOT_ACTIVE', 'message' => 'This event is not active for registration.'];
        }

        $eventType = strtolower((string) ($event['event_type'] ?? $event['eventType'] ?? $event['type'] ?? 'free'));
        if ($eventType !== 'paid') {
            return ['ok' => false, 'error' => 'EVENT_IS_FREE', 'message' => 'Use free registration flow for free events.'];
        }

        $validation = $this->validateRegistrationPayload($body);
        if (($validation['ok'] ?? false) !== true) {
            return $validation;
        }

        $customerName = trim((string) ($body['customerName'] ?? $body['customer_name'] ?? $body['name'] ?? ''));
        $email = strtolower(trim((string) ($body['customerEmail'] ?? $body['email'] ?? '')));
        $phone = trim((string) ($body['customerPhone'] ?? $body['phone'] ?? ''));
        $qty = max(1, (int) ($body['qty'] ?? 1));
        $attendeeDetails = $this->normalizeAttendeeDetails($body, $qty, $customerName);
        $attendeeNames = array_values(array_filter(array_map(
            static fn (array $row): string => trim((string) ($row['name'] ?? '')),
            $attendeeDetails
        ), static fn (string $name): bool => $name !== ''));
        $verificationToken = (string) ($body['verificationToken'] ?? $body['verification_token'] ?? '');

        if (!$this->otpService->validateVerificationToken($eventId, $email, $verificationToken)) {
            return ['ok' => false, 'error' => 'OTP_VERIFICATION_REQUIRED', 'message' => 'Please verify your email OTP again before continuing.'];
        }

        $duplicate = $this->transactionRepo->findRecentDuplicate($eventId, $email, $phone);
        if ($duplicate) {
            $duplicateTransactionId = (string) ($duplicate['transaction_id'] ?? '');
            return ['ok' => false, 'error' => 'DUPLICATE_BOOKING', 'message' => 'Existing booking found for this event.', 'transaction_id' => $duplicateTransactionId, 'transactionId' => $duplicateTransactionId];
        }

        $price = (float) ($event['ticket_price'] ?? $event['ticketPrice'] ?? 0);
        $amount = max(0, $price * $qty);
        $transactionId = 'txn_' . bin2hex(random_bytes(8));
        $orderId = (string) ($body['order_id'] ?? ('order_' . bin2hex(random_bytes(8))));

        $this->transactionRepo->create([
            'transaction_id' => $transactionId,
            'event_id' => $eventId,
            'event_title' => (string) ($event['title'] ?? ''),
            'customer_name' => $customerName,
            'customer_email' => $email,
            'customer_phone' => $phone,
            'qty' => $qty,
            'amount' => $amount,
            'currency' => 'INR',
            'gateway' => 'razorpay',
            'order_id' => $orderId,
            'status' => 'pending',
            'attendee_details' => json_encode($attendeeDetails, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'guest_passes_json' => json_encode($this->buildGuestPasses($transactionId, $qty, $attendeeNames), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ]);

        return [
            'ok' => true,
            'transaction_id' => $transactionId,
            'order_id' => $orderId,
            'amount' => $amount,
            'currency' => 'INR',
            'gateway' => 'razorpay',
            'status' => 'pending',
        ];
    }

    public function confirmEventPayment(array $body): array
    {
        $orderId = trim((string) ($body['order_id'] ?? ''));
        $paymentId = trim((string) ($body['payment_id'] ?? ''));
        $signature = trim((string) ($body['signature'] ?? $body['razorpay_signature'] ?? ''));

        $transaction = $this->transactionRepo->findByOrderId($orderId);
        if (!$transaction) {
            return ['ok' => false, 'error' => 'ORDER_NOT_FOUND', 'message' => 'Order not found.'];
        }

        if ($this->isSignatureValidationEnabled() && !$this->validateGatewaySignature($orderId, $paymentId, $signature)) {
            $this->transactionRepo->updatePaymentStatus((string) $transaction['transaction_id'], 'failed', $paymentId, $orderId);
            return ['ok' => false, 'error' => 'PAYMENT_SIGNATURE_INVALID', 'message' => 'Payment signature verification failed.'];
        }

        $transactionId = (string) ($transaction['transaction_id'] ?? '');
        $this->transactionRepo->updatePaymentStatus($transactionId, 'paid', $paymentId, $orderId);

        $fresh = $this->transactionRepo->findByTransactionId($transactionId) ?: $transaction;
        $event = $this->eventRepo->findById((string) ($fresh['event_id'] ?? '')) ?? [];
        $emailContext = $this->buildEventEmailContext($event);
        $qrPayload = $this->buildQrPayloadFromTransaction($fresh, $paymentId);
        $qrUrl = $this->buildQrUrl($qrPayload);
        $this->transactionRepo->updateQr($transactionId, $qrPayload, $qrUrl);
        $attendeeNames = $this->extractAttendeeNames($fresh);

        $emailSent = $this->mailer->sendEventRegistrationConfirmation((string) ($fresh['customer_email'] ?? ''), [
            'event_id' => (string) ($fresh['event_id'] ?? ''),
            'event_title' => (string) ($fresh['event_title'] ?? ''),
            'event_subtitle' => (string) ($event['subtitle'] ?? ''),
            'customer_name' => (string) ($fresh['customer_name'] ?? ''),
            'qty' => (int) ($fresh['qty'] ?? 1),
            'attendeeNames' => $attendeeNames,
            'createdAt' => (string) ($fresh['created_at'] ?? date('Y-m-d H:i:s')),
            'eventStartAt' => $this->formatEventDateTime((string) ($event['start_date'] ?? ''), (string) ($event['start_time'] ?? '')),
            'eventEndAt' => $this->formatEventDateTime((string) ($event['end_date'] ?? ''), (string) ($event['end_time'] ?? '')),
            'amount' => (string) ($fresh['amount'] ?? ''),
            'currency' => (string) ($fresh['currency'] ?? ($event['currency'] ?? 'INR')),
            'isFreeRegistration' => false,
            'transaction_id' => $transactionId,
            'transactionId' => $transactionId,
            'verificationUrl' => $this->buildVerificationUrl($transactionId),
            'staffCheckinUrl' => $this->buildStaffCheckinUrl($qrPayload),
            'qr_url' => $qrUrl,
            'qrUrl' => $qrUrl,
            'eventVenue' => $emailContext['venue'],
            'supportPhone' => $emailContext['supportPhone'],
            'policyText' => $emailContext['policyText'],
        ]);
        $this->transactionRepo->updateEmailStatus($transactionId, $emailSent ? 'sent' : 'failed');
        $this->pushCrmEventRegistration($this->transactionRepo->findByTransactionId($transactionId) ?: $fresh);

        return [
            'ok' => true,
            'message' => $emailSent
                ? 'Payment confirmed.'
                : 'Payment confirmed, but email delivery failed. Please retry from admin mail logs.',
            'transaction_id' => $transactionId,
            'status' => 'paid',
            'qr_url' => $qrUrl,
            'qr_payload' => $qrPayload,
            'email_sent' => $emailSent,
        ];
    }

    public function resendEventConfirmation(array $body): array
    {
        $transactionId = trim((string) ($body['transactionId'] ?? $body['transaction_id'] ?? ''));
        $transaction = $this->transactionRepo->findByTransactionId($transactionId);
        if (!$transaction) {
            return ['ok' => false, 'error' => 'TRANSACTION_NOT_FOUND', 'message' => 'Transaction not found.'];
        }

        $qrPayload = (string) ($transaction['qr_payload'] ?? '');
        $qrUrl = (string) ($transaction['qr_url'] ?? '');
        if ($qrPayload === '' || $qrUrl === '') {
            $qrPayload = $this->buildQrPayloadFromTransaction($transaction, (string) ($transaction['payment_id'] ?? 'free'));
            $qrUrl = $this->buildQrUrl($qrPayload);
            $this->transactionRepo->updateQr($transactionId, $qrPayload, $qrUrl);
        }

        $event = $this->eventRepo->findById((string) ($transaction['event_id'] ?? '')) ?? [];
        $emailContext = $this->buildEventEmailContext($event);

        $sent = $this->mailer->sendEventRegistrationConfirmation((string) ($transaction['customer_email'] ?? ''), [
            'event_id' => (string) ($transaction['event_id'] ?? ''),
            'event_title' => (string) ($transaction['event_title'] ?? ''),
            'event_subtitle' => (string) ($event['subtitle'] ?? ''),
            'customer_name' => (string) ($transaction['customer_name'] ?? ''),
            'qty' => (int) ($transaction['qty'] ?? 1),
            'attendeeNames' => $this->extractAttendeeNames($transaction),
            'createdAt' => (string) ($transaction['created_at'] ?? date('Y-m-d H:i:s')),
            'eventStartAt' => $this->formatEventDateTime((string) ($event['start_date'] ?? ''), (string) ($event['start_time'] ?? '')),
            'eventEndAt' => $this->formatEventDateTime((string) ($event['end_date'] ?? ''), (string) ($event['end_time'] ?? '')),
            'amount' => (string) ($transaction['amount'] ?? ''),
            'currency' => (string) ($transaction['currency'] ?? 'INR'),
            'isFreeRegistration' => in_array((string) ($transaction['status'] ?? ''), ['free_confirmed', 'free'], true),
            'transaction_id' => $transactionId,
            'transactionId' => $transactionId,
            'verificationUrl' => $this->buildVerificationUrl($transactionId),
            'staffCheckinUrl' => $this->buildStaffCheckinUrl($qrPayload),
            'qr_url' => $qrUrl,
            'qrUrl' => $qrUrl,
            'eventVenue' => $emailContext['venue'],
            'supportPhone' => $emailContext['supportPhone'],
            'policyText' => $emailContext['policyText'],
        ]);

        $this->transactionRepo->updateEmailStatus($transactionId, $sent ? 'sent' : 'failed');

        return [
            'ok' => $sent,
            'message' => $sent ? 'Confirmation email sent again.' : 'Unable to resend now, please retry.',
            'transaction_id' => $transactionId,
            'transactionId' => $transactionId,
        ];
    }

    public function requestEventCancellation(array $body): array
    {
        $transactionId = trim((string) ($body['transaction_id'] ?? ''));
        $transaction = $this->transactionRepo->findByTransactionId($transactionId);
        if (!$transaction) {
            return ['ok' => false, 'error' => 'TRANSACTION_NOT_FOUND', 'message' => 'Transaction not found.'];
        }

        $this->transactionRepo->updatePaymentStatus($transactionId, 'cancel_requested', (string) ($transaction['payment_id'] ?? ''), (string) ($transaction['order_id'] ?? ''));

        return [
            'ok' => true,
            'message' => 'Cancellation request submitted.',
            'transaction_id' => $transactionId,
            'status' => 'cancel_requested',
        ];
    }

    public function verifyEventQr(array $body): array
    {
        $passcodeResult = $this->validateEventEntryPasscode((string) ($body['passcode'] ?? ''));
        if (($passcodeResult['ok'] ?? false) !== true) {
            return $passcodeResult;
        }

        $payload = $this->extractQrPayload($body) ?? [];
        $tx = trim((string) ($payload['tx'] ?? $body['tx'] ?? $body['transactionId'] ?? $body['transaction_id'] ?? ''));
        if ($tx === '') {
            return ['ok' => false, 'error' => 'TRANSACTION_REQUIRED', 'message' => 'transactionId (or tx) is required.'];
        }

        if (!empty($payload['sig']) && !$this->isValidQrSignature($payload)) {
            return ['ok' => false, 'error' => 'QR_SIGNATURE_INVALID', 'message' => 'QR signature mismatch.'];
        }

        $preview = $this->isPreviewMode($body);
        $transaction = $this->transactionRepo->findByTransactionId($tx);
        if (!$transaction) {
            return ['ok' => false, 'error' => 'TRANSACTION_NOT_FOUND', 'message' => 'Transaction not found.'];
        }

        $status = (string) ($transaction['status'] ?? '');
        if (!in_array($status, ['paid', 'free_confirmed', 'checked_in', 'checked_in_free'], true)) {
            return ['ok' => false, 'error' => 'PAYMENT_NOT_CONFIRMED', 'message' => 'Ticket is not payment confirmed.'];
        }

        $qty = max(1, (int) ($transaction['qty'] ?? 1));
        $checkedInCount = (int) ($transaction['checked_in_count'] ?? 0);
        $remaining = max(0, $qty - $checkedInCount);

        $history = $this->formatCheckinHistory($this->checkinRepo->getByTransaction((string) ($transaction['transaction_id'] ?? '')));
        $remainingGuestNames = $this->calculateRemainingGuestNames($transaction, $history, $remaining);

        if ($remaining <= 0) {
            return [
                'ok' => false,
                'error' => 'NO_MORE_ENTRY',
                'message' => 'No more entry allowed for this ticket.',
                'ticket_closed' => true,
                'remainingEntries' => 0,
                'history' => $history,
                'booking' => $this->buildBookingSummary($transaction, $qty, $checkedInCount, 0, []),
            ];
        }

        if ($preview) {
            return [
                'ok' => true,
                'preview' => true,
                'transactionId' => (string) ($transaction['transaction_id'] ?? ''),
                'remainingEntries' => $remaining,
                'remainingGuestNames' => $remainingGuestNames,
                'history' => $history,
                'booking' => $this->buildBookingSummary($transaction, $qty, $checkedInCount, $remaining, $remainingGuestNames),
            ];
        }

        $selectedGuestNames = $this->normalizeSelectedGuestNames($body);
        if ($remaining > 0 && $selectedGuestNames === []) {
            return [
                'ok' => false,
                'error' => 'SELECT_GUEST_REQUIRED',
                'message' => 'Select at least 1 guest name to confirm check-in.',
                'remainingEntries' => $remaining,
                'remainingGuestNames' => $remainingGuestNames,
            ];
        }

        $admittedCount = max(1, (int) ($body['admittedCount'] ?? $body['admitted_count'] ?? count($selectedGuestNames)));
        if ($admittedCount > $remaining) {
            return ['ok' => false, 'error' => 'ADMISSION_EXCEEDS_REMAINING', 'message' => 'Admission count exceeds remaining passes.', 'remaining' => $remaining];
        }
        if (count($selectedGuestNames) > 0) {
            $admittedCount = count($selectedGuestNames);
        }

        $verifiedBy = trim((string) ($body['verifiedBy'] ?? $body['verified_by'] ?? 'staff'));
        if ($verifiedBy === '') {
            $verifiedBy = 'staff';
        }
        $source = trim((string) ($body['source'] ?? 'email_link'));
        if ($source === '') {
            $source = 'email_link';
        }

        $this->transactionRepo->applyCheckin((string) ($transaction['transaction_id'] ?? ''), $admittedCount, $verifiedBy);
        $this->checkinRepo->create([
            'transaction_id' => (string) ($transaction['transaction_id'] ?? ''),
            'event_id' => (string) ($transaction['event_id'] ?? ''),
            'admitted_count' => $admittedCount,
            'guest_names_json' => json_encode(array_values($selectedGuestNames), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'verified_by' => $verifiedBy,
            'source' => $source,
        ]);

        $updated = $this->transactionRepo->findByTransactionId((string) ($transaction['transaction_id'] ?? '')) ?: $transaction;
        $newRemaining = max(0, ((int) ($updated['qty'] ?? $qty)) - ((int) ($updated['checked_in_count'] ?? $checkedInCount)));
        $updatedHistory = $this->formatCheckinHistory($this->checkinRepo->getByTransaction((string) ($updated['transaction_id'] ?? '')));
        $updatedRemainingNames = $this->calculateRemainingGuestNames($updated, $updatedHistory, $newRemaining);

        $this->mailer->sendEventCheckinNotification((string) ($updated['customer_email'] ?? ''), [
            'event_id' => (string) ($updated['event_id'] ?? ''),
            'event_title' => (string) ($updated['event_title'] ?? ''),
            'transaction_id' => (string) ($updated['transaction_id'] ?? ''),
            'admitted_count' => $admittedCount,
            'remaining' => $newRemaining,
        ]);
        $this->pushCrmEventScanEntry($updated, $admittedCount, $newRemaining);

        return [
            'ok' => true,
            'message' => 'Check-in successful.',
            'transactionId' => (string) ($updated['transaction_id'] ?? ''),
            'admittedCount' => $admittedCount,
            'remainingEntries' => $newRemaining,
            'checkinStatus' => (string) ($updated['checkin_status'] ?? 'not_checked_in'),
            'history' => $updatedHistory,
            'remainingGuestNames' => $updatedRemainingNames,
            'booking' => $this->buildBookingSummary($updated, (int) ($updated['qty'] ?? $qty), (int) ($updated['checked_in_count'] ?? 0), $newRemaining, $updatedRemainingNames),
        ];
    }

    public function adminBatchCheckinEventQr(array $body): array
    {
        $tickets = $body['tickets'] ?? $body['tokens'] ?? [];
        if (!is_array($tickets)) {
            return ['ok' => false, 'error' => 'INVALID_BATCH_PAYLOAD', 'message' => 'tickets/tokens must be an array.'];
        }

        $results = [];
        $success = 0;
        $failed = 0;

        foreach ($tickets as $ticket) {
            if (!is_array($ticket)) {
                if (is_string($ticket) && trim($ticket) !== '') {
                    $ticket = ['guestToken' => trim($ticket), 'passcode' => (string) ($body['passcode'] ?? '')];
                } else {
                    $results[] = ['ok' => false, 'error' => 'INVALID_TICKET_ITEM'];
                    $failed++;
                    continue;
                }
            }

            if (!array_key_exists('passcode', $ticket) && array_key_exists('passcode', $body)) {
                $ticket['passcode'] = (string) $body['passcode'];
            }
            if (!array_key_exists('previewOnly', $ticket)) {
                $ticket['previewOnly'] = 0;
            }

            if (!is_array($ticket)) {
                $results[] = ['ok' => false, 'error' => 'INVALID_TICKET_ITEM'];
                $failed++;
                continue;
            }

            $ticket['preview'] = false;
            $res = $this->verifyEventQr($ticket);
            $results[] = $res;
            if (($res['ok'] ?? false) === true) {
                $success++;
            } else {
                $failed++;
            }
        }

        return [
            'ok' => $failed === 0,
            'message' => 'Batch check-in completed.',
            'success_count' => $success,
            'failed_count' => $failed,
            'results' => $results,
        ];
    }

    public function eventGuestReport(array $query): array
    {
        $eventId = trim((string) ($query['eventId'] ?? $query['event_id'] ?? ''));
        $event = $this->eventRepo->findById($eventId);
        if (!$event) {
            return ['ok' => false, 'error' => 'EVENT_NOT_FOUND', 'message' => 'Event not found.'];
        }

        $status = trim((string) ($query['status'] ?? ''));
        $search = strtolower(trim((string) ($query['search'] ?? '')));

        $transactions = $this->transactionRepo->listByEvent($eventId, 2000);
        if ($status !== '') {
            $transactions = array_values(array_filter($transactions, static fn(array $row): bool => (string) ($row['status'] ?? '') === $status));
        }
        if ($search !== '') {
            $transactions = array_values(array_filter($transactions, static function (array $row) use ($search): bool {
                $needle = strtolower((string) (($row['customer_name'] ?? '') . ' ' . ($row['customer_email'] ?? '') . ' ' . ($row['customer_phone'] ?? '') . ' ' . ($row['transaction_id'] ?? '')));
                return str_contains($needle, $search);
            }));
        }

        $summary = $this->buildTransactionSummary($transactions);
        $checkinSummary = $this->checkinRepo->getSummaryByEvent($eventId);

        return [
            'ok' => true,
            'event' => $event,
            'guests' => $transactions,
            'summary' => array_merge($summary, [
                'total_checked_in' => $checkinSummary['total_admitted'] ?? 0,
                'last_checkin_at' => $checkinSummary['last_checkin_at'] ?? '',
            ]),
        ];
    }

    public function eventTransactionsReport(array $query): array
    {
        $eventId = trim((string) ($query['eventId'] ?? $query['event_id'] ?? ''));
        $transactions = $this->transactionRepo->listByEvent($eventId, 2000);
        return [
            'ok' => true,
            'transactions' => $transactions,
            'summary' => $this->buildTransactionSummary($transactions),
        ];
    }

    public function adminMailLogReport(array $query): array
    {
        $eventId = trim((string) ($query['eventId'] ?? $query['event_id'] ?? ''));
        $limit = max(1, (int) ($query['limit'] ?? 100));

        if (!$this->mailLogRepo instanceof EventMailLogRepository) {
            return [
                'ok' => true,
                'summary' => ['total' => 0, 'total_sent' => 0, 'total_failed' => 0],
                'logs' => [],
            ];
        }

        return [
            'ok' => true,
            'summary' => $this->mailLogRepo->summaryByEvent($eventId),
            'logs' => $this->mailLogRepo->listByEvent($eventId, $limit),
        ];
    }

    public function adminCreateOrUpdateEvent(array $body): array
    {
        $this->normalizeLegacySeedEvents();
        $eventId = trim((string) ($body['id'] ?? $body['event_id'] ?? ''));
        if ($eventId === '') {
            $created = $this->eventRepo->create($body);
            return ['ok' => true, 'message' => 'Event created.', 'event_id' => $created];
        }

        $this->eventRepo->update($eventId, $body);
        return ['ok' => true, 'message' => 'Event updated.', 'event_id' => $eventId];
    }

    public function adminToggleEvent(array $body): array
    {
        $this->normalizeLegacySeedEvents();
        $eventId = trim((string) ($body['id'] ?? $body['event_id'] ?? ''));
        $isActive = (bool) ($body['isActive'] ?? $body['is_active'] ?? false);
        $this->eventRepo->toggleActive($eventId, $isActive);

        return [
            'ok' => true,
            'message' => 'Event status updated.',
            'event_id' => $eventId,
            'is_active' => $isActive,
        ];
    }

    public function adminDeleteEvent(array $body): array
    {
        $this->normalizeLegacySeedEvents();
        $eventId = trim((string) ($body['id'] ?? $body['event_id'] ?? ''));
        $this->eventRepo->delete($eventId);
        return ['ok' => true, 'message' => 'Event deleted.', 'event_id' => $eventId];
    }

    public function adminCloneEvent(array $body): array
    {
        $this->normalizeLegacySeedEvents();
        $eventId = trim((string) ($body['id'] ?? $body['event_id'] ?? ''));
        $event = $this->eventRepo->findById($eventId);
        if (!$event) {
            return ['ok' => false, 'error' => 'EVENT_NOT_FOUND', 'message' => 'Event not found.'];
        }

        unset($event['id']);
        $event['title'] = '(Clone) ' . (string) ($event['title'] ?? 'Untitled Event');
        $newId = $this->eventRepo->create($event);

        return ['ok' => true, 'message' => 'Event cloned.', 'event_id' => $newId];
    }

    public function adminEventImageUpload(array $body): array
    {
        $eventId = trim((string) ($body['event_id'] ?? $body['id'] ?? ''));

        if ($eventId === '') {
            return ['ok' => false, 'error' => 'INVALID_IMAGE_UPLOAD', 'message' => 'event_id is required.'];
        }

        $event = $this->eventRepo->findById($eventId);
        if (!$event) {
            return ['ok' => false, 'error' => 'EVENT_NOT_FOUND', 'message' => 'Event not found.'];
        }

        $imageUrl = trim((string) ($body['image_url'] ?? ''));

        if (isset($_FILES['eventImage']) && is_array($_FILES['eventImage']) && (int) ($_FILES['eventImage']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            $upload = $_FILES['eventImage'];
            $errorCode = (int) ($upload['error'] ?? UPLOAD_ERR_OK);
            if ($errorCode !== UPLOAD_ERR_OK) {
                return ['ok' => false, 'error' => 'UPLOAD_FAILED', 'message' => 'Image upload failed.'];
            }

            $tmpPath = (string) ($upload['tmp_name'] ?? '');
            if ($tmpPath === '' || !is_uploaded_file($tmpPath)) {
                return ['ok' => false, 'error' => 'UPLOAD_INVALID', 'message' => 'Uploaded file is invalid.'];
            }

            $maxBytes = 8 * 1024 * 1024;
            $size = (int) ($upload['size'] ?? 0);
            if ($size <= 0 || $size > $maxBytes) {
                return ['ok' => false, 'error' => 'UPLOAD_TOO_LARGE', 'message' => 'Image must be smaller than 8 MB.'];
            }

            $ext = '';
            if (class_exists('finfo')) {
                $finfo = new \finfo(FILEINFO_MIME_TYPE);
                $mime = (string) $finfo->file($tmpPath);
                $ext = match ($mime) {
                    'image/jpeg' => 'jpg',
                    'image/png' => 'png',
                    'image/webp' => 'webp',
                    default => '',
                };
            }

            if ($ext === '') {
                $nameExt = strtolower((string) pathinfo((string) ($upload['name'] ?? ''), PATHINFO_EXTENSION));
                if (in_array($nameExt, ['jpg', 'jpeg'], true)) {
                    $ext = 'jpg';
                } elseif (in_array($nameExt, ['png', 'webp'], true)) {
                    $ext = $nameExt;
                }
            }
            if ($ext === '') {
                return ['ok' => false, 'error' => 'UPLOAD_TYPE_NOT_ALLOWED', 'message' => 'Allowed formats: JPG, PNG, WEBP.'];
            }

            $baseDir = dirname(__DIR__, 2) . '/asianwokandgrill.in/assets/event-images';
            if (!is_dir($baseDir) && !@mkdir($baseDir, 0755, true) && !is_dir($baseDir)) {
                return ['ok' => false, 'error' => 'UPLOAD_STORAGE_UNAVAILABLE', 'message' => 'Unable to create image directory.'];
            }

            $safeId = preg_replace('/[^a-zA-Z0-9_-]/', '', (string) ($event['event_id'] ?? $eventId));
            $fileName = 'evt-' . ($safeId !== '' ? $safeId : 'event') . '-' . date('YmdHis') . '-' . bin2hex(random_bytes(3)) . '.' . $ext;
            $targetPath = $baseDir . '/' . $fileName;
            if (!move_uploaded_file($tmpPath, $targetPath)) {
                return ['ok' => false, 'error' => 'UPLOAD_MOVE_FAILED', 'message' => 'Unable to store uploaded image.'];
            }

            $imageUrl = '/assets/event-images/' . $fileName;
        }

        if ($imageUrl === '') {
            return ['ok' => false, 'error' => 'INVALID_IMAGE_UPLOAD', 'message' => 'Upload an image file or provide image_url.'];
        }

        $this->eventRepo->update($eventId, ['image_url' => $imageUrl]);
        return ['ok' => true, 'message' => 'Event image updated.', 'event_id' => $eventId, 'image_url' => $imageUrl];
    }

    // Legacy-compatible wrappers for current UI calls
    public function listEvents(): array
    {
        return $this->eventsList(true);
    }

    public function saveEvent(array $body): array
    {
        return $this->adminCreateOrUpdateEvent($body);
    }

    public function deleteEvent(string $eventId): array
    {
        return $this->adminDeleteEvent(['event_id' => $eventId]);
    }

    public function toggleEvent(string $eventId, bool $isActive): array
    {
        return $this->adminToggleEvent(['event_id' => $eventId, 'is_active' => $isActive]);
    }

    public function cloneEvent(string $eventId): array
    {
        return $this->adminCloneEvent(['event_id' => $eventId]);
    }

    public function registerForEvent(string $eventId, array $data): array
    {
        $data['event_id'] = $eventId;
        $event = $this->eventRepo->findById($eventId);
        if (!$event) {
            return ['ok' => false, 'error' => 'EVENT_NOT_FOUND', 'message' => 'Event not found.'];
        }

        if ((string) ($event['type'] ?? 'free') === 'free') {
            return $this->registerFreeEvent($data);
        }

        return $this->createEventOrder($data);
    }

    public function requestOtpForCheckin(string $phone): array
    {
        return ['ok' => false, 'error' => 'LEGACY_API_DISABLED', 'message' => 'Use send_event_otp with event_id and email.'];
    }

    public function verifyOtpForCheckin(string $phone, string $otp): array
    {
        return ['ok' => false, 'error' => 'LEGACY_API_DISABLED', 'message' => 'Use verify_event_otp with event_id and email.'];
    }

    public function previewQr(string $eventId, string $guestToken): array
    {
        return $this->verifyEventQr(['qr_payload' => $guestToken, 'event_id' => $eventId, 'preview' => true]);
    }

    public function verifyAndCheckin(string $eventId, string $guestToken): array
    {
        return $this->verifyEventQr(['qr_payload' => $guestToken, 'event_id' => $eventId, 'admitted_count' => 1]);
    }

    public function getEventGuestReport(string $eventId, ?string $status = null, ?string $search = null): array
    {
        return $this->eventGuestReport([
            'event_id' => $eventId,
            'status' => (string) $status,
            'search' => (string) $search,
        ]);
    }

    public function getMailLog(string $eventId, int $limit = 100): array
    {
        return $this->adminMailLogReport([
            'event_id' => $eventId,
            'limit' => $limit,
        ]);
    }

    public function generateQrForEvent(string $eventId): array
    {
        $event = $this->eventRepo->findById($eventId);
        if (!$event) {
            return ['ok' => false, 'error' => 'EVENT_NOT_FOUND', 'message' => 'Event not found.'];
        }

        return [
            'ok' => true,
            'message' => 'Use booking-level QR payloads generated on registration/payment confirmation.',
            'event_id' => $eventId,
        ];
    }

    private function buildGuestPasses(string $transactionId, int $qty, array $attendeeNames = []): array
    {
        $passes = [];
        for ($index = 1; $index <= $qty; $index++) {
            $guestId = $transactionId . '_g' . $index;
            $candidateName = trim((string) ($attendeeNames[$index - 1] ?? ''));
            $passes[] = [
                'guest_id' => $guestId,
                'label' => $candidateName !== '' ? $candidateName : 'Guest ' . $index,
            ];
        }

        return $passes;
    }

    private function normalizeEventForApi(array $event): array
    {
        $startDate = (string) ($event['start_date'] ?? $event['date'] ?? '');
        $startTime = (string) ($event['start_time'] ?? $event['time'] ?? '');
        $endDate = (string) ($event['end_date'] ?? $startDate);
        $endTime = (string) ($event['end_time'] ?? $startTime);
        $eventType = strtolower((string) ($event['event_type'] ?? $event['type'] ?? 'free'));
        if ($eventType !== 'paid') {
            $eventType = 'free';
        }

        $ticketPrice = (float) ($event['ticket_price'] ?? $event['ticketPrice'] ?? 0);
        $startDateTime = $startDate . ' ' . ($startTime !== '' ? $startTime : '00:00:00');
        $scheduleSummary = trim((string) ($event['subtitle'] ?? ''));
        if ($scheduleSummary === '' && $startDate !== '') {
            $scheduleSummary = trim($this->formatEventDateTime($startDate, $startTime));
        }

        return array_merge($event, [
            'id' => (string) ($event['event_id'] ?? $event['id'] ?? ''),
            'eventId' => (string) ($event['event_id'] ?? $event['id'] ?? ''),
            'title' => (string) ($event['title'] ?? ''),
            'subtitle' => (string) ($event['subtitle'] ?? ''),
            'scheduleSummary' => $scheduleSummary,
            'date' => $startDate,
            'time' => $startTime,
            'startDate' => $startDate,
            'startTime' => $startTime,
            'endDate' => $endDate,
            'endTime' => $endTime,
            'startDateTime' => $startDateTime,
            'eventType' => $eventType,
            'type' => $eventType,
            'ticketPrice' => $ticketPrice,
            'currency' => (string) ($event['currency'] ?? 'INR'),
            'maxTickets' => (int) ($event['max_tickets'] ?? 0),
            'priority' => (int) ($event['priority'] ?? 0),
            'timeDisplayFormat' => (string) ($event['time_display_format'] ?? '12h'),
            'venue' => (string) ($event['venue'] ?? $event['location'] ?? ''),
            'badgeText' => (string) ($event['badge_text'] ?? ''),
            'ctaText' => (string) ($event['cta_text'] ?? ''),
            'ctaUrl' => (string) ($event['cta_url'] ?? ''),
            'description' => (string) ($event['description'] ?? ''),
            'imageUrl' => (string) ($event['image_url'] ?? ''),
            'videoUrl' => (string) ($event['video_url'] ?? ''),
            'popupDelayHours' => (int) ($event['popup_delay_hours'] ?? 0),
            'popupCooldownHours' => (int) ($event['popup_cooldown_hours'] ?? 24),
            'popupEnabled' => (bool) ($event['popup_enabled'] ?? false),
            'showOncePerSession' => (bool) ($event['show_once_per_session'] ?? true),
            'showVideo' => (bool) ($event['show_video'] ?? false),
            'paymentEnabled' => (bool) ($event['payment_enabled'] ?? ($eventType === 'paid')),
            'cancellationPolicy' => (string) ($event['cancellation_policy'] ?? ''),
            'refundPolicy' => (string) ($event['refund_policy'] ?? ''),
            'isActive' => ((int) ($event['is_active'] ?? 0)) === 1,
        ]);
    }

    private function findEventByIdOrSlug(string $eventId): ?array
    {
        $eventId = trim($eventId);
        if ($eventId === '') {
            return null;
        }

        $event = $this->eventRepo->findById($eventId);
        if ($event) {
            return $event;
        }

        $needle = strtolower($eventId);
        foreach ($this->loadSettingsEvents() as $row) {
            if (!is_array($row)) {
                continue;
            }

            $ids = [
                (string) ($row['id'] ?? ''),
                (string) ($row['event_id'] ?? ''),
                (string) ($row['eventId'] ?? ''),
                (string) ($row['slug'] ?? ''),
                (string) ($row['eventSlug'] ?? ''),
                (string) ($row['event_slug'] ?? ''),
            ];

            foreach ($ids as $candidate) {
                if (strtolower(trim($candidate)) === $needle) {
                    return $row;
                }
            }
        }

        return null;
    }

    private function loadSettingsEvents(): array
    {
        try {
            $settingsRepo = new AppSettingRepository(Database::connection());
            $raw = $settingsRepo->getValue('events', 'items');
            if (!is_string($raw) || trim($raw) === '') {
                $raw = $settingsRepo->getValue('events', 'liveEvents');
            }
            if (!is_string($raw) || trim($raw) === '') {
                $raw = $settingsRepo->getValue('events', 'events');
            }

            if (!is_string($raw) || trim($raw) === '') {
                return [];
            }

            $decoded = json_decode($raw, true);
            return is_array($decoded) ? $decoded : [];
        } catch (\Throwable $exception) {
            return [];
        }
    }

    private function eventIdFromEvent(array $event, string $fallback): string
    {
        return trim((string) ($event['event_id'] ?? $event['eventId'] ?? $event['id'] ?? $fallback));
    }

    private function isEventOpenForRegistration(array $event): bool
    {
        $activeRaw = $event['is_active'] ?? $event['isActive'] ?? true;
        if ((is_bool($activeRaw) && !$activeRaw) || (!is_bool($activeRaw) && (string) $activeRaw === '0')) {
            return false;
        }

        return !$this->isEventExpired($event, new \DateTimeImmutable('now', new \DateTimeZone(date_default_timezone_get() ?: 'Asia/Kolkata')));
    }

    private function validateRegistrationPayload(array $body): array
    {
        $customerName = trim((string) ($body['customerName'] ?? $body['customer_name'] ?? $body['name'] ?? ''));
        $email = strtolower(trim((string) ($body['customerEmail'] ?? $body['email'] ?? '')));
        $countryCode = trim((string) ($body['customerCountryCode'] ?? $body['country_code'] ?? '91'));
        $phone = preg_replace('/\D+/', '', (string) ($body['customerPhone'] ?? $body['phone'] ?? '')) ?? '';
        $qty = (int) ($body['qty'] ?? $body['guest_count'] ?? 0);
        $verificationToken = trim((string) ($body['verificationToken'] ?? $body['verification_token'] ?? ''));

        if ($customerName === '') {
            return ['ok' => false, 'error' => 'VALIDATION_ERROR', 'message' => 'Full name is required.'];
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['ok' => false, 'error' => 'INVALID_EMAIL', 'message' => 'Enter a valid email address.'];
        }
        if ($countryCode === '') {
            return ['ok' => false, 'error' => 'VALIDATION_ERROR', 'message' => 'Country code is required.'];
        }
        if (strlen($phone) !== 10) {
            return ['ok' => false, 'error' => 'VALIDATION_ERROR', 'message' => 'Enter a valid 10 digit phone number.'];
        }
        if ($qty < 1) {
            return ['ok' => false, 'error' => 'VALIDATION_ERROR', 'message' => 'Number of guests must be at least 1.'];
        }
        if ($verificationToken === '') {
            return ['ok' => false, 'error' => 'OTP_VERIFICATION_REQUIRED', 'message' => 'Please verify your email OTP before continuing.'];
        }

        $attendeeCount = count($this->readProvidedAttendeeNames($body));
        if (!($qty === 1 && $attendeeCount === 0) && $attendeeCount !== $qty) {
            return ['ok' => false, 'error' => 'VALIDATION_ERROR', 'message' => 'Guest names count must match number of guests.'];
        }

        return ['ok' => true];
    }

    private function readProvidedAttendeeNames(array $body): array
    {
        $rawRows = $body['attendeeNames'] ?? $body['attendee_names'] ?? $body['attendee_details'] ?? $body['attendees'] ?? [];
        $names = [];

        if (is_array($rawRows)) {
            foreach ($rawRows as $row) {
                $name = is_array($row)
                    ? trim((string) ($row['name'] ?? $row['guestName'] ?? $row['fullName'] ?? ''))
                    : trim((string) $row);
                if ($name !== '') {
                    $names[] = $name;
                }
            }
            return $names;
        }

        $textNames = trim((string) $rawRows);
        if ($textNames === '') {
            return [];
        }

        $parts = preg_split('/[\r\n,]+/', $textNames) ?: [];
        foreach ($parts as $part) {
            $name = trim((string) $part);
            if ($name !== '') {
                $names[] = $name;
            }
        }

        return $names;
    }

    private function autoDeactivateExpiredEvents(): void
    {
        $allEvents = $this->eventRepo->all();
        if ($allEvents === []) {
            return;
        }

        $now = new \DateTimeImmutable('now', new \DateTimeZone(date_default_timezone_get() ?: 'Asia/Kolkata'));
        foreach ($allEvents as $event) {
            if (((int) ($event['is_active'] ?? 0)) !== 1) {
                continue;
            }

            if (!$this->isEventExpired($event, $now)) {
                continue;
            }

            $eventId = (string) ($event['event_id'] ?? $event['id'] ?? '');
            if ($eventId !== '') {
                $this->eventRepo->toggleActive($eventId, false);
            }
        }
    }

    private function isEventExpired(array $event, \DateTimeImmutable $now): bool
    {
        $date = trim((string) ($event['end_date'] ?? $event['start_date'] ?? ''));
        if ($date === '') {
            $date = trim((string) ($event['date'] ?? ''));
        }

        if ($date === '') {
            return false;
        }

        $time = trim((string) ($event['end_time'] ?? $event['start_time'] ?? '23:59:59'));
        if ($time === '') {
            $time = '23:59:59';
        }

        $candidate = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $date . ' ' . $time, $now->getTimezone())
            ?: \DateTimeImmutable::createFromFormat('Y-m-d H:i', $date . ' ' . $time, $now->getTimezone());

        if (!$candidate instanceof \DateTimeImmutable) {
            return false;
        }

        return $candidate < $now;
    }

    private function normalizeAttendeeDetails(array $body, int $qty, string $customerName): array
    {
        $rows = [];
        $rawRows = $body['attendeeNames'] ?? $body['attendee_names'] ?? $body['attendee_details'] ?? $body['attendees'] ?? [];

        if (is_array($rawRows)) {
            foreach ($rawRows as $row) {
                $name = '';
                if (is_array($row)) {
                    $name = trim((string) ($row['name'] ?? $row['guestName'] ?? $row['fullName'] ?? ''));
                } else {
                    $name = trim((string) $row);
                }

                if ($name !== '') {
                    $rows[] = ['name' => $name];
                }
            }
        }

        if ($rows === []) {
            $textNames = trim((string) ($body['attendee_names'] ?? $body['attendeeNames'] ?? ''));
            if ($textNames !== '') {
                $parts = preg_split('/[\r\n,]+/', $textNames) ?: [];
                foreach ($parts as $part) {
                    $name = trim((string) $part);
                    if ($name !== '') {
                        $rows[] = ['name' => $name];
                    }
                }
            }
        }

        $normalized = [];
        for ($index = 0; $index < $qty; $index++) {
            $name = trim((string) ($rows[$index]['name'] ?? ''));
            if ($name === '' && $index === 0 && $customerName !== '') {
                $name = $customerName;
            }
            if ($name === '') {
                $name = 'Guest ' . ($index + 1);
            }
            $normalized[] = ['name' => $name];
        }

        return $normalized;
    }

    private function formatEventDateTime(string $date, string $time): string
    {
        $date = trim($date);
        $time = trim($time);

        if ($date === '' && $time === '') {
            return '';
        }

        if ($date !== '' && $time !== '') {
            $timestamp = strtotime($date . ' ' . $time);
            if ($timestamp !== false) {
                return date('d M Y, h:i A', $timestamp);
            }
        }

        if ($date !== '') {
            $dateStamp = strtotime($date);
            if ($dateStamp !== false) {
                return date('d M Y', $dateStamp);
            }
            return $date;
        }

        $timeStamp = strtotime($time);
        if ($timeStamp !== false) {
            return date('h:i A', $timeStamp);
        }
        return $time;
    }

    private function buildQrPayloadFromTransaction(array $transaction, string $paymentId): string
    {
        $tx = (string) ($transaction['transaction_id'] ?? '');
        $eventId = (string) ($transaction['event_id'] ?? '');
        $guestId = 'all';
        $sig = hash_hmac('sha256', $tx . '|' . $eventId . '|' . $paymentId . '|' . $guestId, $this->qrSecret);

        return json_encode([
            'tx' => $tx,
            'eventId' => $eventId,
            'paymentId' => $paymentId,
            'guestId' => $guestId,
            'sig' => $sig,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private function buildQrUrl(string $qrPayload): string
    {
        return '/verify-event-qr?payload=' . rawurlencode(base64_encode($qrPayload));
    }

    private function buildVerificationUrl(string $transactionId): string
    {
        return '/events/verification.html?transactionId=' . rawurlencode($transactionId);
    }

    private function buildStaffCheckinUrl(string $qrPayload): string
    {
        return '/verify-event-qr?payload=' . rawurlencode(base64_encode($qrPayload));
    }

    private function pushCrmEventRegistration(array $transaction): void
    {
        try {
            (new CrmTriggerService())->pushEventRegistration($transaction);
        } catch (\Throwable $exception) {
            // CRM must never block event registration.
        }
    }

    private function pushCrmEventScanEntry(array $transaction, int $admittedCount, int $remaining): void
    {
        try {
            (new CrmTriggerService())->pushEventScanEntry($transaction, $admittedCount, $remaining);
        } catch (\Throwable $exception) {
            // CRM must never block QR check-in.
        }
    }

    private function extractQrPayload(array $body): ?array
    {
        if (isset($body['tx']) || isset($body['transactionId']) || isset($body['transaction_id'])) {
            return [
                'tx' => (string) ($body['tx'] ?? $body['transactionId'] ?? $body['transaction_id'] ?? ''),
                'eventId' => (string) ($body['eventId'] ?? $body['event_id'] ?? ''),
                'paymentId' => (string) ($body['paymentId'] ?? $body['payment_id'] ?? ''),
                'guestId' => (string) ($body['guestId'] ?? $body['guest_id'] ?? 'all'),
                'sig' => (string) ($body['sig'] ?? ''),
            ];
        }

        if (isset($body['tx'], $body['eventId'], $body['paymentId'], $body['guestId'], $body['sig'])) {
            return [
                'tx' => (string) $body['tx'],
                'eventId' => (string) $body['eventId'],
                'paymentId' => (string) $body['paymentId'],
                'guestId' => (string) $body['guestId'],
                'sig' => (string) $body['sig'],
            ];
        }

        $raw = (string) ($body['qr_payload'] ?? $body['guestToken'] ?? '');
        if ($raw === '') {
            return null;
        }

        if (str_starts_with($raw, '{')) {
            $decoded = json_decode($raw, true);
            return is_array($decoded) ? $decoded : null;
        }

        $decodedBase64 = base64_decode($raw, true);
        if ($decodedBase64 !== false && str_starts_with($decodedBase64, '{')) {
            $decoded = json_decode($decodedBase64, true);
            return is_array($decoded) ? $decoded : null;
        }

        return null;
    }

    private function validateEventEntryPasscode(string $input): array
    {
        $normalizedInput = strtoupper(trim($input));
        if ($normalizedInput === '') {
            return [
                'ok' => false,
                'error' => 'PASSCODE_REQUIRED',
                'message' => 'Passcode is required.',
            ];
        }

        $configured = $this->resolveEventEntryPasscode();
        if ($configured === '') {
            return [
                'ok' => false,
                'error' => 'PASSCODE_NOT_CONFIGURED',
                'message' => 'Event entry passcode is not configured.',
            ];
        }

        if (!hash_equals($configured, $normalizedInput)) {
            return [
                'ok' => false,
                'error' => 'INVALID_PASSCODE',
                'message' => 'Invalid passcode.',
            ];
        }

        return ['ok' => true];
    }

    private function resolveEventEntryPasscode(): string
    {
        $settingsFromFile = $this->loadAppSettingsFromJsonFile();
        $filePrimary = strtoupper(trim((string) ($settingsFromFile['eventEntryPasscode'] ?? '')));
        if ($filePrimary !== '') {
            return $filePrimary;
        }

        $fileFallback = strtoupper(trim((string) ($settingsFromFile['menuBlockerStaffCode'] ?? '')));
        if ($fileFallback !== '') {
            return $fileFallback;
        }

        try {
            $settingsRepo = new AppSettingRepository(Database::connection());
            $dbPrimary = strtoupper(trim((string) ($settingsRepo->getValue('app', 'eventEntryPasscode') ?? '')));
            if ($dbPrimary !== '') {
                return $dbPrimary;
            }

            $dbFallback = strtoupper(trim((string) ($settingsRepo->getValue('app', 'menuBlockerStaffCode') ?? '')));
            if ($dbFallback !== '') {
                return $dbFallback;
            }
        } catch (\Throwable $exception) {
            // Ignore DB settings lookup failures and continue to env fallback.
        }

        $envPrimary = strtoupper(trim((string) ($_ENV['EVENT_ENTRY_PASSCODE'] ?? getenv('EVENT_ENTRY_PASSCODE') ?: '')));
        if ($envPrimary !== '') {
            return $envPrimary;
        }

        return strtoupper(trim((string) ($_ENV['ADMIN_PANEL_PASSCODE'] ?? getenv('ADMIN_PANEL_PASSCODE') ?: '')));
    }

    private function loadAppSettingsFromJsonFile(): array
    {
        $path = dirname(__DIR__, 2) . '/config/app-settings.json';
        if (!is_file($path)) {
            return [];
        }

        $raw = @file_get_contents($path);
        if (!is_string($raw) || trim($raw) === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function buildEventEmailContext(array $event): array
    {
        $defaultVenue = 'Rockmount Commercial Hub, 4th Floor, Khadakpada Circle, Kalyan West, Thane, Maharashtra 421301';
        $defaultSupport = '9371519999';

        $venue = trim((string) ($event['venue'] ?? $event['location'] ?? ''));
        if ($venue === '') {
            $venue = $defaultVenue;
        }

        $policyParts = [];
        $cancellation = trim((string) ($event['cancellation_policy'] ?? ''));
        $refund = trim((string) ($event['refund_policy'] ?? ''));
        if ($cancellation !== '') {
            $policyParts[] = 'Cancellation: ' . $cancellation;
        }
        if ($refund !== '') {
            $policyParts[] = 'Refund: ' . $refund;
        }

        $policyText = $policyParts !== []
            ? implode(' | ', $policyParts)
            : (((string) ($event['event_type'] ?? 'free')) === 'paid'
                ? 'Paid registration confirmed.'
                : 'Free entry registration confirmed.');

        $supportPhone = trim((string) ($this->loadAppSettingsFromJsonFile()['hotelWhatsappNo'] ?? ''));
        try {
            $settingsRepo = new AppSettingRepository(Database::connection());
            $dbSupportPhone = trim((string) ($settingsRepo->getValue('app', 'hotelWhatsappNo') ?? ''));
            if ($dbSupportPhone !== '') {
                $supportPhone = $dbSupportPhone;
            }
        } catch (\Throwable $exception) {
            // Keep fallback support number when app settings lookup is unavailable.
        }

        if ($supportPhone === '') {
            $supportPhone = $defaultSupport;
        }

        return [
            'venue' => $venue,
            'supportPhone' => $supportPhone,
            'policyText' => $policyText,
        ];
    }

    private function isPreviewMode(array $body): bool
    {
        $previewRaw = $body['previewOnly'] ?? $body['preview_only'] ?? $body['preview'] ?? null;
        if (is_bool($previewRaw)) {
            return $previewRaw;
        }

        $value = strtolower(trim((string) $previewRaw));
        return in_array($value, ['1', 'true', 'yes', 'preview'], true);
    }

    private function normalizeSelectedGuestNames(array $body): array
    {
        $candidates = $body['selectedGuestNames'] ?? $body['selectedGuestNames[]'] ?? $body['guest_names'] ?? $body['guestNames'] ?? [];
        if (!is_array($candidates)) {
            return [];
        }

        $unique = [];
        foreach ($candidates as $name) {
            $clean = trim((string) $name);
            if ($clean === '') {
                continue;
            }
            $key = strtolower($clean);
            if (isset($unique[$key])) {
                continue;
            }
            $unique[$key] = $clean;
        }

        return array_values($unique);
    }

    private function formatCheckinHistory(array $rows): array
    {
        $history = [];
        foreach ($rows as $row) {
            $guestNamesRaw = $row['guest_names_json'] ?? '[]';
            $guestNames = json_decode((string) $guestNamesRaw, true);
            if (!is_array($guestNames)) {
                $guestNames = [];
            }

            $history[] = [
                'id' => (int) ($row['id'] ?? 0),
                'admittedCount' => (int) ($row['admitted_count'] ?? 0),
                'guestNames' => array_values(array_map(static fn($item): string => trim((string) $item), $guestNames)),
                'verifiedBy' => (string) ($row['verified_by'] ?? ''),
                'source' => (string) ($row['source'] ?? 'qr'),
                'createdAt' => (string) ($row['created_at'] ?? ''),
            ];
        }

        return $history;
    }

    private function calculateRemainingGuestNames(array $transaction, array $history, int $remainingCount): array
    {
        $allNames = $this->extractAttendeeNames($transaction);
        if ($allNames === []) {
            $qty = max(1, (int) ($transaction['qty'] ?? 1));
            for ($i = 1; $i <= $qty; $i++) {
                $allNames[] = 'Guest ' . $i;
            }
        }

        $used = [];
        foreach ($history as $item) {
            foreach ((array) ($item['guestNames'] ?? []) as $guestName) {
                $key = strtolower(trim((string) $guestName));
                if ($key !== '') {
                    $used[$key] = true;
                }
            }
        }

        $remaining = [];
        foreach ($allNames as $name) {
            $normalized = trim((string) $name);
            if ($normalized === '') {
                continue;
            }
            $key = strtolower($normalized);
            if (!isset($used[$key])) {
                $remaining[] = $normalized;
            }
        }

        if (count($remaining) > $remainingCount) {
            $remaining = array_slice($remaining, 0, $remainingCount);
        }

        return $remaining;
    }

    private function extractAttendeeNames(array $transaction): array
    {
        $names = [];

        $attendeeRaw = $transaction['attendee_details'] ?? '[]';
        $attendees = json_decode((string) $attendeeRaw, true);
        if (is_array($attendees)) {
            foreach ($attendees as $row) {
                if (is_array($row)) {
                    $candidate = trim((string) ($row['name'] ?? $row['guestName'] ?? $row['fullName'] ?? ''));
                    if ($candidate !== '') {
                        $names[] = $candidate;
                    }
                } else {
                    $candidate = trim((string) $row);
                    if ($candidate !== '') {
                        $names[] = $candidate;
                    }
                }
            }
        }

        if ($names === []) {
            $passesRaw = $transaction['guest_passes_json'] ?? '[]';
            $passes = json_decode((string) $passesRaw, true);
            if (is_array($passes)) {
                foreach ($passes as $pass) {
                    if (!is_array($pass)) {
                        continue;
                    }
                    $candidate = trim((string) ($pass['name'] ?? $pass['label'] ?? ''));
                    if ($candidate !== '') {
                        $names[] = $candidate;
                    }
                }
            }
        }

        $unique = [];
        foreach ($names as $name) {
            $normalized = trim((string) $name);
            if ($normalized === '') {
                continue;
            }
            $key = strtolower($normalized);
            if (!isset($unique[$key])) {
                $unique[$key] = $normalized;
            }
        }

        return array_values($unique);
    }

    private function buildBookingSummary(array $transaction, int $qty, int $checkedInCount, int $remaining, array $remainingGuestNames): array
    {
        return [
            'eventTitle' => (string) ($transaction['event_title'] ?? ''),
            'primaryGuest' => (string) ($transaction['customer_name'] ?? ''),
            'checkedInCount' => $checkedInCount,
            'remainingCount' => $remaining,
            'transactionId' => (string) ($transaction['transaction_id'] ?? ''),
            'email' => (string) ($transaction['customer_email'] ?? ''),
            'phone' => (string) ($transaction['customer_phone'] ?? ''),
            'status' => (string) ($transaction['status'] ?? ''),
            'quantity' => $qty,
            'remainingGuestNames' => $remainingGuestNames,
        ];
    }

    private function isValidQrSignature(array $payload): bool
    {
        $tx = (string) ($payload['tx'] ?? '');
        $eventId = (string) ($payload['eventId'] ?? '');
        $paymentId = (string) ($payload['paymentId'] ?? '');
        $guestId = (string) ($payload['guestId'] ?? '');
        $sig = (string) ($payload['sig'] ?? '');

        if ($tx === '' || $eventId === '' || $paymentId === '' || $guestId === '' || $sig === '') {
            return false;
        }

        $expected = hash_hmac('sha256', $tx . '|' . $eventId . '|' . $paymentId . '|' . $guestId, $this->qrSecret);
        return hash_equals($expected, $sig);
    }

    private function isSignatureValidationEnabled(): bool
    {
        return ((string) ($_ENV['EVENT_PAYMENT_VALIDATE_SIGNATURE'] ?? '0')) === '1';
    }

    private function validateGatewaySignature(string $orderId, string $paymentId, string $signature): bool
    {
        if ($orderId === '' || $paymentId === '' || $signature === '') {
            return false;
        }

        $secret = (string) ($_ENV['RAZORPAY_KEY_SECRET'] ?? '');
        if ($secret === '') {
            return false;
        }

        $expected = hash_hmac('sha256', $orderId . '|' . $paymentId, $secret);
        return hash_equals($expected, $signature);
    }

    private function buildTransactionSummary(array $transactions): array
    {
        $totalAmount = 0.0;
        $paidAmount = 0.0;
        $totalQty = 0;
        $checkedIn = 0;

        foreach ($transactions as $row) {
            $amount = (float) ($row['amount'] ?? 0);
            $qty = (int) ($row['qty'] ?? 1);
            $status = (string) ($row['status'] ?? '');
            $checkedInCount = (int) ($row['checked_in_count'] ?? 0);

            $totalAmount += $amount;
            $totalQty += $qty;
            $checkedIn += $checkedInCount;

            if (in_array($status, ['paid', 'checked_in', 'free_confirmed', 'checked_in_free'], true)) {
                $paidAmount += $amount;
            }
        }

        return [
            'total_transactions' => count($transactions),
            'total_qty' => $totalQty,
            'total_amount' => round($totalAmount, 2),
            'paid_amount' => round($paidAmount, 2),
            'checked_in_count' => $checkedIn,
        ];
    }

    private function normalizeLegacySeedEvents(): void
    {
        $all = $this->eventRepo->all();

        foreach ($all as $event) {
            $title = strtolower(trim((string) ($event['title'] ?? '')));
            $eventId = (string) ($event['event_id'] ?? $event['id'] ?? '');
            if ($title !== '' && str_contains($title, 'dummy test event')) {
                $this->eventRepo->delete($eventId);
                continue;
            }

            if ($title !== '' && preg_match('/(^|[^a-z])(alpha|beta)([^a-z]|$)/i', $title) === 1) {
                $this->eventRepo->delete($eventId);
                continue;
            }

            if (str_starts_with(strtolower($eventId), 'evt_demo_')) {
                $this->eventRepo->delete((string) ($event['event_id'] ?? $event['id'] ?? ''));
                continue;
            }
        }

        $canonical = $this->seasonalDemoEventSeeds();

        foreach ($canonical as $payload) {
            $existing = $this->eventRepo->findByEventId((string) $payload['event_id']);
            if ($existing) {
                $this->eventRepo->update((string) ($existing['event_id'] ?? $existing['id'] ?? ''), $payload);
                continue;
            }

            $this->eventRepo->create($payload);
        }
    }

    private function seasonalDemoEventSeeds(): array
    {
        $year = (int) (new \DateTimeImmutable('now'))->format('Y');

        return [
            [
                'event_id' => 'evt_live_wok_fire_fest',
                'title' => 'Wok Fire Fest',
                'subtitle' => 'May Nights Special',
                'description' => 'High-heat wok theatrics, chef tasting platters, and live music sets.',
                'venue' => 'Asian Wok & Grill, Gangapur Rd, Nashik',
                'image_url' => 'https://storage.files-vault.com/uploads/1768797343-HN4FZc9djJ.webp',
                'start_date' => sprintf('%04d-05-18', $year),
                'start_time' => '19:00:00',
                'end_date' => sprintf('%04d-05-18', $year),
                'end_time' => '22:30:00',
                'event_type' => 'free',
                'ticket_price' => 0,
                'badge_text' => 'MAY SPECIAL',
                'is_active' => 1,
                'priority' => 120,
                'cta_text' => 'Reserve Seat',
                'currency' => 'INR',
                'popup_enabled' => 0,
                'show_once_per_session' => 1,
                'popup_delay_hours' => 0,
                'popup_cooldown_hours' => 24,
            ],
            [
                'event_id' => 'evt_live_dimsum_brunch_club',
                'title' => 'Dim Sum Brunch Club',
                'subtitle' => 'Sunday Brunch Edition',
                'description' => 'Weekend dim sum baskets, tea pairings, and family combo tasting menu.',
                'venue' => 'Asian Wok & Grill, Gangapur Rd, Nashik',
                'image_url' => 'https://storage.files-vault.com/uploads/1768797431-U8tZph1H4Q.webp',
                'start_date' => sprintf('%04d-05-26', $year),
                'start_time' => '12:30:00',
                'end_date' => sprintf('%04d-05-26', $year),
                'end_time' => '15:30:00',
                'event_type' => 'free',
                'ticket_price' => 0,
                'badge_text' => 'BRUNCH',
                'is_active' => 1,
                'priority' => 115,
                'cta_text' => 'Reserve Seat',
                'currency' => 'INR',
                'popup_enabled' => 0,
                'show_once_per_session' => 1,
                'popup_delay_hours' => 0,
                'popup_cooldown_hours' => 24,
            ],
            [
                'event_id' => 'evt_live_monsoon_mocktail_lab',
                'title' => 'Monsoon Mocktail Lab',
                'subtitle' => 'June Flavor Lab',
                'description' => 'Signature mocktail flights with small-plate pairings and bartender demos.',
                'venue' => 'Asian Wok & Grill, Gangapur Rd, Nashik',
                'image_url' => 'https://storage.files-vault.com/uploads/1768797625-EllydznoIM.JPG',
                'start_date' => sprintf('%04d-06-08', $year),
                'start_time' => '18:30:00',
                'end_date' => sprintf('%04d-06-08', $year),
                'end_time' => '21:30:00',
                'event_type' => 'free',
                'ticket_price' => 0,
                'badge_text' => 'JUNE LIVE',
                'is_active' => 1,
                'priority' => 110,
                'cta_text' => 'Reserve Seat',
                'currency' => 'INR',
                'popup_enabled' => 0,
                'show_once_per_session' => 1,
                'popup_delay_hours' => 0,
                'popup_cooldown_hours' => 24,
            ],
            [
                'event_id' => 'evt_live_sushi_social_evening',
                'title' => 'Sushi Social Evening',
                'subtitle' => 'Chef Showcase Night',
                'description' => 'Curated sushi rolls, chef interaction counter, and plated tasting rounds.',
                'venue' => 'Asian Wok & Grill, Gangapur Rd, Nashik',
                'image_url' => 'https://storage.files-vault.com/uploads/1768797743-s1dqwybMab.webp',
                'start_date' => sprintf('%04d-06-22', $year),
                'start_time' => '20:00:00',
                'end_date' => sprintf('%04d-06-22', $year),
                'end_time' => '23:00:00',
                'event_type' => 'free',
                'ticket_price' => 0,
                'badge_text' => 'LIVE EVENT',
                'is_active' => 1,
                'priority' => 105,
                'cta_text' => 'Reserve Seat',
                'currency' => 'INR',
                'popup_enabled' => 0,
                'show_once_per_session' => 1,
                'popup_delay_hours' => 0,
                'popup_cooldown_hours' => 24,
            ],
        ];
    }

    private function ensureSchema(): void
    {
        $db = Database::connection();

        $db->exec('CREATE TABLE IF NOT EXISTS events (
            id INT AUTO_INCREMENT PRIMARY KEY,
            event_id VARCHAR(64) NOT NULL UNIQUE,
            title VARCHAR(255) NOT NULL,
            subtitle VARCHAR(255) DEFAULT NULL,
            description LONGTEXT DEFAULT NULL,
            venue VARCHAR(255) DEFAULT NULL,
            image_url TEXT DEFAULT NULL,
            video_url TEXT DEFAULT NULL,
            show_video TINYINT(1) NOT NULL DEFAULT 0,
            cta_text VARCHAR(255) DEFAULT NULL,
            cta_url TEXT DEFAULT NULL,
            badge_text VARCHAR(100) DEFAULT NULL,
            start_date DATE NOT NULL,
            start_time TIME NOT NULL,
            end_date DATE DEFAULT NULL,
            end_time TIME DEFAULT NULL,
            time_display_format VARCHAR(16) NOT NULL DEFAULT "12h",
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            priority INT NOT NULL DEFAULT 0,
            popup_enabled TINYINT(1) NOT NULL DEFAULT 0,
            show_once_per_session TINYINT(1) NOT NULL DEFAULT 1,
            popup_delay_hours INT NOT NULL DEFAULT 0,
            popup_cooldown_hours INT NOT NULL DEFAULT 24,
            event_type VARCHAR(16) NOT NULL DEFAULT "free",
            ticket_price DECIMAL(10,2) NOT NULL DEFAULT 0,
            currency VARCHAR(8) NOT NULL DEFAULT "INR",
            max_tickets INT NOT NULL DEFAULT 0,
            payment_enabled TINYINT(1) NOT NULL DEFAULT 0,
            cancellation_policy LONGTEXT DEFAULT NULL,
            refund_policy LONGTEXT DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_is_active (is_active),
            INDEX idx_start_date (start_date),
            INDEX idx_event_type (event_type)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

        $this->ensureColumn('events', 'venue', 'VARCHAR(255) DEFAULT NULL');

        $db->exec('CREATE TABLE IF NOT EXISTS event_otp_verifications (
            id INT AUTO_INCREMENT PRIMARY KEY,
            event_id VARCHAR(64) NOT NULL,
            email VARCHAR(255) NOT NULL,
            customer_name VARCHAR(255) DEFAULT NULL,
            otp_hash VARCHAR(255) NOT NULL,
            attempt_count INT NOT NULL DEFAULT 0,
            otp_requested_at DATETIME NOT NULL,
            resend_allowed_at DATETIME NOT NULL,
            expires_at DATETIME NOT NULL,
            verified_at DATETIME DEFAULT NULL,
            verification_token_hash VARCHAR(255) DEFAULT NULL,
            verification_expires_at DATETIME DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_event_email (event_id, email),
            INDEX idx_expires_at (expires_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

        $db->exec('CREATE TABLE IF NOT EXISTS event_transactions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            transaction_id VARCHAR(64) NOT NULL UNIQUE,
            event_id VARCHAR(64) NOT NULL,
            event_title VARCHAR(255) DEFAULT NULL,
            customer_name VARCHAR(255) NOT NULL,
            customer_email VARCHAR(255) NOT NULL,
            customer_phone VARCHAR(32) NOT NULL,
            qty INT NOT NULL DEFAULT 1,
            amount DECIMAL(10,2) NOT NULL DEFAULT 0,
            currency VARCHAR(8) NOT NULL DEFAULT "INR",
            gateway VARCHAR(64) NOT NULL DEFAULT "free",
            order_id VARCHAR(128) DEFAULT NULL,
            payment_id VARCHAR(128) DEFAULT NULL,
            status VARCHAR(64) NOT NULL DEFAULT "pending",
            qr_url TEXT DEFAULT NULL,
            qr_payload LONGTEXT DEFAULT NULL,
            email_status VARCHAR(32) NOT NULL DEFAULT "pending",
            email_sent_at DATETIME DEFAULT NULL,
            checkin_status VARCHAR(32) NOT NULL DEFAULT "not_checked_in",
            checked_in_at DATETIME DEFAULT NULL,
            checked_in_count INT NOT NULL DEFAULT 0,
            verified_by VARCHAR(255) DEFAULT NULL,
            attendee_details LONGTEXT DEFAULT NULL,
            guest_passes_json LONGTEXT DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            paid_at DATETIME DEFAULT NULL,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_event_id (event_id),
            INDEX idx_order_id (order_id),
            INDEX idx_customer_email (customer_email)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

        $db->exec('CREATE TABLE IF NOT EXISTS event_checkin_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            transaction_id VARCHAR(64) NOT NULL DEFAULT "",
            event_id VARCHAR(64) NOT NULL,
            admitted_count INT NOT NULL DEFAULT 1,
            guest_names_json LONGTEXT DEFAULT NULL,
            verified_by VARCHAR(255) DEFAULT NULL,
            source VARCHAR(64) NOT NULL DEFAULT "qr",
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_event_id (event_id),
            INDEX idx_transaction_id (transaction_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

        $this->ensureColumn('event_checkin_logs', 'transaction_id', 'VARCHAR(64) NOT NULL DEFAULT ""');
        $this->ensureColumn('event_checkin_logs', 'admitted_count', 'INT NOT NULL DEFAULT 1');
        $this->ensureColumn('event_checkin_logs', 'guest_names_json', 'LONGTEXT DEFAULT NULL');
        $this->ensureColumn('event_checkin_logs', 'verified_by', 'VARCHAR(255) DEFAULT NULL');
        $this->ensureColumn('event_checkin_logs', 'source', 'VARCHAR(64) NOT NULL DEFAULT "qr"');

        $db->exec('CREATE TABLE IF NOT EXISTS mail_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            event_id VARCHAR(64) DEFAULT NULL,
            booking_id VARCHAR(64) DEFAULT NULL,
            transaction_id VARCHAR(64) DEFAULT NULL,
            recipient_email VARCHAR(255) NOT NULL,
            template VARCHAR(100) NOT NULL,
            status VARCHAR(32) NOT NULL DEFAULT "pending",
            error_message LONGTEXT DEFAULT NULL,
            payload_json LONGTEXT DEFAULT NULL,
            sent_at DATETIME DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_event_id (event_id),
            INDEX idx_transaction_id (transaction_id),
            INDEX idx_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    }

    private function ensureColumn(string $table, string $column, string $definition): void
    {
        $db = Database::connection();
        $stmt = $db->prepare('SELECT COUNT(*) AS cnt FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
        $stmt->execute([$table, $column]);
        $row = $stmt->fetch();
        if ((int) ($row['cnt'] ?? 0) === 0) {
            $db->exec('ALTER TABLE ' . $table . ' ADD COLUMN ' . $column . ' ' . $definition);
        }
    }
}
