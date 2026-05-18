<?php

declare(strict_types=1);

$uri = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
$normalizedUri = rawurldecode((string) ($uri ?: '/'));

if (strpos($normalizedUri, '/backend') === 0) {
    require __DIR__ . '/index.php';
    return true;
}

$queryString = (string) ($_SERVER['QUERY_STRING'] ?? '');
if (preg_match('/(^|&)action=/i', $queryString)) {
    require __DIR__ . '/index.php';
    return true;
}

$requestMethod = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if (in_array($requestMethod, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
    require __DIR__ . '/index.php';
    return true;
}

if ($requestMethod === 'GET' && preg_match('#^/qr/([a-z0-9][a-z0-9\-]{1,180})/?$#i', $normalizedUri, $matches) === 1) {
    $_GET['slug'] = (string) ($matches[1] ?? '');
    $scanPage = __DIR__ . '/asianwokandgrill.in/qr/scan.html';
    if (is_file($scanPage)) {
        header('Content-Type: text/html; charset=UTF-8');
        readfile($scanPage);
        return true;
    }
}

$staticRoot = __DIR__ . '/asianwokandgrill.in';
$file = $staticRoot . ($normalizedUri === '/' ? '/index.html' : $normalizedUri);

if (is_dir($file)) {
    $directoryIndex = rtrim($file, '/\\') . '/index.html';
    if (is_file($directoryIndex)) {
        header('Content-Type: text/html; charset=UTF-8');
        readfile($directoryIndex);
        return true;
    }
}

if (is_file($file)) {
    $extension = strtolower((string) pathinfo($file, PATHINFO_EXTENSION));
    $mimeTypes = [
        'html' => 'text/html; charset=UTF-8',
        'css' => 'text/css',
        'js' => 'application/javascript',
        'json' => 'application/json',
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
        'svg' => 'image/svg+xml',
        'ico' => 'image/x-icon',
        'woff' => 'font/woff',
        'woff2' => 'font/woff2',
        'ttf' => 'font/ttf',
        'xml' => 'application/xml',
        'txt' => 'text/plain; charset=UTF-8',
        'pdf' => 'application/pdf',
    ];

    header('Content-Type: ' . ($mimeTypes[$extension] ?? 'application/octet-stream'));
    readfile($file);
    return true;
}

$fallback = $staticRoot . '/index.html';
if (is_file($fallback)) {
    header('Content-Type: text/html; charset=UTF-8');
    readfile($fallback);
    return true;
}

http_response_code(404);
echo '404 Not Found';
return true;
