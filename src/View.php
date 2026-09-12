<?php

declare(strict_types=1);

namespace Mk\Framework;

use Twig\Environment;
use Twig\Loader\FilesystemLoader;

/**
 * Thin Twig renderer. Knows nothing about routing or page data; controllers
 * pass it a template name and a data array.
 */
class View
{
    public const ASSET_REVISION = '20260912-statistics-title-drilldowns';

    private Environment $twig;

    public function __construct()
    {
        $loader = new FilesystemLoader(TEMPLATES_DIR);

        // Module templates live under a namespace matching the module name,
        // e.g. "@downloads/index.twig".
        foreach (Modules::templatePaths() as $namespace => $dir) {
            $loader->addPath($dir, $namespace);
        }

        $this->twig = new Environment($loader, [
            // No compiled cache in debug/dev (always fresh); file cache in production.
            'cache' => Config::isDebug() ? false : CACHE_DIR,
            'auto_reload' => Config::isDebug(),
            'debug' => Config::isDebug(),
        ]);

        // Expose the CSRF token to every template (for forms).
        $this->twig->addGlobal('csrf_token', Csrf::token());
        $this->twig->addGlobal('asset_revision', self::ASSET_REVISION);

        // Web Push: the public VAPID key the browser needs to subscribe, and
        // whether notifications are switched on. Absent key => the toggle hides.
        $this->twig->addGlobal('vapid_public_key', Config::get('VAPID_PUBLIC_KEY'));
        $this->twig->addGlobal('push_enabled', Config::bool('PUSH_ENABLED', true));
        $this->twig->addGlobal(
            'push_enrollment_allowed',
            (new Authorization())->can(Authorization::CAPABILITY_ENROLL_PUSH),
        );

        // Sidebar subtitle under the brand: user-configurable, empty hides it.
        $this->twig->addGlobal('server_label', AppSettings::get('server_label', 'Jellyfin dashboard'));
        $this->twig->addGlobal('show_server_stats', AppSettings::bool('show_server_stats', true));
        $this->twig->addGlobal('show_recently_added', AppSettings::bool('show_recently_added', true));

        // Jellyseerr nav entry only shows once the integration is configured.
        $this->twig->addGlobal('seerr_enabled', (new Jellyseerr\JellyseerrClient())->isConfigured());

        // App version (VERSION file at the repo root), shown in the sidebar.
        $this->twig->addGlobal('app_version', self::version());

        // Optional authentication: the sidebar shows a sign-out row when on.
        $this->twig->addGlobal('auth_enabled', Config::bool('AUTH_ENABLED', false));

        // Module contributions: sidebar entries + globally loaded assets.
        $moduleAssets = Modules::globalAssets();
        $this->twig->addGlobal('module_nav', Modules::navItems());
        $this->twig->addGlobal('module_styles', $moduleAssets['styles']);
        $this->twig->addGlobal('module_scripts', $moduleAssets['scripts']);
    }

    public function render(string $template, array $data = []): void
    {
        echo $this->twig->load($template . '.twig')->render($data);
    }

    public function exists(string $template): bool
    {
        return file_exists(TEMPLATES_DIR . $template . '.twig');
    }

    public static function version(): string
    {
        static $version = null;

        if ($version === null) {
            $file = ROOT_DIR . '/VERSION';
            $version = is_file($file) ? trim((string) file_get_contents($file)) : '';
            $version = $version !== '' ? $version : 'dev';
        }

        return $version;
    }
}
