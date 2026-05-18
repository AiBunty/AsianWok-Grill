<?php

declare(strict_types=1);

namespace AWG\Services;

use AWG\Repositories\EventMailLogRepository;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as PHPMailerException;

final class MailerService
{
    // SMTP Configuration - dcoresystems
    private const SMTP_HOST = 'smtp.dcoresystems.com';
    private const SMTP_PORT = 465;
    private const SMTP_USERNAME = 'noreply@dcoresystems.com';
    private const SMTP_PASSWORD = 'Zebra@789';
    private const FROM_EMAIL = 'noreply@dcoresystems.com';
    private const FROM_NAME = 'Asian Wok & Grill';
    private const REPLY_TO = 'asianwokandgrill99@gmail.com';

    private ?object $mailer = null;
    private ?EventMailLogRepository $mailLogRepository;

    public function __construct(?EventMailLogRepository $mailLogRepository = null)
    {
        $this->mailLogRepository = $mailLogRepository;
        if (!class_exists(PHPMailer::class)) {
            return;
        }

        $this->mailer = new PHPMailer(true);
        $this->configureSMTP();
    }

    /**
     * Configure SMTP settings for dcoresystems
     */
    private function configureSMTP(): void
    {
        try {
            $this->mailer->isSMTP();
            $this->mailer->Host = self::SMTP_HOST;
            $this->mailer->Port = self::SMTP_PORT;
            $this->mailer->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS; // SSL/TLS
            $this->mailer->SMTPAuth = true;
            $this->mailer->Username = self::SMTP_USERNAME;
            $this->mailer->Password = self::SMTP_PASSWORD;
            $this->mailer->CharSet = 'UTF-8';
            $this->mailer->isHTML(true);
            $this->mailer->setFrom(self::FROM_EMAIL, self::FROM_NAME);
            $this->mailer->addReplyTo(self::REPLY_TO, self::FROM_NAME);
        } catch (\Throwable $e) {
            throw new \RuntimeException('SMTP configuration failed: ' . $e->getMessage());
        }
    }

    public function sendEventOtp(string $email, string $otp, string $eventTitle, array $context = []): bool
    {
        $subject = 'Your OTP for ' . $eventTitle;
        $body = $this->buildOtpTemplate($otp, $eventTitle, (string) ($context['customer_name'] ?? 'Guest'));
        return $this->sendAndLog($email, $subject, $body, 'event_otp', $context);
    }

    public function sendEventRegistrationConfirmation(string $email, array $context): bool
    {
        $subject = 'Event Booking Confirmation - ' . (string) ($context['event_title'] ?? 'Asian Wok & Grill');
        $body = $this->buildBookingTemplate($context);
        return $this->sendAndLog($email, $subject, $body, 'event_booking_confirmation', $context);
    }

    public function sendEventCheckinNotification(string $email, array $context): bool
    {
        $subject = 'Check-in Confirmed - ' . (string) ($context['event_title'] ?? 'Asian Wok & Grill');
        $body = $this->buildCheckinTemplate($context);
        return $this->sendAndLog($email, $subject, $body, 'event_checkin_confirmation', $context);
    }

    /**
     * Send admin alert for critical events
     */
    public function sendAdminAlert(string $subject, string $message, array $data = []): bool
    {
        try {
            if ($this->mailer === null) {
                error_log('Admin alert send skipped: PHPMailer dependency is not installed.');
                return false;
            }

            $this->mailer->clearAddresses();
            $this->mailer->addAddress(self::REPLY_TO, 'Asian Wok & Grill Admin');
            $this->mailer->Subject = "[ALERT] $subject";
            
            $htmlBody = $this->buildAlertTemplate($subject, $message, $data);
            $this->mailer->Body = $htmlBody;
            $this->mailer->AltBody = strip_tags($message);
            
            return $this->mailer->send();
        } catch (\Throwable $e) {
            error_log('Admin alert send failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Send lead notification email
     */
    public function sendLeadNotification(array $lead): bool
    {
        try {
            if ($this->mailer === null) {
                error_log('Lead notification send skipped: PHPMailer dependency is not installed.');
                return false;
            }

            $this->mailer->clearAddresses();
            $this->mailer->addAddress(self::REPLY_TO, 'Leads Manager');
            $this->mailer->Subject = "New Lead: " . (string) ($lead['name'] ?? 'Unknown');
            
            $htmlBody = $this->buildLeadTemplate($lead);
            $this->mailer->Body = $htmlBody;
            $this->mailer->AltBody = "New lead from {$lead['name']} ({$lead['phone']})";
            
            return $this->mailer->send();
        } catch (\Throwable $e) {
            error_log('Lead notification send failed: ' . $e->getMessage());
            return false;
        }
    }

    private function sendAndLog(string $to, string $subject, string $body, string $template, array $context): bool
    {
        $status = 'failed';
        $error = '';
        $sentAt = null;
        $ok = false;

        try {
            if ($this->mailer !== null) {
                $this->mailer->clearAddresses();
                $this->mailer->addAddress($to);
                $this->mailer->Subject = $subject;
                $this->mailer->Body = $body;
                $this->mailer->AltBody = strip_tags($body);
                $ok = $this->mailer->send();
            } else {
                $ok = $this->sendViaNativeSmtp($to, $subject, $body, strip_tags($body));
            }

            if ($ok) {
                $status = 'sent';
                $sentAt = date('Y-m-d H:i:s');
            } else {
                $error = 'Mail send failed';
            }
        } catch (\Throwable $exception) {
            $error = $exception->getMessage();
            $ok = false;
        }

        if ($this->mailLogRepository instanceof EventMailLogRepository) {
            $this->mailLogRepository->create([
                'event_id' => (string) ($context['event_id'] ?? ''),
                'booking_id' => $context['booking_id'] ?? null,
                'transaction_id' => (string) ($context['transaction_id'] ?? ''),
                'recipient_email' => $to,
                'template' => $template,
                'status' => $status,
                'error_message' => $error,
                'payload_json' => json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                'sent_at' => $sentAt,
            ]);
        }

        return $ok;
    }

    private function sendViaNativeSmtp(string $to, string $subject, string $htmlBody, string $altBody = ''): bool
    {
        $socket = @stream_socket_client(
            'ssl://' . self::SMTP_HOST . ':' . self::SMTP_PORT,
            $errno,
            $errstr,
            25,
            STREAM_CLIENT_CONNECT
        );

        if (!is_resource($socket)) {
            throw new \RuntimeException('SMTP connection failed: ' . $errstr . ' (' . $errno . ')');
        }

        stream_set_timeout($socket, 25);

        try {
            $this->smtpExpect($socket, [220]);
            $this->smtpWrite($socket, 'EHLO asianwokandgrill.in');
            $this->smtpExpect($socket, [250]);

            $this->smtpWrite($socket, 'AUTH LOGIN');
            $this->smtpExpect($socket, [334]);

            $this->smtpWrite($socket, base64_encode(self::SMTP_USERNAME));
            $this->smtpExpect($socket, [334]);

            $this->smtpWrite($socket, base64_encode(self::SMTP_PASSWORD));
            $this->smtpExpect($socket, [235]);

            $this->smtpWrite($socket, 'MAIL FROM:<' . self::FROM_EMAIL . '>');
            $this->smtpExpect($socket, [250]);

            $this->smtpWrite($socket, 'RCPT TO:<' . $to . '>');
            $this->smtpExpect($socket, [250, 251]);

            $this->smtpWrite($socket, 'DATA');
            $this->smtpExpect($socket, [354]);

            $boundary = 'awg_' . bin2hex(random_bytes(6));
            $headers = [
                'Date: ' . date(DATE_RFC2822),
                'From: ' . self::FROM_NAME . ' <' . self::FROM_EMAIL . '>',
                'Reply-To: ' . self::REPLY_TO,
                'To: <' . $to . '>',
                'Subject: =?UTF-8?B?' . base64_encode($subject) . '?=',
                'MIME-Version: 1.0',
                'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
            ];

            $plain = $altBody !== '' ? $altBody : strip_tags($htmlBody);
            $message = implode("\r\n", $headers)
                . "\r\n\r\n"
                . '--' . $boundary . "\r\n"
                . "Content-Type: text/plain; charset=UTF-8\r\n"
                . "Content-Transfer-Encoding: 8bit\r\n\r\n"
                . $plain . "\r\n"
                . '--' . $boundary . "\r\n"
                . "Content-Type: text/html; charset=UTF-8\r\n"
                . "Content-Transfer-Encoding: 8bit\r\n\r\n"
                . $htmlBody . "\r\n"
                . '--' . $boundary . "--\r\n";

            $escaped = preg_replace('/(^|\r\n)\./', '$1..', $message) ?? $message;
            fwrite($socket, $escaped . "\r\n.\r\n");
            $this->smtpExpect($socket, [250]);

            $this->smtpWrite($socket, 'QUIT');
            $this->smtpExpect($socket, [221]);

            return true;
        } finally {
            fclose($socket);
        }
    }

    private function smtpWrite($socket, string $line): void
    {
        fwrite($socket, $line . "\r\n");
    }

    private function smtpExpect($socket, array $allowedCodes): void
    {
        $buffer = '';
        $code = null;

        while (!feof($socket)) {
            $line = fgets($socket);
            if ($line === false) {
                break;
            }

            $buffer .= $line;
            if (preg_match('/^(\d{3})([\s-])/', $line, $matches) !== 1) {
                continue;
            }

            $code = (int) $matches[1];
            if ($matches[2] === ' ') {
                break;
            }
        }

        if ($code === null || !in_array($code, $allowedCodes, true)) {
            throw new \RuntimeException('SMTP unexpected response: ' . trim($buffer));
        }
    }

    private function buildOtpTemplate(string $otp, string $eventTitle, string $customerName): string
    {
        $safeName = htmlspecialchars($customerName, ENT_QUOTES, 'UTF-8');
        $safeEvent = htmlspecialchars($eventTitle, ENT_QUOTES, 'UTF-8');
        $safeOtp = htmlspecialchars($otp, ENT_QUOTES, 'UTF-8');

        return '<h2>Event OTP Verification</h2>'
            . '<p>Hello ' . $safeName . ',</p>'
            . '<p>Your OTP for <strong>' . $safeEvent . '</strong> is:</p>'
            . '<p style="font-size: 24px; letter-spacing: 4px;"><strong>' . $safeOtp . '</strong></p>'
            . '<p>This OTP is valid for 10 minutes.</p>';
    }

    private function buildBookingTemplate(array $context): string
    {
        // Extract all context fields
        $eventTitle = htmlspecialchars((string) ($context['event_title'] ?? ''), ENT_QUOTES, 'UTF-8');
        $eventSubtitle = htmlspecialchars((string) ($context['event_subtitle'] ?? ''), ENT_QUOTES, 'UTF-8');
        $customerName = htmlspecialchars((string) ($context['customer_name'] ?? ''), ENT_QUOTES, 'UTF-8');
        $transactionIdRaw = (string) ($context['transactionId'] ?? $context['transaction_id'] ?? '');
        $transactionId = htmlspecialchars($transactionIdRaw, ENT_QUOTES, 'UTF-8');
        $qty = (int) ($context['qty'] ?? 1);
        $amount = (string) ($context['amount'] ?? '0');
        $currency = (string) ($context['currency'] ?? 'USD');
        $createdAt = (string) ($context['createdAt'] ?? $context['created_at'] ?? date('Y-m-d H:i:s'));
        $paidAt = (string) ($context['paidAt'] ?? $context['paid_at'] ?? '');
        $eventStartAt = (string) ($context['eventStartAt'] ?? $context['event_start_at'] ?? '');
        $eventEndAt = (string) ($context['eventEndAt'] ?? $context['event_end_at'] ?? '');
        $attendeeNames = $context['attendeeNames'] ?? $context['attendee_names'] ?? [];
        $isFreeRegistration = (bool) ($context['isFreeRegistration'] ?? $context['is_free_registration'] ?? false);
        $verificationUrlRaw = (string) ($context['verificationUrl'] ?? '/events/verification.html?transactionId=' . rawurlencode($transactionIdRaw));
        $verificationUrl = htmlspecialchars($verificationUrlRaw, ENT_QUOTES, 'UTF-8');
        $qrUrlRaw = (string) ($context['qrUrl'] ?? $context['qr_url'] ?? '');
        
        // Generate QR code image URL using Google Charts API
        $qrImageUrl = '';
        if ($qrUrlRaw !== '') {
            $qrImageUrl = 'https://chart.googleapis.com/chart?chs=200x200&chld=M|0&cht=qr&chl=' . urlencode($qrUrlRaw);
        }

        // Build attendee list HTML if present
        $attendeesHtml = '';
        if (!empty($attendeeNames) && count($attendeeNames) > 1) {
            $attendeesHtml = '<div class="attendees-list">';
            $attendeesHtml .= '<strong>👥 Attendees (' . count($attendeeNames) . '):</strong>';
            $attendeesHtml .= '<ul>';
            foreach ($attendeeNames as $name) {
                $attendeesHtml .= '<li>' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '</li>';
            }
            $attendeesHtml .= '</ul></div>';
        }

        // Build event timing section if available
        $timingHtml = '';
        if ($eventStartAt !== '') {
            $timingHtml = '<div class="detail-item">';
            $timingHtml .= '<span class="detail-label">Event Date & Time:</span>';
            $timingHtml .= '<span class="detail-value">' . htmlspecialchars($eventStartAt, ENT_QUOTES, 'UTF-8');
            if ($eventEndAt !== '') {
                $timingHtml .= ' – ' . htmlspecialchars($eventEndAt, ENT_QUOTES, 'UTF-8');
            }
            $timingHtml .= '</span></div>';
        }

        // Build amount section if not free
        $amountHtml = '';
        if (!$isFreeRegistration && $amount !== '0') {
            $amountHtml = '<div class="detail-item">';
            $amountHtml .= '<span class="detail-label">Amount:</span>';
            $amountHtml .= '<span class="detail-value">' . htmlspecialchars($currency, ENT_QUOTES, 'UTF-8') . ' ' . htmlspecialchars($amount, ENT_QUOTES, 'UTF-8') . '</span>';
            $amountHtml .= '</div>';
        }

        // Build the branded HTML email
        return <<<'HTML'
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        body {
            font-family: Arial, sans-serif;
            line-height: 1.6;
            color: #333;
            background-color: #f4f4f4;
            margin: 0;
            padding: 0;
        }
        .email-container {
            max-width: 600px;
            margin: 20px auto;
            background-color: #ffffff;
            border: 1px solid #ddd;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        }
        .email-header {
            background: linear-gradient(135deg, #8B4513 0%, #D2691E 100%);
            color: #fff;
            padding: 40px 20px;
            text-align: center;
        }
        .email-header h1 {
            margin: 0;
            font-size: 28px;
            font-weight: bold;
        }
        .email-header p {
            margin: 5px 0 0 0;
            font-size: 14px;
            opacity: 0.9;
        }
        .email-body {
            padding: 30px 20px;
        }
        .greeting {
            font-size: 16px;
            margin-bottom: 20px;
            color: #333;
        }
        .confirmation-badge {
            background-color: #d4edda;
            border-left: 4px solid #28a745;
            padding: 15px;
            margin-bottom: 20px;
            border-radius: 4px;
        }
        .confirmation-badge strong {
            color: #155724;
        }
        .details-section {
            background-color: #f9f9f9;
            padding: 20px;
            border-radius: 6px;
            margin-bottom: 20px;
        }
        .detail-item {
            display: flex;
            justify-content: space-between;
            margin-bottom: 12px;
            border-bottom: 1px solid #e0e0e0;
            padding-bottom: 8px;
        }
        .detail-item:last-child {
            border-bottom: none;
            margin-bottom: 0;
            padding-bottom: 0;
        }
        .detail-label {
            font-weight: bold;
            color: #555;
            width: 40%;
        }
        .detail-value {
            color: #333;
            word-break: break-word;
        }
        .qr-section {
            text-align: center;
            margin: 30px 0;
            padding: 20px;
            background-color: #fafafa;
            border-radius: 6px;
        }
        .qr-section h3 {
            margin-top: 0;
            color: #8B4513;
            font-size: 14px;
        }
        .qr-image {
            margin: 15px 0;
        }
        .qr-image img {
            width: 200px;
            height: 200px;
            border: 2px solid #D2691E;
            border-radius: 4px;
        }
        .cta-button {
            display: inline-block;
            background-color: #D2691E;
            color: #fff;
            padding: 12px 30px;
            text-decoration: none;
            border-radius: 4px;
            font-weight: bold;
            margin: 20px 0;
            transition: background-color 0.3s;
        }
        .cta-button:hover {
            background-color: #8B4513;
        }
        .instructions {
            background-color: #fff3cd;
            border-left: 4px solid #ffc107;
            padding: 15px;
            margin: 20px 0;
            border-radius: 4px;
            font-size: 14px;
        }
        .footer-section {
            background-color: #f9f9f9;
            padding: 20px;
            text-align: center;
            border-top: 1px solid #ddd;
            font-size: 12px;
            color: #666;
        }
        .footer-section a {
            color: #D2691E;
            text-decoration: none;
        }
        .divider {
            height: 1px;
            background-color: #ddd;
            margin: 20px 0;
        }
    </style>
</head>
<body>
    <div class="email-container">
        <!-- Header -->
        <div class="email-header">
            <h1>🎉 Booking Confirmed!</h1>
            <p>Asian Wok & Grill Event Reservation</p>
        </div>

        <!-- Body -->
        <div class="email-body">
            <!-- Greeting -->
            <div class="greeting">
                <p>Hello <strong>
HTML;
        
        $html = $this->buildBookingTemplate(array_merge($context, ['qrImageUrl' => $qrImageUrl]));
        return substr($html, 0, -1); // Remove last char since we're building incrementally
    }
    
    private function buildBookingTemplateHTML(string $customerName, string $eventTitle, string $transactionId, int $qty, string $verificationUrl, string $qrImageUrl): string
    {
        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        body {
            font-family: Arial, sans-serif;
            line-height: 1.6;
            color: #333;
            background-color: #f4f4f4;
            margin: 0;
            padding: 0;
        }
        .email-container {
            max-width: 600px;
            margin: 20px auto;
            background-color: #ffffff;
            border: 1px solid #ddd;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        }
        .email-header {
            background: linear-gradient(135deg, #8B4513 0%, #D2691E 100%);
            color: #fff;
            padding: 40px 20px;
            text-align: center;
        }
        .email-header h1 {
            margin: 0;
            font-size: 28px;
            font-weight: bold;
        }
        .email-header p {
            margin: 5px 0 0 0;
            font-size: 14px;
            opacity: 0.9;
        }
        .email-body {
            padding: 30px 20px;
        }
        .greeting {
            font-size: 16px;
            margin-bottom: 20px;
            color: #333;
        }
        .confirmation-badge {
            background-color: #d4edda;
            border-left: 4px solid #28a745;
            padding: 15px;
            margin-bottom: 20px;
            border-radius: 4px;
        }
        .confirmation-badge strong {
            color: #155724;
        }
        .details-section {
            background-color: #f9f9f9;
            padding: 20px;
            border-radius: 6px;
            margin-bottom: 20px;
        }
        .detail-item {
            display: flex;
            justify-content: space-between;
            margin-bottom: 12px;
            border-bottom: 1px solid #e0e0e0;
            padding-bottom: 8px;
        }
        .detail-item:last-child {
            border-bottom: none;
            margin-bottom: 0;
            padding-bottom: 0;
        }
        .detail-label {
            font-weight: bold;
            color: #555;
            width: 40%;
        }
        .detail-value {
            color: #333;
            word-break: break-word;
        }
        .qr-section {
            text-align: center;
            margin: 30px 0;
            padding: 20px;
            background-color: #fafafa;
            border-radius: 6px;
        }
        .qr-section h3 {
            margin-top: 0;
            color: #8B4513;
            font-size: 14px;
        }
        .qr-image {
            margin: 15px 0;
        }
        .qr-image img {
            width: 200px;
            height: 200px;
            border: 2px solid #D2691E;
            border-radius: 4px;
        }
        .cta-button {
            display: inline-block;
            background-color: #D2691E;
            color: #fff;
            padding: 12px 30px;
            text-decoration: none;
            border-radius: 4px;
            font-weight: bold;
            margin: 20px 0;
            transition: background-color 0.3s;
        }
        .cta-button:hover {
            background-color: #8B4513;
        }
        .instructions {
            background-color: #fff3cd;
            border-left: 4px solid #ffc107;
            padding: 15px;
            margin: 20px 0;
            border-radius: 4px;
            font-size: 14px;
        }
        .footer-section {
            background-color: #f9f9f9;
            padding: 20px;
            text-align: center;
            border-top: 1px solid #ddd;
            font-size: 12px;
            color: #666;
        }
        .footer-section a {
            color: #D2691E;
            text-decoration: none;
        }
        .divider {
            height: 1px;
            background-color: #ddd;
            margin: 20px 0;
        }
    </style>
</head>
<body>
    <div class="email-container">
        <!-- Header -->
        <div class="email-header">
            <h1>🎉 Booking Confirmed!</h1>
            <p>Asian Wok & Grill Event Reservation</p>
        </div>

        <!-- Body -->
        <div class="email-body">
            <!-- Greeting -->
            <div class="greeting">
                <p>Hello <strong>{$customerName}</strong>,</p>
                <p>Your reservation has been successfully confirmed! We're excited to have you at our event.</p>
            </div>

            <!-- Confirmation Badge -->
            <div class="confirmation-badge">
                <strong>✓ Your booking is confirmed and paid.</strong>
            </div>

            <!-- Event Details -->
            <div class="details-section">
                <div class="detail-item">
                    <span class="detail-label">Event:</span>
                    <span class="detail-value">{$eventTitle}</span>
                </div>
                <div class="detail-item">
                    <span class="detail-label">Reservation ID:</span>
                    <span class="detail-value">{$transactionId}</span>
                </div>
                <div class="detail-item">
                    <span class="detail-label">Number of Guests:</span>
                    <span class="detail-value">{$qty}</span>
                </div>
            </div>

            <!-- QR Code Section -->
            <div class="qr-section">
                <h3>Your Event Check-in QR Code</h3>
                <p style="margin: 0 0 15px 0; font-size: 13px; color: #666;">
                    Please present this QR code at check-in. You can take a screenshot or show this email on your phone.
                </p>
                <div class="qr-image">
                    <img src="{$qrImageUrl}" alt="Event Check-in QR Code" />
                </div>
            </div>

            <!-- Important Instructions -->
            <div class="instructions">
                <strong>📍 Important:</strong>
                <ul style="margin: 10px 0;">
                    <li>Please bring this email or a screenshot of the QR code to the event</li>
                    <li>The QR code can be used to check in on the day of the event</li>
                    <li>If you have any questions, please reply to this email</li>
                </ul>
            </div>

            <!-- Call to Action -->
            <div style="text-align: center;">
                <a href="{$verificationUrl}" class="cta-button">Verify Your Reservation</a>
            </div>

            <div class="divider"></div>

            <!-- Additional Info -->
            <p style="font-size: 14px; color: #666; text-align: center;">
                <strong>Confirmation Details:</strong><br>
                Transaction ID: {$transactionId}<br>
                Guests: {$qty} person(s)
            </p>
        </div>

        <!-- Footer -->
        <div class="footer-section">
            <p style="margin: 0 0 10px 0;">
                <strong>Asian Wok & Grill</strong><br>
                Premium Dining & Events
            </p>
            <p style="margin: 0;">
                Questions? Reply to this email or visit <a href="https://asianwokandgrill.in">asianwokandgrill.in</a>
            </p>
            <p style="margin: 10px 0 0 0; font-size: 11px; color: #999;">
                This is an automated email. Please do not reply with sensitive information.
            </p>
        </div>
    </div>
</body>
</html>
HTML;
    }

    private function buildCheckinTemplate(array $context): string
    {
        $eventTitle = htmlspecialchars((string) ($context['event_title'] ?? ''), ENT_QUOTES, 'UTF-8');
        $admitted = (int) ($context['admitted_count'] ?? 1);
        $remaining = (int) ($context['remaining'] ?? 0);

        return '<h2>Check-in Successful</h2>'
            . '<p><strong>Event:</strong> ' . $eventTitle . '</p>'
            . '<p><strong>Admitted this scan:</strong> ' . $admitted . '</p>'
            . '<p><strong>Remaining passes:</strong> ' . $remaining . '</p>';
    }

    private function buildAlertTemplate(string $subject, string $message, array $data): string
    {
        $html = '<h1 style="color: #f0c48f;">⚠️ ' . htmlspecialchars($subject) . '</h1>';
        $html .= '<p>' . htmlspecialchars($message) . '</p>';
        
        if (!empty($data)) {
            $html .= '<table style="border-collapse: collapse; margin-top: 16px;"><tbody>';
            foreach ($data as $key => $value) {
                $safeKey = htmlspecialchars($key);
                $safeValue = htmlspecialchars(is_array($value) ? json_encode($value) : $value);
                $html .= '<tr style="border-bottom: 1px solid #ddd;"><th style="text-align: left; padding: 8px; background: #f5f5f5;">' . $safeKey . '</th><td style="padding: 8px;">' . $safeValue . '</td></tr>';
            }
            $html .= '</tbody></table>';
        }

        $html .= '<p style="margin-top: 20px; font-size: 12px; color: #999;">Time: ' . date('Y-m-d H:i:s') . '</p>';
        return $html;
    }

    private function buildLeadTemplate(array $lead): string
    {
        $name = htmlspecialchars($lead['name'] ?? 'Unknown');
        $phone = htmlspecialchars($lead['phone'] ?? '—');
        $email = htmlspecialchars($lead['email'] ?? '—');
        $source = htmlspecialchars($lead['source'] ?? 'Website');
        $createdAt = date('Y-m-d H:i:s');

        return '<h2 style="color: #f0c48f;">📋 New Lead Received</h2>'
            . '<table style="border-collapse: collapse; margin-top: 16px; width: 100%;"><tbody>'
            . '<tr style="border-bottom: 1px solid #ddd;"><th style="text-align: left; padding: 8px; background: #f5f5f5; font-weight: bold;">Name</th><td style="padding: 8px;">' . $name . '</td></tr>'
            . '<tr style="border-bottom: 1px solid #ddd;"><th style="text-align: left; padding: 8px; background: #f5f5f5; font-weight: bold;">Phone</th><td style="padding: 8px;">' . $phone . '</td></tr>'
            . '<tr style="border-bottom: 1px solid #ddd;"><th style="text-align: left; padding: 8px; background: #f5f5f5; font-weight: bold;">Email</th><td style="padding: 8px;">' . $email . '</td></tr>'
            . '<tr style="border-bottom: 1px solid #ddd;"><th style="text-align: left; padding: 8px; background: #f5f5f5; font-weight: bold;">Source</th><td style="padding: 8px;">' . $source . '</td></tr>'
            . '<tr><th style="text-align: left; padding: 8px; background: #f5f5f5; font-weight: bold;">Received</th><td style="padding: 8px;">' . $createdAt . '</td></tr>'
            . '</tbody></table>'
            . '<p style="margin-top: 20px;"><a href="https://asianwokandgrill.in/admin/" style="background: #f0c48f; color: #1b1111; padding: 10px 20px; text-decoration: none; border-radius: 4px; display: inline-block;">View in Admin Panel</a></p>';
    }
}
