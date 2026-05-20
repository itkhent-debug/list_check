<?php
/**
 * Router script for PHP's built-in dev server (php -S).
 *
 * Without a router, php -S returns 405 Method Not Allowed when a non-GET
 * request lands on anything it treats as a static asset, and its CORS
 * preflight handling is unreliable. This router fixes both issues by:
 *
 *   1. Answering CORS preflight (OPTIONS) at the very top with 204.
 *   2. Letting the built-in server serve real static files unchanged.
 *   3. Forwarding all PHP requests (including api/*.php) into PHP.
 *   4. Falling back to login.html for the bare "/" path.
 */

// 1) CORS preflight - answer immediately so it can never produce a 405.
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, X-Auth-Token, Authorization');
    header('Access-Control-Max-Age: 86400');
    header('Content-Length: 0');
    http_response_code(204);
    exit;
}

$uri  = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$path = __DIR__ . $uri;

// 2) Bare root -> serve login page.
if ($uri === '/' || $uri === '') {
    // Let PHP serve the static HTML directly via include.
    $login = __DIR__ . '/login.html';
    if (is_file($login)) {
        header('Content-Type: text/html; charset=UTF-8');
        readfile($login);
        return true;
    }
}

// 3) If the requested file exists and is a .php file, execute it.
if (is_file($path) && substr($path, -4) === '.php') {
    require $path;
    return true;
}

// 4) If it exists as a real static asset, hand it back to the built-in
//    server (returning false tells php -S to serve the file itself with
//    the correct Content-Type, which is what we want for HTML/CSS/JS/img).
if (is_file($path)) {
    return false;
}

// 5) Nothing matched.
http_response_code(404);
header('Content-Type: text/plain; charset=UTF-8');
echo "404 Not Found: $uri";
return true;
