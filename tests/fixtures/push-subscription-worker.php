<?php

declare(strict_types=1);

use Mk\Framework\Database;
use Mk\Framework\Push\PushSubscriptionLimitExceeded;
use Mk\Framework\Push\PushSubscriptionRepository;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$startFile = (string) ($argv[1] ?? '');
$endpoint = (string) ($argv[2] ?? '');
$deadline = microtime(true) + 10;
while (!is_file($startFile) && microtime(true) < $deadline) {
    usleep(10_000);
}
if (!is_file($startFile)) {
    fwrite(STDERR, 'start timeout');
    exit(2);
}

$config = [
    'driver' => (string) getenv('DB_DRIVER'),
    'database' => (string) getenv('DB_NAME'),
    'formatDate' => "'Y-m-d'",
    'formatDateTime' => "'Y-m-d H:i:s'",
];
if ($config['driver'] === 'mysqli') {
    $config['host'] = (string) getenv('DB_HOST');
    $config['username'] = (string) getenv('DB_USER');
    $config['password'] = (string) getenv('DB_PASS');
    $port = (string) getenv('DB_PORT');
    if ($port !== '') {
        $config['port'] = (int) $port;
    }
}

try {
    $database = $config['driver'] === 'sqlite3'
        ? Database::sqlite((string) $config['database'])
        : new Database(new \Dibi\Connection($config));
    $repository = new PushSubscriptionRepository($database, 1);
    $repository->save(
        $endpoint,
        rtrim(strtr(base64_encode("\x04" . str_repeat('p', 64)), '+/', '-_'), '='),
        rtrim(strtr(base64_encode(str_repeat('a', 16)), '+/', '-_'), '='),
        null,
        hash('sha256', $endpoint),
    );
    echo 'saved';
} catch (PushSubscriptionLimitExceeded) {
    echo 'limited';
} catch (Throwable $e) {
    fwrite(STDERR, $e::class . ':' . $e->getCode());
    exit(1);
}
