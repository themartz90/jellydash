<?php

declare(strict_types=1);

namespace Mk\Framework;

use Jenssegers\Agent\Agent;

/**
 * Base page controller. Each page is a small Controller subclass with a
 * handle() method that builds its data and renders a template.
 */
abstract class Controller
{
    public function __construct(protected View $view)
    {
    }

    abstract public function handle(): void;

    protected function render(string $template, array $data = []): void
    {
        $this->view->render($template, $data);
    }

    /**
     * Shared layout data (current user, device info, page) merged with any
     * page-specific extras such as a title.
     *
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    protected function layout(array $extra = []): array
    {
        $auth = new Authorization();
        $agent = new Agent();
        $posterReviewCount = 0;
        if ($auth->can(Authorization::CAPABILITY_MANAGE_GLOBAL)) {
            try {
                $posterReviewCount = (new \Mk\Framework\Jellyfin\ExternalPosterManager())->noticeCount();
            } catch (\Throwable) {
                // Poster review must not prevent the rest of the dashboard loading.
            }
        }

        $base = [
            'page' => PAGE,
            'user' => $auth->isUserLoggedIn() ? $auth->getUserData() : null,
            'browser' => $agent->browser(),
            'device' => $agent->device(),
            'platform' => $agent->platform(),
            'mobile' => $agent->isMobile(),
            'desktop' => $agent->isDesktop(),
            'phone' => $agent->isPhone(),
            'iphone' => $agent->isiPhone(),
            'poster_review_count' => $posterReviewCount,
        ];

        return array_merge($base, $extra);
    }
}
