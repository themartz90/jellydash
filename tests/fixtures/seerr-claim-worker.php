<?php

declare(strict_types=1);

use Mk\Framework\Config;
use Mk\Framework\Jellyseerr\SeerrRequestRepository;

require dirname(__DIR__) . '/bootstrap.php';

$repository = new SeerrRequestRepository();
$barrier = Config::get('TEST_CLAIM_BARRIER');
if ($barrier !== null) {
    $deadline = microtime(true) + 5;
    while (!is_file($barrier)) {
        if (microtime(true) >= $deadline) {
            fwrite(STDERR, "Timed out waiting for the claim barrier.\n");
            exit(1);
        }
        usleep(10_000);
    }
}

$claims = $repository->claimUnnotified();
foreach ($claims as $claim) {
    $repository->acknowledgeNotificationClaim(
        (int) $claim['id'],
        (string) $claim['notification_claim_token'],
    );
}
echo count($claims);
