<?php

declare(strict_types=1);

use Mk\Framework\Authorization;
use Mk\Framework\Config;
use Mk\Framework\Pager;
use Mk\Framework\RequestContext;

// Enforce the external request policy for pages and direct API requests before
// opening a session or restoring a remembered login.
try {
    $requestContext = RequestContext::fromConfig();
} catch (\InvalidArgumentException $e) {
    \Mk\Framework\Log::logException($e);
    http_response_code(503);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    exit('Jellydash request configuration is invalid. Check the server configuration.');
}
$httpsRedirect = $requestContext->httpsRedirectUrl($_SERVER);
if ($httpsRedirect !== null) {
    header('Cache-Control: no-store');
    header('Location: ' . $httpsRedirect, true, 308);
    exit;
}

// Database: credentials come from the environment (.env / .env.example)
define('DATABASE_NAME', Config::get('DB_NAME', 'framework'));
define('DATABASE_HOST', Config::get('DB_HOST', 'localhost'));
define('DATABASE_PORT', Config::get('DB_PORT'));
define('DATABASE_DRIVER_DIBI', Config::get('DB_DRIVER', 'mysqli'));
define('DATABASE_USERNAME', Config::get('DB_USER', 'root'));
define('DATABASE_PASSWORD', Config::get('DB_PASS', ''));

// SESSION, COOKIES
// Harden the session cookie. `secure` follows the actual connection so local
// HTTP development still works, while production over HTTPS gets the flag.
// (Made env-driven in a later phase; see docs/ROADMAP.md.)
$isHttps = $requestContext->secureCookies($_SERVER);

// Own cookie name so Jellydash never fights other PHP apps (or a second
// Jellydash instance) on the same host over the default PHPSESSID cookie.
// Cookies ignore ports, so two instances on one IP would clobber each other's
// session, which surfaces as failing CSRF checks.
session_name('jellydash_session');

ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
ini_set('session.gc_maxlifetime', (string) Authorization::SESSION_ABSOLUTE_TIMEOUT);

// Keep Jellydash session files away from other PHP apps on the same host.
// Otherwise another app with PHP's shorter cleanup window can remove them.
$sessionPath = ROOT_DIR . '/var/sessions';
if ((is_dir($sessionPath) || @mkdir($sessionPath, 0770, true)) && is_writable($sessionPath)) {
    ini_set('session.save_path', $sessionPath);
}

session_set_cookie_params([
    'lifetime' => Authorization::SESSION_ABSOLUTE_TIMEOUT,
    'path' => '/',
    'secure' => $isHttps,
    'httponly' => true,
    'samesite' => 'Lax',
]);
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Session lifecycle and optional remembered-login restoration are handled by
// Authorization. The ordinary cookie never outlives the 8-hour session.

// TIMEZONE SETTINGS
date_default_timezone_set(Config::timezone());

// FEATURE MODULES: discover manifests and register their autoloaders.
\Mk\Framework\Modules::boot();

// PAGES AND CATEGORIES
$page = Pager::getPage();
$category = Pager::getCategory();

// GLOBAL constants
define('PAGE', $page);
define('CATEGORY', $category);
