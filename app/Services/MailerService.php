<?php

declare(strict_types=1);

namespace AWG\Services;

use AWG\Config\Env;
use AWG\Repositories\EventMailLogRepository;

final class MailerService
{
    private const DEFAULT_SMTP_HOST = 'smtp.dcoresystems.com';
    private const DEFAULT_SMTP_PORT = 465;
    private const DEFAULT_SMTP_USERNAME = 'noreply@dcoresystems.com';
    private const DEFAULT_SMTP_PASSWORD = '';
    private const DEFAULT_FROM_EMAIL = 'noreply@dcoresystems.com';
    private const DEFAULT_FROM_NAME = 'Asian Wok & Grill';
    private const DEFAULT_REPLY_TO = 'asianwokandgrill99@gmail.com';
    private const DEFAULT_SMTP_SECURE = 'ssl';

    private ?EventMailLogRepository $mailLogRepository;
    private ?string $lastError = null;

    public function __construct(?EventMailLogRepository $mailLogRepository = null)
    {
        $this->mailLogRepository = $mailLogRepository;
    }

    public function sendEventOtp(string $email, string $otp, string $eventTitle, array $context = []): bool
    {
        $subject = 'Your OTP for ' . $eventTitle;
        $guestName = (string) ($context['customerName'] ?? $context['customer_name'] ?? 'Guest');
        $supportPhone = (string) ($context['supportPhone'] ?? $context['support_phone'] ?? '9371519999');
        $body = $this->buildOtpTemplate($otp, $eventTitle, $guestName, $supportPhone);
        $plain = $this->buildOtpPlainText($otp, $eventTitle, $guestName, $supportPhone);
        return $this->sendAndLog($email, $subject, $body, 'event_otp', $context, $plain);
    }

    public function sendEventRegistrationConfirmation(string $email, array $context): bool
    {
        $isFreeRegistration = (bool) ($context['isFreeRegistration'] ?? $context['is_free_registration'] ?? false);
        $eventTitle = (string) ($context['event_title'] ?? 'Asian Wok & Grill');
        
        if ($isFreeRegistration) {
            $subject = 'Your Free Event Pass - ' . $eventTitle;
        } else {
            $subject = 'Your Event Pass - ' . $eventTitle;
        }
        
        $body = $this->buildBookingTemplate($context);
        return $this->sendAndLog($email, $subject, $body, 'event_booking_confirmation', $context);
    }

    public function sendEventCheckinNotification(string $email, array $context): bool
    {
        $subject = 'Check-in Confirmed - ' . (string) ($context['event_title'] ?? 'Asian Wok & Grill');
        $body = $this->buildCheckinTemplate($context);
        return $this->sendAndLog($email, $subject, $body, 'event_checkin_confirmation', $context);
    }

    public function sendAdminAlert(string $subject, string $message, array $data = []): bool
    {
        try {
            $htmlBody = $this->buildAlertTemplate($subject, $message, $data);
            $config = $this->smtpConfig();
            return $this->sendViaNativeSmtp((string) $config['replyTo'], "[ALERT] $subject", $htmlBody, strip_tags($message));
        } catch (\Throwable $e) {
            error_log('Admin alert send failed: ' . $e->getMessage());
            return false;
        }
    }

    public function sendLeadNotification(array $lead): bool
    {
        try {
            $htmlBody = $this->buildLeadTemplate($lead);
            $subject = "New Lead: " . (string) ($lead['name'] ?? 'Unknown');
            $config = $this->smtpConfig();
            return $this->sendViaNativeSmtp((string) $config['replyTo'], $subject, $htmlBody, "New lead from {$lead['name']} ({$lead['phone']})");
        } catch (\Throwable $e) {
            error_log('Lead notification send failed: ' . $e->getMessage());
            return false;
        }
    }

    public function getLastError(): ?string
    {
        return $this->lastError;
    }

    private function sendAndLog(string $to, string $subject, string $body, string $template, array $context, string $plainText = ''): bool
    {
        $status = 'failed';
        $error = '';
        $sentAt = null;
        $ok = false;
        $this->lastError = null;

        try {
            $ok = $this->sendViaNativeSmtp($to, $subject, $body, $plainText !== '' ? $plainText : strip_tags($body));
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

        if ($error !== '') {
            $this->lastError = $error;
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
        $config = $this->smtpConfig();
        if (!$this->hasSmtpCredentials($config)) {
            throw new \RuntimeException('SMTP credentials are not configured.');
        }

        $secureMode = strtolower((string) $config['secure']);
        $transportPrefix = $secureMode === 'none' ? '' : ($secureMode . '://');

        $socket = @stream_socket_client(
            $transportPrefix . (string) $config['host'] . ':' . (int) $config['port'],
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

            $this->smtpWrite($socket, base64_encode((string) $config['username']));
            $this->smtpExpect($socket, [334]);

            $this->smtpWrite($socket, base64_encode((string) $config['password']));
            $this->smtpExpect($socket, [235]);

            $this->smtpWrite($socket, 'MAIL FROM:<' . (string) $config['fromEmail'] . '>');
            $this->smtpExpect($socket, [250]);

            $this->smtpWrite($socket, 'RCPT TO:<' . $to . '>');
            $this->smtpExpect($socket, [250, 251]);

            $this->smtpWrite($socket, 'DATA');
            $this->smtpExpect($socket, [354]);

            $boundary = 'awg_' . bin2hex(random_bytes(6));
            $headers = [
                'Date: ' . date(DATE_RFC2822),
                'From: ' . (string) $config['fromName'] . ' <' . (string) $config['fromEmail'] . '>',
                'Reply-To: ' . (string) $config['replyTo'],
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

    private function smtpConfig(): array
    {
        $portValue = (string) Env::getProfiled('SMTP_PORT', (string) self::DEFAULT_SMTP_PORT);
        $port = (int) $portValue;
        if ($port <= 0) {
            $port = self::DEFAULT_SMTP_PORT;
        }

        $username = trim((string) Env::getProfiled('SMTP_USERNAME', ''));
        if ($username == '') {
            $username = trim((string) Env::getProfiled('SMTP_USER', self::DEFAULT_SMTP_USERNAME));
        }

        $password = trim((string) Env::getProfiled('SMTP_PASSWORD', ''));
        if ($password == '') {
            $password = trim((string) Env::getProfiled('SMTP_PASS', self::DEFAULT_SMTP_PASSWORD));
        }

        return [
            'host' => trim((string) Env::getProfiled('SMTP_HOST', self::DEFAULT_SMTP_HOST)),
            'port' => $port,
            'username' => $username,
            'password' => $password,
            'fromEmail' => trim((string) Env::getProfiled('SMTP_FROM_EMAIL', self::DEFAULT_FROM_EMAIL)),
            'fromName' => trim((string) Env::getProfiled('SMTP_FROM_NAME', self::DEFAULT_FROM_NAME)),
            'replyTo' => trim((string) Env::getProfiled('SMTP_REPLY_TO', self::DEFAULT_REPLY_TO)),
            'secure' => trim((string) Env::getProfiled('SMTP_SECURE', self::DEFAULT_SMTP_SECURE)),
        ];
    }

    private function hasSmtpCredentials(array $config): bool
    {
        return (string) ($config['host'] ?? '') !== ''
            && (string) ($config['username'] ?? '') !== ''
            && (string) ($config['password'] ?? '') !== ''
            && (string) ($config['fromEmail'] ?? '') !== '';
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

    private function buildOtpTemplate(string $otp, string $eventTitle, string $customerName, string $supportPhone): string
    {
        $safeName = htmlspecialchars($customerName, ENT_QUOTES, 'UTF-8');
        $safeEvent = htmlspecialchars($eventTitle, ENT_QUOTES, 'UTF-8');
        $safeOtp = htmlspecialchars($otp, ENT_QUOTES, 'UTF-8');
        $safeSupport = htmlspecialchars($supportPhone, ENT_QUOTES, 'UTF-8');
        $logoUrl = htmlspecialchars($this->absoluteUrl('/assets/images/logo.svg'), ENT_QUOTES, 'UTF-8');

        return <<<HTML
<!doctype html>
<html>
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <title>Email Verification OTP</title>
</head>
<body style="margin:0;padding:24px;background:#0f0a0d;font-family:Segoe UI,Arial,sans-serif;color:#f7f2eb;">
  <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:620px;margin:0 auto;border:1px solid #3b2527;border-radius:18px;overflow:hidden;background:#181014;">
    <tr>
      <td style="padding:24px;background:linear-gradient(135deg,#320206,#171015 72%,#1d1217);border-bottom:1px solid #53322f;">
        <img src="{$logoUrl}" alt="Asian Wok &amp; Grill" style="display:block;height:52px;width:auto;max-width:240px;" />
      </td>
    </tr>
    <tr>
      <td style="padding:28px 24px;">
        <p style="margin:0 0 12px 0;font-size:16px;color:#f7f2eb;">Hello <strong>{$safeName}</strong>,</p>
        <p style="margin:0 0 18px 0;font-size:15px;line-height:1.7;color:#eadfd3;">
          Use the OTP below to verify your email address before we continue with your registration for
          <strong style="color:#f2c48a;">{$safeEvent}</strong>.
        </p>

        <div style="margin:0 0 18px 0;padding:20px;border-radius:16px;background:#241418;border:1px solid #6b4836;text-align:center;">
          <div style="font-size:12px;letter-spacing:1.5px;text-transform:uppercase;color:#c8aa8a;margin-bottom:8px;">Your OTP</div>
          <div style="font-size:34px;font-weight:700;letter-spacing:10px;color:#ffffff;">{$safeOtp}</div>
          <div style="margin-top:10px;font-size:13px;color:#eadfd3;">This OTP expires in 10 minutes.</div>
        </div>

        <p style="margin:0;font-size:13px;line-height:1.7;color:#c8b9ab;">
          If you did not request this verification, you can ignore this email. For help, contact us at {$safeSupport}.
        </p>
      </td>
    </tr>
  </table>
</body>
</html>
HTML;
    }

    private function buildOtpPlainText(string $otp, string $eventTitle, string $customerName, string $supportPhone): string
    {
        return "Hello {$customerName},\n\n"
            . "Use this OTP to verify your email for {$eventTitle}:\n"
            . "{$otp}\n\n"
            . "This OTP expires in 10 minutes.\n"
            . "Support: {$supportPhone}";
    }

    private function buildBookingTemplate(array $context): string
    {
        $eventTitle = htmlspecialchars((string) ($context['event_title'] ?? 'Asian Wok & Grill Event'), ENT_QUOTES, 'UTF-8');
        $customerName = htmlspecialchars((string) ($context['customer_name'] ?? 'Guest'), ENT_QUOTES, 'UTF-8');
        $transactionIdRaw = (string) ($context['transactionId'] ?? $context['transaction_id'] ?? '');
        $transactionId = htmlspecialchars($transactionIdRaw, ENT_QUOTES, 'UTF-8');
        $qty = max(1, (int) ($context['qty'] ?? 1));
        $amount = trim((string) ($context['amount'] ?? '0'));
        $currency = strtoupper(trim((string) ($context['currency'] ?? 'INR')));
        $createdAt = htmlspecialchars((string) ($context['createdAt'] ?? $context['created_at'] ?? date('d M Y, h:i A')), ENT_QUOTES, 'UTF-8');
        $eventStartAt = htmlspecialchars((string) ($context['eventStartAt'] ?? $context['event_start_at'] ?? ''), ENT_QUOTES, 'UTF-8');
        $eventEndAt = htmlspecialchars((string) ($context['eventEndAt'] ?? $context['event_end_at'] ?? ''), ENT_QUOTES, 'UTF-8');
        $eventVenue = htmlspecialchars((string) ($context['eventVenue'] ?? $context['event_venue'] ?? 'Rockmount Commercial Hub, 4th Floor, Khadakpada Circle, Kalyan West, Thane, Maharashtra 421301'), ENT_QUOTES, 'UTF-8');
        $supportPhone = htmlspecialchars((string) ($context['supportPhone'] ?? $context['support_phone'] ?? '9371519999'), ENT_QUOTES, 'UTF-8');
        $policyText = trim((string) ($context['policyText'] ?? $context['policy_text'] ?? ''));
        $policyTextEscaped = htmlspecialchars($policyText !== '' ? $policyText : 'Free entry registration confirmed.', ENT_QUOTES, 'UTF-8');
        $isFreeRegistration = (bool) ($context['isFreeRegistration'] ?? $context['is_free_registration'] ?? false);
        $verificationUrlRaw = (string) ($context['verificationUrl'] ?? '/events/verification.html?transactionId=' . rawurlencode($transactionIdRaw));
        $verificationUrl = htmlspecialchars($verificationUrlRaw, ENT_QUOTES, 'UTF-8');
        $staffCheckinUrlRaw = (string) ($context['staffCheckinUrl'] ?? $context['staff_checkin_url'] ?? $verificationUrlRaw);
        $staffCheckinUrl = htmlspecialchars($staffCheckinUrlRaw, ENT_QUOTES, 'UTF-8');
        $qrUrlRaw = (string) ($context['qrUrl'] ?? $context['qr_url'] ?? '');
        $qrUrl = htmlspecialchars($qrUrlRaw, ENT_QUOTES, 'UTF-8');

        $attendeeNames = $context['attendeeNames'] ?? $context['attendee_names'] ?? [];
        if (!is_array($attendeeNames)) {
            $attendeeNames = [];
        }

        $normalizedAttendees = [];
        foreach ($attendeeNames as $name) {
            $clean = trim((string) $name);
            if ($clean !== '') {
                $normalizedAttendees[] = htmlspecialchars($clean, ENT_QUOTES, 'UTF-8');
            }
        }
        if ($normalizedAttendees === []) {
            $normalizedAttendees[] = $customerName;
        }

        $entryType = $isFreeRegistration ? 'Free Entry' : 'Paid Entry';
        $entryTypeEscaped = htmlspecialchars($entryType, ENT_QUOTES, 'UTF-8');
        $ticketTitle = $isFreeRegistration ? 'Free Registration Confirmed' : 'Registration Confirmed';
        $ticketTitleEscaped = htmlspecialchars($ticketTitle, ENT_QUOTES, 'UTF-8');
        $amountText = $isFreeRegistration || $amount === '' || $amount === '0' || $amount === '0.00'
            ? 'Free Entry'
            : htmlspecialchars($currency . ' ' . $amount, ENT_QUOTES, 'UTF-8');

        $eventScheduleLine = $eventStartAt !== '' ? $eventStartAt . ' onwards' : 'Event schedule as announced';

        $qrImageHtml = '';
        if ($qrUrlRaw !== '') {
            $qrImageUrl = $this->qrImageEndpointUrl($qrUrlRaw);
            $qrImageEscaped = htmlspecialchars($qrImageUrl, ENT_QUOTES, 'UTF-8');
            $qrImageHtml = '<div class="qr-box">'
                . '<div class="qr-label">Show this QR code at entry</div>'
                . '<img src="' . $qrImageEscaped . '" alt="Event QR" width="220" height="220" style="display:block;margin:10px auto 8px auto;border:1px solid #d4b06f;border-radius:8px;background:#fff;padding:6px;" />'
                . '</div>';
        }

        $attendeeItems = '';
        foreach ($normalizedAttendees as $attendee) {
            $attendeeItems .= '<li>' . $attendee . '</li>';
        }

        $eventEndLine = $eventEndAt !== '' ? $eventEndAt : '-';

        $html = <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        body {
            font-family: 'Segoe UI', Arial, sans-serif;
            line-height: 1.5;
            color: #f9ecd8;
            background-color: #0f0606;
            margin: 0;
            padding: 12px;
        }
        .email-container {
            max-width: 600px;
            margin: 0 auto;
            background: linear-gradient(180deg, #1f0909 0%, #130707 100%);
            border: 1px solid #5a2727;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.35);
        }
        .email-header {
            background: linear-gradient(90deg, #2a0505 0%, #5c0912 100%);
            color: #f5d39b;
            padding: 14px 18px;
            border-bottom: 1px solid #874100;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .email-header h1 {
            margin: 0;
            font-size: 18px;
            font-weight: 700;
        }
        .header-tag {
            font-size: 11px;
            color: #ffe4b5;
            background: rgba(255, 221, 155, 0.12);
            border: 1px solid rgba(255, 221, 155, 0.45);
            border-radius: 999px;
            padding: 4px 10px;
            text-transform: uppercase;
            letter-spacing: 0.08em;
        }
        .email-body {
            padding: 18px;
        }
        .event-name {
            font-size: 24px;
            line-height: 1.2;
            margin: 0 0 8px 0;
            color: #ffd793;
            font-weight: 700;
        }
        .intro {
            color: #f8e7cf;
            font-size: 14px;
            margin-bottom: 12px;
        }
        .subline {
            color: #e5c791;
            font-size: 13px;
            margin: 0 0 14px 0;
        }
        .details-card {
            background: #2b0e12;
            border: 1px solid #63333a;
            border-radius: 10px;
            padding: 12px;
            margin: 12px 0;
        }
        .detail-row {
            display: flex;
            justify-content: space-between;
            gap: 10px;
            padding: 8px 0;
            border-bottom: 1px solid #4d252b;
            font-size: 13px;
        }
        .detail-row:last-child {
            border-bottom: none;
        }
        .detail-key {
            color: #e4b36f;
            min-width: 165px;
        }
        .detail-value {
            color: #fff2dc;
            word-break: break-word;
            text-align: right;
            font-weight: 600;
        }
        .attendees-card {
            background: #2b0e12;
            border: 1px solid #63333a;
            border-radius: 10px;
            padding: 12px;
            margin: 12px 0;
        }
        .attendees-card h3 {
            margin: 0 0 8px 0;
            color: #ffcb78;
            font-size: 14px;
        }
        .attendees-card ul {
            margin: 0;
            padding-left: 18px;
        }
        .attendees-card li {
            margin: 4px 0;
            color: #f8e9d1;
        }
        .footer-lines {
            margin: 14px 0;
            color: #f0d8b0;
            font-size: 13px;
        }
        .footer-lines strong {
            color: #ffd08a;
        }
        .qr-box {
            text-align: center;
            margin: 14px 0;
            padding: 12px;
            background: #f5f5f5;
            border-radius: 10px;
            color: #111;
        }
        .qr-label {
            color: #333;
            font-size: 13px;
        }
        .qr-url-title {
            font-weight: 700;
            font-size: 13px;
            margin-top: 8px;
        }
        .qr-url {
            font-size: 11px;
            color: #1247b0;
            word-break: break-all;
        }
        .cta-button {
            display: inline-block;
            background-color: #c96924;
            color: #fff5ea;
            padding: 10px 18px;
            text-decoration: none;
            border-radius: 8px;
            font-weight: bold;
            font-size: 13px;
        }
        .bottom-note {
            margin-top: 10px;
            font-size: 11px;
            color: #dcbf92;
        }
    </style>
</head>
<body>
    <div class="email-container">
        <div class="email-header">
            <h1>Event Ticket</h1>
            <div class="header-tag">{$ticketTitleEscaped}</div>
        </div>

        <div class="email-body">
            <div class="event-name">{$eventTitle}</div>
            <div class="intro">Hello <strong>{$customerName}</strong>,</div>
            <p class="subline">Thanks for registering with us. Your {$entryTypeEscaped} pass is confirmed.</p>
            <p class="subline">Your QR event pass is ready.</p>
            <p class="subline">{$eventScheduleLine}</p>

            <div class="details-card">
                <div class="detail-row"><span class="detail-key">Registration ID</span><span class="detail-value">{$transactionId}</span></div>
                <div class="detail-row"><span class="detail-key">Registered At</span><span class="detail-value">{$createdAt}</span></div>
                <div class="detail-row"><span class="detail-key">Registration Confirmed At</span><span class="detail-value">{$createdAt}</span></div>
                <div class="detail-row"><span class="detail-key">Event Starts</span><span class="detail-value">{$eventStartAt}</span></div>
                <div class="detail-row"><span class="detail-key">Event Ends</span><span class="detail-value">{$eventEndLine}</span></div>
                <div class="detail-row"><span class="detail-key">Tickets</span><span class="detail-value">{$qty}</span></div>
                <div class="detail-row"><span class="detail-key">Entry Type</span><span class="detail-value">{$amountText}</span></div>
            </div>

            <div class="attendees-card">
                <h3>Attendees</h3>
                <ul>
                    {$attendeeItems}
                </ul>
            </div>

            <div class="footer-lines">
                <div><strong>Venue:</strong> {$eventVenue}</div>
                <div style="margin-top:6px;"><strong>Support:</strong> {$supportPhone} | <strong>Policy:</strong> {$policyTextEscaped}</div>
            </div>

            {$qrImageHtml}

            <p style="text-align:center; margin:8px 0 2px 0;">
                <a href="{$staffCheckinUrl}" class="cta-button">Open Staff Check-In (Passcode)</a>
            </p>
            <p class="bottom-note">Entry staff must enter the event passcode after opening this link.</p>
        </div>
    </div>
</body>
</html>
HTML;

        return $html;
    }

    private function buildCheckinTemplate(array $context): string
    {
        $eventTitle = htmlspecialchars((string) ($context['event_title'] ?? ''), ENT_QUOTES, 'UTF-8');
        $admitted = (int) ($context['admitted_count'] ?? 1);
        $remaining = (int) ($context['remaining'] ?? 0);

        return '<h2>Check-in Successful</h2>'
            . '<p>Your check-in for <strong>' . $eventTitle . '</strong> was successful.</p>'
            . '<p><strong>Admitted:</strong> ' . $admitted . ' guest(s)</p>'
            . ($remaining > 0 ? '<p><strong>Remaining:</strong> ' . $remaining . ' guest(s)</p>' : '');
    }

    private function buildAlertTemplate(string $subject, string $message, array $data): string
    {
        $safeSubject = htmlspecialchars($subject, ENT_QUOTES, 'UTF-8');
        $safeMessage = htmlspecialchars($message, ENT_QUOTES, 'UTF-8');

        $html = '<h2>System Alert: ' . $safeSubject . '</h2>'
            . '<p>' . nl2br($safeMessage) . '</p>';

        if (!empty($data)) {
            $html .= '<p><strong>Details:</strong></p><pre>' . htmlspecialchars(json_encode($data, JSON_PRETTY_PRINT), ENT_QUOTES, 'UTF-8') . '</pre>';
        }

        return $html;
    }

    private function buildLeadTemplate(array $lead): string
    {
        $name = htmlspecialchars((string) ($lead['name'] ?? ''), ENT_QUOTES, 'UTF-8');
        $phone = htmlspecialchars((string) ($lead['phone'] ?? ''), ENT_QUOTES, 'UTF-8');
        $email = htmlspecialchars((string) ($lead['email'] ?? ''), ENT_QUOTES, 'UTF-8');
        $message = htmlspecialchars((string) ($lead['message'] ?? ''), ENT_QUOTES, 'UTF-8');

        return '<h2>New Lead Notification</h2>'
            . '<p><strong>Name:</strong> ' . $name . '</p>'
            . '<p><strong>Phone:</strong> ' . $phone . '</p>'
            . '<p><strong>Email:</strong> ' . $email . '</p>'
            . '<p><strong>Message:</strong></p>'
            . '<p>' . nl2br($message) . '</p>';
    }

    private function qrImageEndpointUrl(string $qrUrl): string
    {
        $qrUrl = trim($qrUrl);
        if ($qrUrl === '') {
            return '';
        }
        return $this->absoluteUrl('/?action=event_qr_image&data=' . rawurlencode($qrUrl));
    }

    private function absoluteUrl(string $url): string
    {
        if ($url === '') {
            return '';
        }
        if (preg_match('#^https?://#i', $url) === 1) {
            return $url;
        }

        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
        return $scheme . '://' . $host . '/' . ltrim($url, '/');
    }
}
