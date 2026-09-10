<?php

declare(strict_types=1);

use Dotenv\Dotenv;
use Mk\Framework\Config;
use Mk\Framework\ErrorHandler;
use Mk\Framework\Router;
use Mk\Framework\View;

// DOCUMENT ROOT: project root is the parent of this public/ webroot
define('ROOT_DIR', dirname(__DIR__));

// CONSTANTS
include_once ROOT_DIR . '/utils/@constants.php';

// COMPOSER AUTOLOAD (PSR-4 handles the framework classes)
require_once ROOT_DIR . '/vendor/autoload.php';

// ENVIRONMENT (.env): safeLoad() so deployments using real env vars don't require a file
Dotenv::createImmutable(ROOT_DIR)->safeLoad();

// ERROR DISPLAY: driven by APP_DEBUG (never leak errors to the client in production)
ini_set('display_errors', Config::isDebug() ? '1' : '0');
error_reporting(E_ALL);

// GLOBAL EXCEPTION HANDLER: logs any uncaught throwable and renders a 500 page
ErrorHandler::register();

// SETTINGS AND CONFIG
include_once ROOT_DIR . '/utils/@settings.php';

// SECURITY HEADERS
// Sent before the request handler below, which can render output and exit()
// on a failed login, so these must ship on every response path.
// TMDB serves the request posters on the Jellyseerr page straight from its CDN.
$imgSrc = "'self' data: https://image.tmdb.org";
$jellyfinUrl = Config::get('JELLYFIN_URL');
if ($jellyfinUrl !== null) {
    $parts = parse_url($jellyfinUrl);
    if (
        is_array($parts)
        && isset($parts['scheme'], $parts['host'])
        && in_array($parts['scheme'], ['http', 'https'], true)
    ) {
        $imgSrc .= ' ' . $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
    }
}

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: strict-origin-when-cross-origin');
header("Content-Security-Policy: default-src 'self'; img-src {$imgSrc}; style-src 'self' 'unsafe-inline'; script-src 'self'; object-src 'none'; base-uri 'self'; frame-ancestors 'none'");
if ($requestContext->isHttps($_SERVER)) {
    header('Strict-Transport-Security: max-age=31536000');
}

// REQUESTS & AJAX (actions: ?req= / ?auth=)
include_once ROOT_DIR . '/operations/@request.php';

// ROUTE & RENDER: map the current page/category to a controller
(new Router(new View()))->dispatch(PAGE, CATEGORY);
