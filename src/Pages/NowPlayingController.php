<?php

declare(strict_types=1);

namespace Mk\Framework\Pages;

use Mk\Framework\Controller;

final class NowPlayingController extends Controller
{
    public function handle(): void
    {
        $this->render('now_playing/index', [
            'layout' => $this->layout([
                'title' => 'Now Playing',
                'page' => 'now-playing',
                'header_class' => 'dashboard-header-now-playing',
                'content_class' => 'dashboard-content-now-playing',
                'hide_footer' => true,
            ]),
            'is_loading' => true,
            'streams' => [],
            'hidden_count' => 0,
            'hidden_sources' => '',
            'stats' => [
                'watch_today' => 'Unavailable',
                'watch_today_available' => false,
                'collection_status' => 'checking',
                'metadata_available' => false,
                'active_streams' => 0,
                'active_users' => 0,
                'bandwidth_mbps' => '0.0',
                'transcodes' => 0,
            ],
        ]);
    }
}
