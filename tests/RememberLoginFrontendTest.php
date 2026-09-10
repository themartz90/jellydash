<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class RememberLoginFrontendTest extends TestCase
{
    public function testLoginOffersADeviceSpecificRememberChoice(): void
    {
        $template = (string) file_get_contents(TEMPLATES_DIR . '/login.twig');
        $request = (string) file_get_contents(ROOT_DIR . '/operations/@request.php');

        $this->assertStringContainsString('name="remember_me" value="1"', $template);
        $this->assertStringContainsString('Keep me signed in', $template);
        $this->assertStringContainsString('Stay signed in on this device for up to 90 days.', $template);
        $this->assertStringContainsString("\$_POST['remember_me'] === '1'", $request);
        $this->assertStringContainsString('userLogin($username, $password, $remember)', $request);
    }

    public function testOrdinarySessionConfigurationMatchesTheEightHourLimit(): void
    {
        $settings = (string) file_get_contents(ROOT_DIR . '/utils/@settings.php');
        $shell = (string) file_get_contents(TEMPLATES_DIR . '/_shell.twig');
        $login = (string) file_get_contents(TEMPLATES_DIR . '/login.twig');

        $this->assertStringContainsString("ini_set('session.gc_maxlifetime', (string) Authorization::SESSION_ABSOLUTE_TIMEOUT)", $settings);
        $this->assertStringContainsString("ROOT_DIR . '/var/sessions'", $settings);
        $this->assertStringContainsString("'lifetime' => Authorization::SESSION_ABSOLUTE_TIMEOUT", $settings);
        $this->assertStringContainsString('dashboard.css?v={{ asset_revision }}', $shell);
        $this->assertStringContainsString('dashboard.css?v={{ asset_revision }}', $login);
    }
}
