<?php

declare(strict_types=1);

namespace Mk\Framework;

use Mk\Framework\Jellyseerr\RequestLink;

class Pager
{

    public static function getPage(): ?string
    {
        return Main::captureGetString('page') ?? null;
    }

    public static function getCategory(): ?string
    {
        return Main::captureGetString('category') ?? null;
    }


    public static function homePage(): never
    {
        header('Location: /' . HOMEPAGE);
        exit();
    }

    public static function login(?int $seerrRequestId = null): never
    {
        $query = $seerrRequestId !== null ? '?seerr_request=' . $seerrRequestId : '';
        header('Location: /' . LOGIN_PAGE . $query);
        exit();
    }

    public static function afterLogin(?int $seerrRequestId): never
    {
        if ($seerrRequestId !== null) {
            header('Location: ' . RequestLink::path($seerrRequestId));
            exit();
        }
        self::homePage();
    }



}
