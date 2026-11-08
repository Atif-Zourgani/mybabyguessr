<?php

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/** Garde-fous : ce qui a été allégé pour la vitesse des pages ne doit pas revenir par mégarde. */
class PagePerformanceTest extends WebTestCase
{
    private const ROOT = __DIR__ . '/../..';

    public function testHomePageLoadsNoThirdPartyResource(): void
    {
        $client = static::createClient();
        $client->request('GET', '/fr/');
        $html = $client->getResponse()->getContent();

        $this->assertStringNotContainsString('cdn.jsdelivr.net', $html);
        $this->assertDoesNotMatchRegularExpression('#<link[^>]+rel="stylesheet"[^>]+href="https?://#', $html);
    }

    public function testHeavyPhotoLibraryIsNotLoadedOnEveryPage(): void
    {
        $client = static::createClient();
        $client->request('GET', '/fr/');
        $html = $client->getResponse()->getContent();

        // heic2any pèse plus de 400 Ko : jamais de préchargement, et jamais d'import statique dans le script commun
        $this->assertDoesNotMatchRegularExpression('#rel="modulepreload"[^>]+heic2any#', $html);
        $app = (string) file_get_contents(self::ROOT . '/assets/app.js');
        $this->assertStringNotContainsString("import heic2any from", $app);
        $this->assertStringContainsString("import('heic2any')", $app);
    }

    public function testIllustrationsAreWebpAndLoadedLazilyBelowTheFold(): void
    {
        $this->assertSame([], glob(self::ROOT . '/assets/images/illustration/*.png') ?: [], 'les illustrations doivent rester en WebP');

        $client = static::createClient();
        $crawler = $client->request('GET', '/fr/');

        $illustrations = $crawler->filter('img[src*="/images/illustration/"]');
        $this->assertGreaterThan(5, $illustrations->count());
        foreach ($illustrations as $img) {
            $this->assertStringContainsString('.webp', $img->getAttribute('src'));
            $this->assertNotSame('', $img->getAttribute('width'), 'largeur déclarée pour éviter les décalages de mise en page');
            $this->assertNotSame('', $img->getAttribute('height'));
        }
        $this->assertGreaterThanOrEqual(5, $crawler->filter('img[src*="/images/illustration/"][loading="lazy"]')->count());
    }

    public function testEveryLanguageFlagIsHostedLocally(): void
    {
        foreach (['fr', 'gb', 'es', 'pt', 'de', 'it'] as $flag) {
            $this->assertFileExists(self::ROOT . "/assets/images/flags/$flag.svg");
        }
    }

    public function testStimulusDemoControllerIsGone(): void
    {
        $this->assertFileDoesNotExist(self::ROOT . '/assets/controllers/hello_controller.js');
    }
}
