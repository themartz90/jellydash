<?php

declare(strict_types=1);

namespace Mk\Framework;

use Mk\Framework\Pages\HistoryController;
use Mk\Framework\Pages\DownloadsController;
use Mk\Framework\Pages\HomeController;
use Mk\Framework\Pages\JellyseerrController;
use Mk\Framework\Pages\LibrariesController;
use Mk\Framework\Pages\LoginController;
use Mk\Framework\Pages\MonthlyRecapController;
use Mk\Framework\Pages\NowPlayingController;
use Mk\Framework\Pages\ServerActivityController;
use Mk\Framework\Pages\SettingsController;
use Mk\Framework\Pages\StatisticsController;

/**
 * Maps a request (page / category) to a page controller via an explicit table.
 * Unknown routes get a real 404. Adding a page = add a template, a controller,
 * and one line here.
 */
final class Router
{
    /** @var array<string, class-string<Controller>> route key => controller */
    private const ROUTES = [
        'homepage' => HomeController::class,
        'now-playing' => NowPlayingController::class,
        'downloads' => DownloadsController::class,
        'jellyseerr' => JellyseerrController::class,
        'libraries' => LibrariesController::class,
        'server-activity' => ServerActivityController::class,
        'history' => HistoryController::class,
        'statistics' => StatisticsController::class,
        'statistics/recap' => MonthlyRecapController::class,
        'settings' => SettingsController::class,
        'login' => LoginController::class,
    ];

    public function __construct(private View $view)
    {
    }

    public function dispatch(?string $page, ?string $category): void
    {
        $key = $this->routeKey($page, $category);

        // Optional authentication (AUTH_ENABLED): every page except the login
        // screen requires a session. Off by default: LAN-only deployments can
        // stay frictionless; anything internet-facing should turn it on.
        if (
            Config::bool('AUTH_ENABLED', false)
            && $key !== LOGIN_PAGE
            && !(new Authorization())->isUserLoggedIn()
        ) {
            Pager::login();
        }

        // Core routes win; modules extend the table (see Modules).
        $controllerClass = self::ROUTES[$key] ?? Modules::routes()[$key] ?? null;

        if ($controllerClass === null || !is_subclass_of($controllerClass, Controller::class)) {
            $this->notFound();
            return;
        }

        (new $controllerClass($this->view))->handle();
    }

    private function routeKey(?string $page, ?string $category): string
    {
        if ($category !== null && $page !== null) {
            return $category . '/' . $page;
        }

        return $page ?? 'homepage';
    }

    private function notFound(): void
    {
        http_response_code(404);
        $this->view->render('_404');
    }
}
