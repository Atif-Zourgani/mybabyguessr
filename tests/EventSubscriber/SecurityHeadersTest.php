<?php

namespace App\Tests\EventSubscriber;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class SecurityHeadersTest extends WebTestCase
{
    public function testCommonSecurityHeadersAreSent(): void
    {
        $client = static::createClient();
        $client->request('GET', '/fr/');

        $this->assertResponseHeaderSame('X-Content-Type-Options', 'nosniff');
        $this->assertResponseHeaderSame('Referrer-Policy', 'strict-origin-when-cross-origin');
        $this->assertResponseHeaderSame('X-Frame-Options', 'SAMEORIGIN');
        $this->assertResponseHasHeader('Permissions-Policy');
    }

    public function testHstsIsOnlySentOverHttps(): void
    {
        $client = static::createClient();

        $client->request('GET', '/fr/');
        $this->assertResponseNotHasHeader('Strict-Transport-Security');

        $client->request('GET', '/fr/', [], [], ['HTTPS' => 'on']);
        $this->assertResponseHeaderSame('Strict-Transport-Security', 'max-age=31536000');
    }

    public function testErrorPagesGetTheHeadersToo(): void
    {
        $client = static::createClient();
        $client->request('GET', '/fr/page-qui-n-existe-pas');

        $this->assertResponseStatusCodeSame(404);
        $this->assertResponseHeaderSame('X-Content-Type-Options', 'nosniff');
    }
}
