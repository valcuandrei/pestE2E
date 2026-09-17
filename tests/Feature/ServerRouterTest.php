<?php

declare(strict_types=1);

/**
 * Regression tests for `resources/server-router.php`, the router script
 * pest-e2e's managed PHP built-in server uses.
 *
 * Each test boots a real `php -S` bound to a random loopback port with the
 * router script wired via `-t <publicPath> router.php`. The router receives
 * an env var `PEST_E2E_PUBLIC_PATH` telling it which directory to treat as
 * publicPath. We then curl the running server and assert on the response.
 *
 * Historical bug (fixed here): the previous guard used
 * `str_starts_with(realpath($file), realpath($publicPath))` to prevent
 * path traversal. That guard incorrectly rejected the Laravel canonical
 * `public/storage -> ../storage/app/public` symlink because the resolved
 * target legitimately lives outside publicPath. Consumers whose media/uploads
 * are served through the standard `/storage/…` URL got 404s from the
 * fallback Laravel routing path. The new guard rejects URI-level `..`
 * traversal before file resolution and no longer inspects realpath.
 */
function startRouterServer(string $publicPath): array
{
    $router = dirname(__DIR__, 2).'/resources/server-router.php';

    if (! is_file($router)) {
        throw new RuntimeException("router not found: {$router}");
    }

    $port = randomFreePort();
    $descriptors = [
        0 => ['file', '/dev/null', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $env = array_replace($_ENV, ['PEST_E2E_PUBLIC_PATH' => $publicPath]);
    $proc = proc_open(
        ['php', '-S', "127.0.0.1:{$port}", '-t', $publicPath, $router],
        $descriptors,
        $pipes,
        $publicPath,
        $env,
    );

    if (! is_resource($proc)) {
        throw new RuntimeException('failed to spawn php -S');
    }

    // Wait until the port accepts connections.
    $deadline = microtime(true) + 5.0;
    while (microtime(true) < $deadline) {
        $sock = @stream_socket_client("tcp://127.0.0.1:{$port}", $errno, $errstr, 0.2);
        if ($sock !== false) {
            fclose($sock);

            return ['proc' => $proc, 'pipes' => $pipes, 'port' => $port];
        }
        usleep(50_000);
    }

    proc_terminate($proc);
    throw new RuntimeException("php -S did not open port {$port}");
}

function stopRouterServer(array $server): void
{
    if (isset($server['pipes']) && is_array($server['pipes'])) {
        foreach ($server['pipes'] as $pipe) {
            if (is_resource($pipe)) {
                @fclose($pipe);
            }
        }
    }
    if (is_resource($server['proc'])) {
        proc_terminate($server['proc']);
        proc_close($server['proc']);
    }
}

function randomFreePort(): int
{
    $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    if (! is_resource($server)) {
        throw new RuntimeException("cannot allocate port: {$errstr}");
    }
    $name = stream_socket_get_name($server, false);
    fclose($server);
    if (! is_string($name)) {
        throw new RuntimeException('cannot resolve local port');
    }
    $parts = explode(':', $name);

    return (int) end($parts);
}

function httpGet(int $port, string $path, int $timeoutSeconds = 3): array
{
    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'timeout' => $timeoutSeconds,
            'ignore_errors' => true,
            'header' => "Connection: close\r\n",
        ],
    ]);
    $body = @file_get_contents("http://127.0.0.1:{$port}{$path}", false, $context);

    $headers = function_exists('http_get_last_response_headers')
        ? (http_get_last_response_headers() ?? [])
        : [];

    $status = 0;
    if (isset($headers[0]) && preg_match('#HTTP/\S+\s+(\d+)#', (string) $headers[0], $m)) {
        $status = (int) $m[1];
    }
    $contentType = null;
    foreach ($headers as $h) {
        if (is_string($h) && stripos($h, 'Content-Type:') === 0) {
            $contentType = trim(substr($h, strlen('Content-Type:')));
        }
    }

    return ['status' => $status, 'body' => (string) $body, 'contentType' => $contentType];
}

/**
 * @return array{root: string, public: string, storagePublic: string}
 */
function makeLaravelLikeLayout(): array
{
    $root = sys_get_temp_dir().'/pest-e2e-router-'.uniqid();
    $public = $root.'/public';
    $storagePublic = $root.'/storage/app/public';

    mkdir($public, 0755, true);
    mkdir($storagePublic.'/1/conversions', 0755, true);

    // A trivial index.php so the fallback path can be exercised without
    // requiring a full Laravel bootstrap. Emits a sentinel string.
    file_put_contents($public.'/index.php', "<?php echo 'ROUTED_TO_LARAVEL:'.(\$_SERVER['REQUEST_URI'] ?? '');\n");

    // A regular public asset — served directly.
    file_put_contents($public.'/regular.png', str_repeat("\x89PNG", 20));

    // The canonical Laravel symlink: public/storage -> ../storage/app/public.
    symlink('../storage/app/public', $public.'/storage');

    // A media conversion file that lives *inside* storage/app/public and
    // MUST be served via the symlink.
    file_put_contents(
        $storagePublic.'/1/conversions/front-image-gallery_thumb.jpg',
        'FAKE-JPG-BODY',
    );

    return ['root' => $root, 'public' => $public, 'storagePublic' => $storagePublic];
}

function rrmdir(string $dir): void
{
    if (! is_dir($dir)) {
        return;
    }

    $iter = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );

    foreach ($iter as $file) {
        if ($file->isLink()) {
            @unlink($file->getPathname());
        } elseif ($file->isDir()) {
            @rmdir($file->getPathname());
        } else {
            @unlink($file->getPathname());
        }
    }
    @rmdir($dir);
}

it('serves a regular public/*.png as a static asset (direct hit under publicPath)', function (): void {
    $layout = makeLaravelLikeLayout();

    try {
        $server = startRouterServer($layout['public']);

        try {
            $r = httpGet($server['port'], '/regular.png');
            expect($r['status'])->toBe(200)
                ->and($r['contentType'])->toContain('image/png')
                ->and($r['body'])->toBeString()
                ->and($r['body'])->not->toBe('');
        } finally {
            stopRouterServer($server);
        }
    } finally {
        rrmdir($layout['root']);
    }
});

it('serves /storage/… media through the Laravel public/storage symlink (regression: realpath guard used to reject this)', function (): void {
    $layout = makeLaravelLikeLayout();

    try {
        $server = startRouterServer($layout['public']);

        try {
            $r = httpGet($server['port'], '/storage/1/conversions/front-image-gallery_thumb.jpg');

            expect($r['status'])->toBe(200)
                ->and($r['contentType'])->toContain('image/jpeg')
                ->and($r['body'])->toBe('FAKE-JPG-BODY');
        } finally {
            stopRouterServer($server);
        }
    } finally {
        rrmdir($layout['root']);
    }
});

it('rejects every URI traversal variant and does NOT serve files outside publicPath', function (): void {
    // Plant a "secret" file OUTSIDE publicPath. If the router honours any
    // traversal variant we throw at it, we'd be able to read it.
    $layout = makeLaravelLikeLayout();
    file_put_contents($layout['root'].'/secret.txt', 'MUST-NOT-BE-SERVED');

    // Every one of these paths, decoded and normalised across `/` and `\`,
    // resolves to a `..` segment that escapes publicPath. The router MUST
    // fall through to Laravel (index.php sentinel `ROUTED_TO_LARAVEL:`) and
    // MUST NOT serve the secret file contents.
    //
    // Coverage matrix:
    //   raw `..` with forward slash             /../secret.txt
    //   raw `..` with backslash                 /..\secret.txt
    //   percent-encoded dot (uppercase)         /%2E%2E/secret.txt
    //   percent-encoded dot (lowercase)         /%2e%2e/secret.txt
    //   percent-encoded forward slash           /foo%2F..%2Fsecret.txt
    //   percent-encoded backslash               /foo%5C..%5Csecret.txt
    //   raw backslash                           /foo\..\secret.txt
    //   fully-encoded ..                        /%2E%2E%2Fsecret.txt
    //   mixed encoding + separator              /%2e%2E%5C..%2fsecret.txt
    //   nested benign path then traversal       /public%2f..%2f..%2fsecret.txt
    $variants = [
        '/../secret.txt',
        '/..\\secret.txt',
        '/%2E%2E/secret.txt',
        '/%2e%2e/secret.txt',
        '/foo%2F..%2Fsecret.txt',
        '/foo%5C..%5Csecret.txt',
        '/foo\\..\\secret.txt',
        '/%2E%2E%2Fsecret.txt',
        '/%2e%2E%5C..%2fsecret.txt',
        '/public%2f..%2f..%2fsecret.txt',
    ];

    try {
        $server = startRouterServer($layout['public']);

        try {
            foreach ($variants as $path) {
                $r = httpGet($server['port'], $path);
                expect($r['body'])
                    ->not->toBe('MUST-NOT-BE-SERVED', "must not serve secret.txt via {$path}")
                    ->and($r['body'])
                    ->toStartWith('ROUTED_TO_LARAVEL:', "must fall through to Laravel for {$path}");
            }
        } finally {
            stopRouterServer($server);
        }
    } finally {
        rrmdir($layout['root']);
    }
});

it('serves legitimate paths that happen to contain a dot but are not traversal', function (): void {
    // Regression guard for the new URI-level guard: names like
    // `..something.png` or `foo..bar.png` or a literal `.` segment must
    // still work. Only a full `..` segment should trip the guard.
    $layout = makeLaravelLikeLayout();
    file_put_contents($layout['public'].'/..something.png', 'ok-dotprefix');
    file_put_contents($layout['public'].'/foo..bar.png', 'ok-doubledot-in-name');
    mkdir($layout['public'].'/./subdir', 0755, true);
    file_put_contents($layout['public'].'/./subdir/asset.png', 'ok-dot-segment');

    try {
        $server = startRouterServer($layout['public']);

        try {
            expect(httpGet($server['port'], '/..something.png')['body'])->toBe('ok-dotprefix');
            expect(httpGet($server['port'], '/foo..bar.png')['body'])->toBe('ok-doubledot-in-name');
            // `.` (single dot) segments are also common in URLs — they must
            // still resolve since we only reject `..`.
            expect(httpGet($server['port'], '/./subdir/asset.png')['body'])->toBe('ok-dot-segment');
        } finally {
            stopRouterServer($server);
        }
    } finally {
        rrmdir($layout['root']);
    }
});

it('falls back to Laravel index.php for unknown routes', function (): void {
    $layout = makeLaravelLikeLayout();

    try {
        $server = startRouterServer($layout['public']);

        try {
            $r = httpGet($server['port'], '/some/laravel/route');
            expect($r['body'])->toStartWith('ROUTED_TO_LARAVEL:/some/laravel/route');
        } finally {
            stopRouterServer($server);
        }
    } finally {
        rrmdir($layout['root']);
    }
});

it('uses the correct MIME type for common extensions', function (): void {
    $layout = makeLaravelLikeLayout();

    // Sprinkle a few extensions.
    file_put_contents($layout['public'].'/style.css', 'body{}');
    file_put_contents($layout['public'].'/app.js', 'export {}');
    file_put_contents($layout['public'].'/data.json', '{}');

    try {
        $server = startRouterServer($layout['public']);

        try {
            expect(httpGet($server['port'], '/style.css')['contentType'])->toContain('text/css');
            expect(httpGet($server['port'], '/app.js')['contentType'])->toContain('application/javascript');
            expect(httpGet($server['port'], '/data.json')['contentType'])->toContain('application/json');
            expect(httpGet($server['port'], '/regular.png')['contentType'])->toContain('image/png');
            expect(httpGet($server['port'], '/storage/1/conversions/front-image-gallery_thumb.jpg')['contentType'])
                ->toContain('image/jpeg');
        } finally {
            stopRouterServer($server);
        }
    } finally {
        rrmdir($layout['root']);
    }
});
