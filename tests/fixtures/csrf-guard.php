<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$_SESSION = [];
\Mk\Framework\Csrf::token();
$_POST = ['csrf_token' => json_decode($argv[1], true, flags: JSON_THROW_ON_ERROR)];
register_shutdown_function(static function (): void {
    echo '|status=' . http_response_code();
});
\Mk\Framework\Csrf::check();
echo 'accepted';
