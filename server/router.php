<?php
/**
 * Router for PHP's built-in web server (running on your own computer):
 *   php -S 0.0.0.0:8080 -t server server/router.php
 * The built-in server ignores .htaccess, so this is what keeps the
 * database, config.php and the PHP internals unreachable from the network.
 * Only api.php, the app/ folder and the accounting app (acc/) are served.
 */

$path = rawurldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/');

// the same security headers as .htaccess gives on Apache
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: no-referrer');
header('Permissions-Policy: camera=(), geolocation=(), payment=(), usb=()');
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-eval'; style-src 'self' 'unsafe-inline'; font-src 'self'; "
    . "img-src 'self' data: blob:; media-src 'self' blob: data:; connect-src 'self'; worker-src 'self'; manifest-src 'self'; frame-src 'self'; "
    . "frame-ancestors 'self'; object-src 'none'; base-uri 'self'; form-action 'self'");

if ($path === '/start.js' || $path === '/assistant/assistant.js') {
    header('Content-Type: text/javascript; charset=utf-8');
    readfile(__DIR__ . $path);
    return true;
}

if ($path === '/' || $path === '/index.html') {
    header('Content-Type: text/html; charset=utf-8');
    readfile(__DIR__ . '/index.html');
    return true;
}
if ($path === '/assistant/' || $path === '/assistant') {   // Universal Link landing page (app not installed)
    header('Content-Type: text/html; charset=utf-8');
    readfile(__DIR__ . '/assistant/index.html');
    return true;
}
if ($path === '/app') {
    header('Location: /app/');
    exit;
}
if ($path === '/api.php') {
    require __DIR__ . '/api.php';
    return true;
}
if ($path === '/acc' || $path === '/acc/') {
    if ($path === '/acc') {
        header('Location: /acc/');
        exit;
    }
    header('Content-Type: text/html; charset=utf-8');
    readfile(__DIR__ . '/acc/index.html');
    return true;
}
if ($path === '/acc/api.php') {
    require __DIR__ . '/acc/api.php';
    return true;
}
if (strpos($path, '/acc/') === 0 && strpos($path, '..') === false && is_file(__DIR__ . $path)
    && preg_match('/\.(js|css|woff2?|png|svg|ico|html)$/', $path)) {
    return false;   // static file of the accounting app
}
if (strpos($path, '/app/') === 0 && strpos($path, '..') === false) {
    if ($path === '/app/') {
        header('Content-Type: text/html; charset=utf-8');
        readfile(__DIR__ . '/app/index.html');
        return true;
    }
    if (is_file(__DIR__ . $path)) {
        if (substr($path, -4) === '.apk') {
            header('Content-Type: application/vnd.android.package-archive');
            header('Content-Disposition: attachment; filename="bank-assistant.apk"');
            readfile(__DIR__ . $path);
            return true;
        }
        if (substr($path, -12) === '.webmanifest') {
            header('Content-Type: application/manifest+json');
            readfile(__DIR__ . $path);
            return true;
        }
        return false;   // let the built-in server send the static file
    }
}
http_response_code(404);
echo 'Not found';
return true;
