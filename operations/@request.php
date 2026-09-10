<?php

declare(strict_types=1);

use Mk\Framework\Authorization;
use Mk\Framework\Csrf;
use Mk\Framework\Main;
use Mk\Framework\Pager;
use Mk\Framework\Pages\LoginController;
use Mk\Framework\Requests;
use Mk\Framework\Upload;
use Mk\Framework\View;

{
    // Init Requests Class
    $requests = new Requests();

    // State-changing actions must be POST + carry a valid CSRF token.
    $isPost = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';

    // LOGIN -----------------------------------------------------------------------------------------------------------
    if ($requests->authIs('login') && $isPost) {
        Csrf::check();

        $auth_class = new Authorization();

        // password is passed raw to password_verify (never escaped)
        $username = Main::capturePostString('username');
        $password = $_POST['pwd'] ?? null;
        $remember = isset($_POST['remember_me']) && $_POST['remember_me'] === '1';

        if ($auth_class->userLogin($username, $password, $remember)) {
            Pager::homePage();
        }

        // Bad credentials -> generic message (DB errors propagate to ErrorHandler).
        (new LoginController(new View()))->withError('Invalid username or password.')->handle();
        exit;
    }

    // UPLOAD ----------------------------------------------------------------------------------------------------------
    if ($requests->requestIs('upload') && $isPost) {
        Csrf::check();

        $auth_class = new Authorization();
        if (!$auth_class->can(Authorization::CAPABILITY_MANAGE_GLOBAL)) {
            http_response_code(403);
            exit('Forbidden');
        }

        $upload = new Upload();
        $upload->uploadImage('photo', 'img');

        if ($upload->getResult()) {
            // Get filename
            $filename = $upload->getFileName();
        } else {
            // Get exception - debug
            // error
        }
    }

    // SETTINGS --------------------------------------------------------------------------------------------------------
    if ($requests->requestIs('settings') && $isPost) {
        Csrf::check();

        if (!(new Authorization())->can(Authorization::CAPABILITY_MANAGE_GLOBAL)) {
            http_response_code(403);
            exit('Forbidden');
        }

        $csv = static function (array $checked, string $extraField): string {
            $extra = array_filter(array_map(
                static fn (string $s): string => trim($s, " \t\n\r\0\x0B\"'"),
                explode(',', (string) ($_POST[$extraField] ?? ''))
            ), static fn (string $s): bool => $s !== '');

            $values = [];
            foreach ([...$checked, ...$extra] as $value) {
                $value = trim((string) $value);
                if ($value !== '' && mb_strlen($value) <= 128 && !isset($values[mb_strtolower($value)])) {
                    $values[mb_strtolower($value)] = $value;
                }
            }

            return implode(',', array_values($values));
        };

        $exclude = is_array($_POST['trending_exclude'] ?? null) ? $_POST['trending_exclude'] : [];
        $ignore = is_array($_POST['push_ignore'] ?? null) ? $_POST['push_ignore'] : [];
        $monitoringIgnore = is_array($_POST['monitoring_ignore'] ?? null) ? $_POST['monitoring_ignore'] : [];

        \Mk\Framework\AppSettings::set('server_label', mb_substr(trim((string) ($_POST['server_label'] ?? '')), 0, 64));
        \Mk\Framework\AppSettings::set('show_server_stats', isset($_POST['show_server_stats']) ? '1' : '0');
        \Mk\Framework\AppSettings::set('show_recently_added', isset($_POST['show_recently_added']) ? '1' : '0');
        \Mk\Framework\AppSettings::set('trending_exclude_libraries', $csv($exclude, 'trending_exclude_extra'));
        \Mk\Framework\AppSettings::set('push_ignore_users', $csv($ignore, 'push_ignore_extra'));
        \Mk\Framework\AppSettings::set('ignore_users', $csv($monitoringIgnore, 'monitoring_ignore_extra'));

        header('Location: /settings?saved=1');
        exit;
    }

    // STATISTICS DEFAULT ---------------------------------------------------------------------------------------------
    if ($requests->requestIs('statistics-default') && $isPost) {
        Csrf::check();

        if (!(new Authorization())->can(Authorization::CAPABILITY_MANAGE_GLOBAL)) {
            http_response_code(403);
            exit('Forbidden');
        }

        $range = Main::capturePostString('range');
        if (!\Mk\Framework\Jellyfin\StatisticsPeriod::isValidRange($range)) {
            http_response_code(400);
            exit('Invalid statistics range.');
        }

        \Mk\Framework\AppSettings::set('statistics_default_range', $range);

        header('Location: /statistics?range=' . rawurlencode($range));
        exit;
    }

    // NOTIFICATION DEVICE REVOCATION --------------------------------------------------------------------------------
    if ($requests->requestIs('push-revoke') && $isPost) {
        Csrf::check();

        $auth_class = new Authorization();
        if (!$auth_class->can(Authorization::CAPABILITY_MANAGE_OWN_PUSH)) {
            http_response_code(403);
            exit('Forbidden');
        }
        $authEnabled = \Mk\Framework\Config::bool('AUTH_ENABLED', false);
        $verifiedUser = $authEnabled ? $auth_class->verifiedUser() : null;
        $userId = $verifiedUser !== null ? $verifiedUser['id'] : null;
        $canManageAll = $auth_class->can(Authorization::CAPABILITY_MANAGE_ALL_PUSH);
        $deviceId = max(0, (int) ($_POST['device_id'] ?? 0));
        (new \Mk\Framework\Push\PushSubscriptionRepository())
            ->revokeById($deviceId, $userId, $canManageAll, $authEnabled);

        header('Location: /settings#notification-devices');
        exit;
    }

    // LOGOUT ----------------------------------------------------------------------------------------------------------
    if ($requests->authIs('logout') && $isPost) {
        Csrf::check();

        $auth_class = new Authorization();
        $auth_class->userLogout();
        Pager::login();
    }
}
