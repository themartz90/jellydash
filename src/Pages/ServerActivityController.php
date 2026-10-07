<?php

declare(strict_types=1);

namespace Mk\Framework\Pages;

use Mk\Framework\Authorization;
use Mk\Framework\Controller;

final class ServerActivityController extends Controller
{
    public function handle(): void
    {
        $allowed = (new Authorization())->can(Authorization::CAPABILITY_MANAGE_GLOBAL);
        if (!$allowed) {
            http_response_code(403);
        }
        $this->render('server-activity/index', [
            'layout' => $this->layout(['title' => 'Server Activity', 'page' => 'server-activity', 'hide_footer' => true]),
            'can_view_activity' => $allowed,
        ]);
    }
}
