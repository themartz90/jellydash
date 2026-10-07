<?php

declare(strict_types=1);

use Mk\Framework\Authorization;
use Mk\Framework\Container;
use Mk\Framework\Pages\ServerActivityController;
use Mk\Framework\View;
use PHPUnit\Framework\TestCase;

final class ServerActivityAccessTest extends TestCase
{
    private string|false $previousDebug;
    private ?string $previousDebugEnv;

    protected function setUp(): void
    {
        $this->previousDebug = getenv('APP_DEBUG');
        $this->previousDebugEnv = $_ENV['APP_DEBUG'] ?? null;
        putenv('APP_DEBUG=true');
        $_ENV['APP_DEBUG'] = 'true';
    }

    protected function tearDown(): void
    {
        putenv($this->previousDebug === false ? 'APP_DEBUG' : 'APP_DEBUG=' . $this->previousDebug);
        if ($this->previousDebugEnv === null) {
            unset($_ENV['APP_DEBUG']);
        } else {
            $_ENV['APP_DEBUG'] = $this->previousDebugEnv;
        }
    }

    public function testPageAndNavigationHonorCurrentRoleAndDemotion(): void
    {
        $previous = $_ENV['AUTH_ENABLED'] ?? null;
        $session = $_SESSION ?? [];
        $_ENV['AUTH_ENABLED'] = 'true';
        $db = Container::db();
        $db->ensureAuthSchema();
        $id = $db->addAuthUser('activity-page-test', 'test-password-123', 'Tester', Authorization::ROLE_OWNER);
        $_SESSION = ['auth_user' => ['id' => $id, 'role' => 1], 'auth_login_time' => time(), 'auth_last_activity' => time()];
        try {
            foreach ([1 => true, 2 => true, 3 => false, 4 => false] as $role => $allowed) {
                $db->getDibi()->update('users', ['role' => $role])->where('id = %i', $id)->execute();
                http_response_code(200);
                ob_start();
                (new ServerActivityController(new View()))->handle();
                $html = (string) ob_get_clean();
                self::assertSame($allowed, str_contains($html, 'data-server-activity-root'));
                self::assertSame($allowed, str_contains($html, 'href="/server-activity"'));
                self::assertSame($allowed ? 200 : 403, http_response_code());
            }
        } finally {
            $db->getDibi()->delete('users')->where('id = %i', $id)->execute();
            $_SESSION = $session;
            http_response_code(200);
            if ($previous === null) {
                unset($_ENV['AUTH_ENABLED']);
            } else {
                $_ENV['AUTH_ENABLED'] = $previous;
            }
        }
    }

    public function testOpenDashboardRendersPageWithoutAnyRemoteRequests(): void
    {
        $previous = $_ENV['AUTH_ENABLED'] ?? null;
        $_ENV['AUTH_ENABLED'] = 'false';
        try {
            ob_start();
            (new ServerActivityController(new View()))->handle();
            $html = (string) ob_get_clean();
            self::assertStringContainsString('data-server-activity-root', $html);
            self::assertStringContainsString('href="/server-activity"', $html);
            self::assertStringContainsString('Scheduled tasks', $html);
            self::assertStringContainsString('Recent activity', $html);
        } finally {
            if ($previous === null) {
                unset($_ENV['AUTH_ENABLED']);
            } else {
                $_ENV['AUTH_ENABLED'] = $previous;
            }
        }
    }

    public function testAnonymousAuthEnabledAccessHidesDataAndNavigation(): void
    {
        $previous = $_ENV['AUTH_ENABLED'] ?? null;
        $session = $_SESSION ?? [];
        $_ENV['AUTH_ENABLED'] = 'true';
        $_SESSION = [];
        http_response_code(200);
        try {
            self::assertFalse((new Authorization())->can(Authorization::CAPABILITY_MANAGE_GLOBAL));
            ob_start();
            (new ServerActivityController(new View()))->handle();
            $html = (string) ob_get_clean();
            self::assertSame(403, http_response_code());
            self::assertStringNotContainsString('data-server-activity-root', $html);
            self::assertStringNotContainsString('href="/server-activity"', $html);
            self::assertStringContainsString('owners and admins', $html);
        } finally {
            $_SESSION = $session;
            http_response_code(200);
            if ($previous === null) {
                unset($_ENV['AUTH_ENABLED']);
            } else {
                $_ENV['AUTH_ENABLED'] = $previous;
            }
        }
    }
}
