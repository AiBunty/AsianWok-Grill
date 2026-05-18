<?php

declare(strict_types=1);

$logDir = __DIR__ . '/storage/logs';
if (!is_dir($logDir)) {
    @mkdir($logDir, 0755, true);
}

$bootLog = $logDir . '/boot.log';
$writeBootLog = static function (string $label, array $context = []) use ($bootLog): void {
    $entry = [
        'time' => date('Y-m-d H:i:s'),
        'label' => $label,
        'context' => $context,
    ];

    @file_put_contents($bootLog, json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL, FILE_APPEND | LOCK_EX);
};

$writeBootLog('request_start', [
    'uri' => (string) ($_SERVER['REQUEST_URI'] ?? ''),
    'method' => (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'),
]);

require_once __DIR__ . '/bootstrap/app.php';
require_once __DIR__ . '/app/Config/Database.php';
require_once __DIR__ . '/app/Support/Response.php';
require_once __DIR__ . '/app/Support/Logger.php';
require_once __DIR__ . '/app/Support/PasswordHasher.php';
require_once __DIR__ . '/app/Support/Jwt.php';
require_once __DIR__ . '/app/Support/SimpleXlsx.php';
require_once __DIR__ . '/app/Middleware/CorsMiddleware.php';
require_once __DIR__ . '/app/Middleware/AuthMiddleware.php';
require_once __DIR__ . '/app/Middleware/PermissionMiddleware.php';
require_once __DIR__ . '/app/Repositories/UserRepository.php';
require_once __DIR__ . '/app/Repositories/AuthAuditRepository.php';
require_once __DIR__ . '/app/Repositories/LeadRepository.php';
require_once __DIR__ . '/app/Repositories/MenuBlockerRepository.php';
require_once __DIR__ . '/app/Repositories/MenuRepository.php';
require_once __DIR__ . '/app/Repositories/MenuManagementRepository.php';
require_once __DIR__ . '/app/Repositories/AppSettingRepository.php';
require_once __DIR__ . '/app/Repositories/ContactRepository.php';
require_once __DIR__ . '/app/Repositories/CrmPushLogRepository.php';
require_once __DIR__ . '/app/Repositories/QrScanRepository.php';
require_once __DIR__ . '/app/Repositories/QrRedirectSettingsRepository.php';
require_once __DIR__ . '/app/Repositories/QrRedirectRepository.php';
require_once __DIR__ . '/app/Repositories/EventRepository.php';
require_once __DIR__ . '/app/Repositories/EventBookingRepository.php';
require_once __DIR__ . '/app/Repositories/EventTransactionRepository.php';
require_once __DIR__ . '/app/Repositories/EventCheckinLogRepository.php';
require_once __DIR__ . '/app/Repositories/EventOtpRepository.php';
require_once __DIR__ . '/app/Repositories/EventMailLogRepository.php';
require_once __DIR__ . '/app/Repositories/WhatsAppTemplateRepository.php';
require_once __DIR__ . '/app/Repositories/WhatsAppEventMappingRepository.php';
require_once __DIR__ . '/app/Repositories/WhatsAppMessageLogRepository.php';
require_once __DIR__ . '/app/Repositories/WhatsAppScheduledMessageRepository.php';
require_once __DIR__ . '/app/Repositories/WhatsAppTemplateDraftRepository.php';
require_once __DIR__ . '/app/Repositories/WhatsAppEventMessageVersionRepository.php';
require_once __DIR__ . '/app/Services/AuthService.php';
require_once __DIR__ . '/app/Services/CrmTriggerService.php';
require_once __DIR__ . '/app/Services/LeadService.php';
require_once __DIR__ . '/app/Services/MenuBlockerService.php';
require_once __DIR__ . '/app/Services/DiagnosticsService.php';
require_once __DIR__ . '/app/Services/MenuImportService.php';
require_once __DIR__ . '/app/Services/MenuService.php';
require_once __DIR__ . '/app/Services/MenuManagementService.php';
require_once __DIR__ . '/app/Services/AdminModuleService.php';
require_once __DIR__ . '/app/Services/CrmContactExportService.php';
require_once __DIR__ . '/app/Services/CrmLeadExportService.php';
require_once __DIR__ . '/app/Services/WhatsAppCloudService.php';
require_once __DIR__ . '/app/Services/OtpService.php';
require_once __DIR__ . '/app/Services/MailerService.php';
require_once __DIR__ . '/app/Services/EventService.php';
require_once __DIR__ . '/app/Controllers/AuthController.php';
require_once __DIR__ . '/app/Controllers/LeadController.php';
require_once __DIR__ . '/app/Controllers/DiagnosticsController.php';
require_once __DIR__ . '/app/Controllers/MenuController.php';
require_once __DIR__ . '/app/Controllers/MenuManagementController.php';
require_once __DIR__ . '/app/Controllers/AdminModuleController.php';
require_once __DIR__ . '/app/Controllers/EventController.php';
require_once __DIR__ . '/app/Controllers/WebhookController.php';
require_once __DIR__ . '/app/Routes/ActionRouter.php';

use AWG\Middleware\CorsMiddleware;
use AWG\Routes\ActionRouter;
use AWG\Support\Logger;
use AWG\Support\Response;

CorsMiddleware::handle();

$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$query = $_GET ?? [];
$body = [];
$rawBody = '';

if (in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
    $rawBody = (string) file_get_contents('php://input');
    $body = json_decode($rawBody, true) ?? [];

    if (empty($body) && !empty($_POST)) {
        $body = $_POST;
    }

    $contentType = (string) ($_SERVER['CONTENT_TYPE'] ?? '');
    if (stripos($contentType, 'multipart/form-data') !== false && !empty($_POST)) {
        foreach ($_POST as $key => $value) {
            if (!array_key_exists($key, $body)) {
                $body[$key] = $value;
            }
        }
    }

    if (isset($body['payload']) && is_string($body['payload'])) {
        $decodedPayload = json_decode($body['payload'], true);
        if (is_array($decodedPayload)) {
            $body = array_merge($body, $decodedPayload);
            unset($body['payload']);
        }
    }

    $body['_rawBody'] = $rawBody;
}

$action = (string) ($body['action'] ?? $query['action'] ?? '');

if ($action === 'event_qr_image') {
    $data = trim((string) ($query['data'] ?? ''));
    if ($data === '') {
        Response::send([
            'ok' => false,
            'error' => 'QR_DATA_REQUIRED',
            'message' => 'QR data is required.',
        ], 400);
        exit(1);
    }

    $imageBytes = null;
    $endpoints = [
        'https://api.qrserver.com/v1/create-qr-code/?size=800x800&data=' . rawurlencode($data),
        'https://chart.googleapis.com/chart?chs=800x800&chld=M|0&cht=qr&chl=' . rawurlencode($data),
    ];

    foreach ($endpoints as $url) {
        $bytes = null;

        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 4);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            curl_setopt($ch, CURLOPT_USERAGENT, 'AWG-QR-Proxy/1.0');
            $response = curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if (is_string($response) && $response !== '' && $status >= 200 && $status < 300) {
                $bytes = $response;
            }
        }

        if (!is_string($bytes) || $bytes === '') {
            $context = stream_context_create([
                'http' => [
                    'method' => 'GET',
                    'timeout' => 10,
                    'ignore_errors' => true,
                    'header' => "User-Agent: AWG-QR-Proxy/1.0\r\n",
                ],
                'ssl' => [
                    'verify_peer' => true,
                    'verify_peer_name' => true,
                ],
            ]);
            $streamBytes = @file_get_contents($url, false, $context);
            if (is_string($streamBytes) && $streamBytes !== '') {
                $bytes = $streamBytes;
            }
        }

        if (is_string($bytes) && $bytes !== '') {
            $imageBytes = $bytes;
            break;
        }
    }

    if (is_string($imageBytes) && $imageBytes !== '') {
        if (!headers_sent()) {
            header('Content-Type: image/png');
            header('Cache-Control: public, max-age=300');
        }
        echo $imageBytes;
        exit(0);
    }

    if (!headers_sent()) {
        header('Content-Type: image/svg+xml; charset=UTF-8');
        header('Cache-Control: no-store, no-cache, must-revalidate');
    }

    $safe = htmlspecialchars($data, ENT_QUOTES, 'UTF-8');
    echo '<svg xmlns="http://www.w3.org/2000/svg" width="800" height="800" viewBox="0 0 800 800">'
        . '<rect width="800" height="800" fill="#ffffff"/>'
        . '<rect x="24" y="24" width="752" height="752" fill="none" stroke="#000" stroke-width="2"/>'
        . '<text x="400" y="380" text-anchor="middle" font-family="Segoe UI, Arial" font-size="34" fill="#111">QR currently unavailable</text>'
        . '<text x="400" y="430" text-anchor="middle" font-family="Segoe UI, Arial" font-size="18" fill="#444">Use this URL:</text>'
        . '<text x="400" y="470" text-anchor="middle" font-family="Segoe UI, Arial" font-size="14" fill="#0a58ca">' . $safe . '</text>'
        . '</svg>';
    exit(0);
}

try {
    $result = ActionRouter::dispatch($method, $action, $body, $query);

    if ($action === 'whatsapp_webhook' && $method === 'GET') {
        if (($result['ok'] ?? false) === true) {
            header('Content-Type: text/plain; charset=UTF-8');
            echo (string) ($result['challenge'] ?? '');
            exit(0);
        }

        header('Content-Type: text/plain; charset=UTF-8');
        http_response_code(403);
        echo (string) ($result['message'] ?? 'Forbidden');
        exit(1);
    }

    $status = (isset($result['ok']) && $result['ok'] === false) ? 404 : 200;
    Response::send($result, $status);
} catch (\Throwable $exception) {
    $writeBootLog('unhandled_exception', [
        'message' => $exception->getMessage(),
        'file' => $exception->getFile(),
        'line' => $exception->getLine(),
    ]);

    Logger::error('Unhandled exception', [
        'message' => $exception->getMessage(),
        'file' => $exception->getFile(),
        'line' => $exception->getLine(),
    ]);

    Response::send([
        'ok' => false,
        'error' => 'INTERNAL_ERROR',
        'message' => 'Internal server error.',
    ], 500);
}
