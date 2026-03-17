<?php

/**
 * Router script for PHP built-in server (E2E tests).
 * Bundled with the package — no app modification required.
 * Serves static files with correct MIME types; otherwise delegates to Laravel.
 */
$publicPath = $_ENV['PEST_E2E_PUBLIC_PATH'] ?? '';
if ($publicPath === '' || ! is_dir($publicPath)) {
    http_response_code(500);
    echo 'PEST_E2E_PUBLIC_PATH not set or invalid.';

    return true;
}

$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$file = rtrim($publicPath, '/').$uri;

if ($uri !== '/' && $uri !== '' && is_file($file)) {
    $realPath = realpath($file);
    $realDir = realpath(rtrim($publicPath, '/'));
    if ($realPath !== false && $realDir !== false && str_starts_with($realPath, $realDir)) {
        $ext = pathinfo($file, PATHINFO_EXTENSION);
        $mimes = [
            'js' => 'application/javascript',
            'css' => 'text/css',
            'json' => 'application/json',
            'ico' => 'image/x-icon',
            'png' => 'image/png',
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'gif' => 'image/gif',
            'svg' => 'image/svg+xml',
            'woff' => 'font/woff',
            'woff2' => 'font/woff2',
        ];
        $mime = $mimes[$ext] ?? 'application/octet-stream';
        header('Content-Type: '.$mime);
        header('Content-Length: '.(string) filesize($file));
        readfile($file);

        return true;
    }
}

require rtrim($publicPath, '/').'/index.php';
