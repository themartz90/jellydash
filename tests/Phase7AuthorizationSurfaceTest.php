<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class Phase7AuthorizationSurfaceTest extends TestCase
{
    public function testEveryCoreGlobalMutationRequiresTheGlobalCapability(): void
    {
        foreach ([
            'operations/@request.php',
            'public/api/history-csv.php',
            'public/api/history-library-upgrade.php',
            'public/api/playback-reporting.php',
        ] as $path) {
            $source = file_get_contents(ROOT_DIR . '/' . $path);
            $this->assertIsString($source);
            $this->assertStringContainsString('CAPABILITY_MANAGE_GLOBAL', $source, $path);
            $this->assertStringContainsString('http_response_code(403)', $source, $path);
        }
    }

    public function testPushEndpointsRequireEnrollmentOrOwnedDeviceCapabilities(): void
    {
        $subscribe = file_get_contents(ROOT_DIR . '/public/api/push/subscribe.php');
        $unsubscribe = file_get_contents(ROOT_DIR . '/public/api/push/unsubscribe.php');
        $test = file_get_contents(ROOT_DIR . '/public/api/push/test.php');
        $this->assertIsString($subscribe);
        $this->assertIsString($unsubscribe);
        $this->assertIsString($test);

        $this->assertStringContainsString('CAPABILITY_ENROLL_PUSH', $subscribe);
        $this->assertStringContainsString('PushSubscriptionOwnershipException', $subscribe);
        $this->assertStringContainsString('CAPABILITY_MANAGE_OWN_PUSH', $unsubscribe);
        $this->assertStringContainsString('revokeCurrentEndpoint', $unsubscribe);
        $this->assertStringContainsString("\$scope === 'all'", $test);
        $this->assertStringContainsString('CAPABILITY_MANAGE_GLOBAL', $test);
        $this->assertStringContainsString('currentSubscription', $test);
        $this->assertStringContainsString('sendCurrentDeviceTest', $test);
    }

    public function testSettingsDeviceRowsDoNotRenderPushCredentials(): void
    {
        $template = file_get_contents(ROOT_DIR . '/templates/settings/index.twig');
        $this->assertIsString($template);
        $this->assertStringContainsString('push_devices', $template);
        $this->assertStringContainsString('device.label', $template);
        $this->assertStringNotContainsString('device.endpoint', $template);
        $this->assertStringNotContainsString('device.p256dh', $template);
        $this->assertStringNotContainsString('device.auth', $template);
    }
}
