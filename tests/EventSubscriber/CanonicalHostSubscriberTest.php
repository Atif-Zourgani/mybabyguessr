<?php

namespace App\Tests\EventSubscriber;

use App\EventSubscriber\CanonicalHostSubscriber;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

class CanonicalHostSubscriberTest extends TestCase
{
    private const CANONICAL = 'https://mybabyguessr.com';

    private function handle(string $url, string $method = 'GET', array $server = [], string $canonical = self::CANONICAL, int $type = HttpKernelInterface::MAIN_REQUEST): ?RedirectResponse
    {
        $request = Request::create($url, $method, [], [], [], $server);
        $event   = new RequestEvent($this->createStub(HttpKernelInterface::class), $request, $type);
        (new CanonicalHostSubscriber($canonical))->onKernelRequest($event);

        return $event->getResponse();
    }

    public static function nonCanonicalAddresses(): iterable
    {
        yield 'http' => ['http://mybabyguessr.com/fr/', 'https://mybabyguessr.com/fr/'];
        yield 'www en https' => ['https://www.mybabyguessr.com/fr/', 'https://mybabyguessr.com/fr/'];
        yield 'www en http' => ['http://www.mybabyguessr.com/fr/', 'https://mybabyguessr.com/fr/'];
        yield 'autre nom de domaine' => ['https://ancien-nom.example/fr/', 'https://mybabyguessr.com/fr/'];
        yield 'chemin et paramètres conservés' => ['http://www.mybabyguessr.com/fr/play/baby/abc?utm=1&x=2', 'https://mybabyguessr.com/fr/play/baby/abc?utm=1&x=2'];
        yield 'racine' => ['http://mybabyguessr.com/', 'https://mybabyguessr.com/'];
    }

    #[DataProvider('nonCanonicalAddresses')]
    public function testRedirectsPermanentlyToTheOfficialAddress(string $from, string $to): void
    {
        $response = $this->handle($from, 'GET', str_starts_with($from, 'https') ? ['HTTPS' => 'on'] : []);

        $this->assertNotNull($response);
        $this->assertSame(301, $response->getStatusCode());
        $this->assertSame($to, $response->headers->get('Location'));
    }

    public function testHeadRequestsAreRedirectedToo(): void
    {
        $this->assertSame(301, $this->handle('http://www.mybabyguessr.com/fr/', 'HEAD')?->getStatusCode());
    }

    public function testTheOfficialAddressIsLeftAlone(): void
    {
        $this->assertNull($this->handle('https://mybabyguessr.com/fr/', 'GET', ['HTTPS' => 'on']));
    }

    public function testFormSubmissionsAreNeverRedirected(): void
    {
        $this->assertNull($this->handle('http://www.mybabyguessr.com/fr/login', 'POST'));
    }

    public function testSubRequestsAreIgnored(): void
    {
        $this->assertNull($this->handle('http://www.mybabyguessr.com/fr/', 'GET', [], self::CANONICAL, HttpKernelInterface::SUB_REQUEST));
    }

    public function testNothingHappensWhenNoOfficialAddressIsConfigured(): void
    {
        // Développement et tests : aucune redirection
        $this->assertNull($this->handle('http://127.0.0.1:8000/fr/', 'GET', [], ''));
    }

    public function testNoRedirectLoopBehindAnEncryptionRelayThatSymfonyDoesNotTrust(): void
    {
        // Symfony voit « http » (relais non déclaré fiable) mais le visiteur est bien en https
        $this->assertNull($this->handle('http://mybabyguessr.com/fr/', 'GET', ['HTTP_X_FORWARDED_PROTO' => 'https']));
    }

    public function testTrailingSlashOfTheConfiguredAddressIsIgnored(): void
    {
        $this->assertNull($this->handle('https://mybabyguessr.com/fr/', 'GET', ['HTTPS' => 'on'], 'https://mybabyguessr.com/'));
    }
}
