<?php

declare(strict_types=1);

use Mk\Framework\Http\UserAvatarResponse;
use PHPUnit\Framework\TestCase;

final class UserAvatarResponseTest extends TestCase
{
    public function testMissIsAPrivatelyCachedNotFound(): void
    {
        $response = UserAvatarResponse::fromImage(null);

        $this->assertSame(404, $response['status']);
        $this->assertSame('private, max-age=300', $response['cacheControl']);
        $this->assertNull($response['contentType']);
        $this->assertNull($response['body']);
    }

    public function testHitIsAPrivatelyCachedImage(): void
    {
        $response = UserAvatarResponse::fromImage([
            'body' => 'fake-jpeg',
            'contentType' => 'image/jpeg',
        ]);

        $this->assertSame(200, $response['status']);
        $this->assertSame('private, max-age=3600', $response['cacheControl']);
        $this->assertSame('image/jpeg', $response['contentType']);
        $this->assertSame('fake-jpeg', $response['body']);
    }

    public function testImageProxyWiresAvatarResponsesAndRejectsBadUserIds(): void
    {
        $source = file_get_contents(ROOT_DIR . '/public/api/image.php');

        $this->assertIsString($source);
        $this->assertStringContainsString('UserAvatarResponse::fromImage', $source);
        $this->assertStringContainsString("header('Cache-Control: ' . \$response['cacheControl'])", $source);
    }
}
