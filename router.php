<?php
/**
 * Router script for PHP's built-in dev server (php -S).
 *
 * Two responsibilities only:
 *   1. Answer CORS preflight (OPTIONS) immediately with 204.
 *   2. Map bare "/" to login.html.
 *
 * For everything else we MUST return false so that php -S handles the
 * request natively. Letting php -S execute PHP files itself keeps the
 * correct working directory (e.g. /api), so relative includes such as
 * `require_once 'config.php'` inside api/auth.php resolve correctly.
 * Doing `require` from this router would break those relative includes.
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

$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

// 2) Bare root -> serve login page.
if ($uri === '/' || $uri === '') {
    $login = __DIR__ . '/login.html';
    if (is_file($login)) {
        header('Content-Type: text/html; charset=UTF-8');
        readfile($login);
        return true;
    }
}

// 3) Everything else: defer to the built-in server. It will execute
//    .php files with the correct CWD and serve static files as-is.
return false;
