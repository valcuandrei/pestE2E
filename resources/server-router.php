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
$uri = is_string($uri) ? $uri : '';

// Anti-traversal guard, applied at the URI level *before* the filesystem
// path is constructed. This is what prevents `..`-style escapes; the older
// `realpath(...) startsWith realpath(publicPath)` check was removed because
// it incorrectly rejected Laravel's canonical
// `public/storage -> ../storage/app/public` symlink (legitimate uploads
// resolve outside publicPath).
//
// Coverage:
//   - raw `..` on either `/` or `\` separators
//   - percent-encoded dot (`%2E`) and percent-encoded slash / backslash
//     (`%2F`, `%5C`), including mixed encodings
//   - client-supplied Windows-style paths where `\` is the separator
//
// The technique: rawurldecode the whole URI once (collapsing every %2E,
// %2F, %5C, etc. into their literal characters), then treat `\` as a path
// separator by normalising it to `/`, then split on `/` and reject any
// segment that is literally `..`. That single pipeline catches every
// smuggled traversal variant without needing per-form pattern lists.
$decodedUri = rawurldecode($uri);
$normalizedUri = str_replace('\\', '/', $decodedUri);
$traversal = false;
foreach (explode('/', $normalizedUri) as $segment) {
    if ($segment === '..') {
        $traversal = true;
        break;
    }
}

$file = rtrim($publicPath, '/').$uri;

if (! $traversal && $uri !== '/' && $uri !== '' && is_file($file)) {
    $ext = pathinfo($file, PATHINFO_EXTENSION);
    $mimes = [
        'js' => 'application/javascript',
        'mjs' => 'application/javascript',
        'css' => 'text/css',
        'json' => 'application/json',
        'ico' => 'image/x-icon',
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
        'avif' => 'image/avif',
        'svg' => 'image/svg+xml',
        'woff' => 'font/woff',
        'woff2' => 'font/woff2',
        'ttf' => 'font/ttf',
        'otf' => 'font/otf',
        'txt' => 'text/plain',
        'map' => 'application/json',
    ];
    $mime = $mimes[$ext] ?? 'application/octet-stream';
    header('Content-Type: '.$mime);
    header('Content-Length: '.(string) filesize($file));
    readfile($file);

    return true;
}

require rtrim($publicPath, '/').'/index.php';
