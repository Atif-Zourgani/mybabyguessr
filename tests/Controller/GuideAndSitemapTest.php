<?php

namespace App\Tests\Controller;

use App\Guide\GuideCatalog;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

class GuideAndSitemapTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
    }

    private function path(string $url): string
    {
        return (string) parse_url($url, PHP_URL_PATH);
    }

    // ── Catalogue ────────────────────────────────────────────────────────

    public function testCatalogIsConsistent(): void
    {
        $catalog = new GuideCatalog();
        $slugs   = [];

        foreach ($catalog->all() as $page) {
            $this->assertFileExists(__DIR__ . '/../../templates/' . $page->template(), "gabarit de {$page->id} ({$page->locale})");
            $this->assertLessThanOrEqual(60, mb_strlen($page->title), "titre trop long : {$page->title}");
            $this->assertGreaterThanOrEqual(110, mb_strlen($page->description), "description trop courte : {$page->slug}");
            $this->assertLessThanOrEqual(165, mb_strlen($page->description), "description trop longue : {$page->slug}");
            $this->assertMatchesRegularExpression('/^[a-z0-9-]+$/', $page->slug);
            $this->assertArrayNotHasKey($page->locale . '/' . $page->slug, $slugs, 'adresse en double');
            $slugs[$page->locale . '/' . $page->slug] = true;
        }

        $this->assertCount(6, $catalog->all(), '3 guides en français et en anglais');
        $this->assertSame([], $catalog->forLocale('es'));
    }

    public function testEveryGuideHasEnoughContent(): void
    {
        foreach ((new GuideCatalog())->all() as $page) {
            $crawler = $this->client->request('GET', "/{$page->locale}/guide/{$page->slug}");
            $text    = implode(' ', $crawler->filter('.guide')->each(fn (Crawler $n) => $n->text()));
            $this->assertGreaterThan(380, count(preg_split('/\s+/u', trim($text))), "contenu trop mince : {$page->slug}");
        }
    }

    // ── Pages de guides ──────────────────────────────────────────────────

    public static function guides(): iterable
    {
        foreach ((new GuideCatalog())->all() as $page) {
            yield "{$page->locale} {$page->id}" => [$page->locale, $page->slug, $page->title, $page->heading];
        }
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('guides')]
    public function testGuidePageIsIndexableAndWellFormed(string $locale, string $slug, string $title, string $heading): void
    {
        $crawler = $this->client->request('GET', "/$locale/guide/$slug");

        $this->assertResponseIsSuccessful();
        $this->assertSame($title, trim($crawler->filter('title')->text()));
        $this->assertSame('index, follow', $crawler->filter('meta[name="robots"]')->attr('content'));
        $this->assertSame("/$locale/guide/$slug", $this->path($crawler->filter('link[rel="canonical"]')->attr('href')));
        $this->assertCount(1, $crawler->filter('h1'));
        $this->assertSame($heading, trim($crawler->filter('h1')->text()));
        $this->assertGreaterThanOrEqual(4, $crawler->filter('.guide h2')->count());
        $this->assertSame($title, $crawler->filter('meta[property="og:title"]')->attr('content'));
        $this->assertSame(1, $crawler->filter('html[lang="' . $locale . '"]')->count());
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('guides')]
    public function testGuideStructuredDataIsValid(string $locale, string $slug, string $title, string $heading): void
    {
        $this->client->request('GET', "/$locale/guide/$slug");
        preg_match_all('#<script type="application/ld\+json">(.*?)</script>#s', $this->client->getResponse()->getContent(), $m);

        $types = [];
        foreach ($m[1] as $json) {
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
            foreach (array_is_list($data) ? $data : [$data] as $item) {
                $types[$item['@type']] = $item;
            }
        }

        $this->assertEqualsCanonicalizing(['Organization', 'Article', 'BreadcrumbList'], array_keys($types));
        $this->assertSame('2026-11-08', $types['Article']['datePublished']);
        $this->assertSame(2, count($types['BreadcrumbList']['itemListElement']));
    }

    public function testHreflangOnlyListsTheLanguagesWhereTheGuideExists(): void
    {
        $crawler = $this->client->request('GET', '/fr/guide/jeu-baby-shower-pronostics');
        $alternates = [];
        foreach ($crawler->filter('link[rel="alternate"][hreflang]') as $link) {
            $alternates[$link->getAttribute('hreflang')] = $this->path($link->getAttribute('href'));
        }

        $this->assertEquals([
            'fr'        => '/fr/guide/jeu-baby-shower-pronostics',
            'en'        => '/en/guide/baby-shower-guessing-game',
            'x-default' => '/fr/guide/jeu-baby-shower-pronostics',
        ], $alternates);

        foreach (['/fr/guide/jeu-baby-shower-pronostics', '/en/guide/baby-shower-guessing-game'] as $path) {
            $this->client->request('GET', $path);
            $this->assertResponseIsSuccessful();
        }
    }

    public function testLanguageSwitcherPointsToTheTranslatedGuideOrToTheHome(): void
    {
        $crawler = $this->client->request('GET', '/fr/guide/jeu-gender-reveal');

        $links = [];
        foreach ($crawler->filter('a[hreflang]') as $link) {
            $links[$link->getAttribute('hreflang')] = $this->path($link->getAttribute('href'));
        }

        $this->assertSame('/en/guide/gender-reveal-guessing-game', $links['en']);
        $this->assertSame('/es/', $links['es'], 'pas de guide en espagnol : on envoie vers l\'accueil, jamais vers une erreur 404');
        $this->assertSame('/de/', $links['de']);
    }

    public function testUnknownOrWrongLanguageAddressesAreNotFound(): void
    {
        foreach (['/fr/guide/nimporte-quoi', '/fr/guide/baby-prediction-game', '/en/guide/pronostic-naissance', '/es/guide/pronostic-naissance'] as $path) {
            $this->client->request('GET', $path);
            $this->assertResponseStatusCodeSame(404, $path);
        }
    }

    public function testGuideContentLinksAllWork(): void
    {
        foreach ((new GuideCatalog())->all() as $page) {
            $crawler = $this->client->request('GET', "/{$page->locale}/guide/{$page->slug}");
            foreach ($crawler->filter('.guide a[href^="/"]')->each(fn (Crawler $a) => $a->attr('href')) as $href) {
                $this->client->request('GET', $href);
                $this->assertContains($this->client->getResponse()->getStatusCode(), [200, 302], "lien cassé dans {$page->slug} : $href");
            }
        }
    }

    // ── Maillage interne ─────────────────────────────────────────────────

    public function testHomeAndFooterLinkToTheGuidesOnlyWhereTheyExist(): void
    {
        $crawler = $this->client->request('GET', '/fr/');
        $this->assertCount(3, $crawler->filter('#guides-title ~ div a[href*="/guide/"]'));
        $this->assertGreaterThanOrEqual(3, $crawler->filter('footer a[href*="/guide/"]')->count());

        $crawler = $this->client->request('GET', '/en/');
        $this->assertCount(3, $crawler->filter('#guides-title ~ div a[href*="/guide/"]'));

        $crawler = $this->client->request('GET', '/es/');
        $this->assertCount(0, $crawler->filter('a[href*="/guide/"]'));
        $this->assertCount(0, $crawler->filter('#guides-title'));
    }

    // ── Sitemap ──────────────────────────────────────────────────────────

    /** @return array<string, array{lastmod: string, alternates: array<string, string>}> */
    private function sitemap(): array
    {
        $this->client->request('GET', '/sitemap.xml');
        $this->assertResponseIsSuccessful();
        $this->assertStringContainsString('application/xml', $this->client->getResponse()->headers->get('Content-Type'));

        $xml = new \SimpleXMLElement($this->client->getResponse()->getContent());
        $xml->registerXPathNamespace('s', 'http://www.sitemaps.org/schemas/sitemap/0.9');
        $out = [];
        foreach ($xml->xpath('//s:url') as $url) {
            $url->registerXPathNamespace('s', 'http://www.sitemaps.org/schemas/sitemap/0.9');
            $url->registerXPathNamespace('x', 'http://www.w3.org/1999/xhtml');
            $alternates = [];
            foreach ($url->xpath('x:link') as $link) {
                $alternates[(string) $link['hreflang']] = (string) $link['href'];
            }
            $out[(string) $url->loc] = ['lastmod' => (string) $url->lastmod, 'alternates' => $alternates];
        }

        return $out;
    }

    public function testSitemapListsEveryHomeAndGuideWithADate(): void
    {
        $entries = $this->sitemap();

        $this->assertCount(12, $entries, '6 accueils et 6 guides');
        foreach ($entries as $loc => $entry) {
            $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $entry['lastmod'], $loc);
            $this->assertArrayHasKey('x-default', $entry['alternates']);
            $this->assertContains($loc, $entry['alternates'], 'chaque page se cite elle-même parmi ses alternatives');
        }
        foreach (['fr', 'en', 'es', 'pt', 'de', 'it'] as $locale) {
            $this->assertContains("/$locale/", array_map(fn ($u) => $this->path($u), array_keys($entries)));
        }
    }

    public function testEverySitemapUrlIsIndexableAndItsOwnCanonical(): void
    {
        foreach (array_keys($this->sitemap()) as $loc) {
            $path    = $this->path($loc);
            $crawler = $this->client->request('GET', $path);

            $this->assertResponseStatusCodeSame(200, $path);
            $this->assertStringNotContainsString('noindex', $crawler->filter('meta[name="robots"]')->attr('content'), "$path est dans le sitemap mais en noindex");
            $this->assertSame($path, $this->path($crawler->filter('link[rel="canonical"]')->attr('href')), "$path : canonique différente");
        }
    }

    public function testGuideLinkColourDoesNotHideButtonLabels(): void
    {
        // Régression constatée : la couleur des liens du guide rendait le texte du bouton invisible (brun sur brun)
        $css = (string) file_get_contents(__DIR__ . '/../../assets/styles/guide.css');

        $this->assertStringContainsString('.guide a:not(.btn)', $css);
        $this->assertDoesNotMatchRegularExpression('/^\.guide a\s*\{/m', $css);
    }

    public function testRobotsTxtPointsToTheGeneratedSitemapAndDoesNotBlockGuides(): void
    {
        $robots = (string) file_get_contents(__DIR__ . '/../../public/robots.txt');

        $this->assertStringContainsString('Sitemap: https://mybabyguessr.com/sitemap.xml', $robots);
        $this->assertStringNotContainsString('/guide', $robots);
        $this->assertFileDoesNotExist(__DIR__ . '/../../public/sitemap.xml', 'le fichier statique masquerait le sitemap généré');
    }
}
