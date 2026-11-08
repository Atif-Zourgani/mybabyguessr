<?php

namespace App\Tests\Controller;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/** Balises que les moteurs de recherche et les réseaux sociaux lisent sur les pages publiques. */
class SeoMarkupTest extends WebTestCase
{
    private const LOCALES = ['fr', 'en', 'es', 'pt', 'de', 'it'];

    public static function locales(): iterable
    {
        foreach (self::LOCALES as $locale) {
            yield $locale => [$locale];
        }
    }

    /** @return list<array<string, mixed>> */
    private function jsonLd(string $html): array
    {
        preg_match_all('#<script type="application/ld\+json">(.*?)</script>#s', $html, $matches);
        $blocks = [];
        foreach ($matches[1] as $json) {
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
            foreach (array_is_list($data) ? $data : [$data] as $item) {
                $blocks[] = $item;
            }
        }

        return $blocks;
    }

    #[DataProvider('locales')]
    public function testHomePageStructuredDataIsValidJsonWithTheExpectedTypes(string $locale): void
    {
        $client = static::createClient();
        $client->request('GET', "/$locale/");

        $types = array_column($this->jsonLd($client->getResponse()->getContent()), '@type');

        $this->assertEqualsCanonicalizing(['Organization', 'WebSite', 'WebApplication', 'FAQPage'], $types);
    }

    public function testOrganizationLogoSizeMatchesTheRealFile(): void
    {
        $client = static::createClient();
        $client->request('GET', '/fr/');
        $organization = array_values(array_filter($this->jsonLd($client->getResponse()->getContent()), fn ($b) => $b['@type'] === 'Organization'))[0];

        [$width, $height] = getimagesize(__DIR__ . '/../../assets/images/logo-mybabyguessr.png');
        $this->assertSame($width, $organization['logo']['width']);
        $this->assertSame($height, $organization['logo']['height']);
    }

    public function testWebApplicationIsDeclaredAsFree(): void
    {
        $client = static::createClient();
        $client->request('GET', '/en/');
        $app = array_values(array_filter($this->jsonLd($client->getResponse()->getContent()), fn ($b) => $b['@type'] === 'WebApplication'))[0];

        $this->assertTrue($app['isAccessibleForFree']);
        $this->assertSame('0', $app['offers']['price']);
        $this->assertSame('en-GB', $app['inLanguage']);
    }

    #[DataProvider('locales')]
    public function testSocialImageIsPerLanguageAndLargeCard(string $locale): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', "/$locale/");

        $this->assertStringEndsWith("og-$locale" . '.png', $this->stripHash($crawler->filter('meta[property="og:image"]')->attr('content')));
        $this->assertSame('summary_large_image', $crawler->filter('meta[name="twitter:card"]')->attr('content'));
        $this->assertSame($crawler->filter('meta[property="og:image"]')->attr('content'), $crawler->filter('meta[name="twitter:image"]')->attr('content'));
        $this->assertSame('1200', $crawler->filter('meta[property="og:image:width"]')->attr('content'));
        $this->assertFileExists(__DIR__ . "/../../assets/images/og/og-$locale.png");
        $this->assertSame([1200, 630], array_slice(getimagesize(__DIR__ . "/../../assets/images/og/og-$locale.png"), 0, 2));
    }

    #[DataProvider('locales')]
    public function testHomeCanonicalAndAlternates(string $locale): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', "/$locale/");

        $this->assertStringEndsWith("/$locale/", $crawler->filter('link[rel="canonical"]')->attr('href'));
        $alternates = $crawler->filter('link[rel="alternate"][hreflang]')->each(fn ($n) => $n->attr('hreflang'));
        $this->assertEqualsCanonicalizing(['fr', 'en', 'es', 'pt', 'de', 'it', 'x-default'], $alternates);
        $this->assertSame('index, follow', $crawler->filter('meta[name="robots"]')->attr('content'));
    }

    /** Les adresses d'images sont fingerprintées (« og-fr-AbC123.png ») : on retire l'empreinte pour comparer. */
    private function stripHash(string $url): string
    {
        return preg_replace('/-[A-Za-z0-9_-]{7}(\.png)$/', '$1', $url);
    }
}
