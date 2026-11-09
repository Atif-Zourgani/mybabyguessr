<?php

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Yaml\Yaml;

/**
 * Les noms de site de confiance ne sont déclarés qu'en production : on lit donc cette configuration et on la
 * fait appliquer au noyau de test, pour vérifier qu'elle accepte le vrai site (avec et sans « www ») et refuse
 * tout autre nom.
 */
class TrustedHostsTest extends WebTestCase
{
    protected function tearDown(): void
    {
        Request::setTrustedHosts([]);
        parent::tearDown();
    }

    /** @return list<string> */
    private function productionTrustedHosts(): array
    {
        $config = Yaml::parseFile(__DIR__ . '/../../config/packages/framework.yaml');

        return $config['when@prod']['framework']['trusted_hosts'] ?? [];
    }

    public function testProductionDeclaresTheTrustedHosts(): void
    {
        $hosts = $this->productionTrustedHosts();

        $this->assertNotSame([], $hosts, 'trusted_hosts doit être déclaré en production');
        $this->assertTrue($this->anyMatches($hosts, 'mybabyguessr.com'));
        $this->assertTrue($this->anyMatches($hosts, 'www.mybabyguessr.com'), 'le « www » doit passer, sinon sa redirection devient une erreur 400');
        $this->assertFalse($this->anyMatches($hosts, 'evil.example'));
        $this->assertFalse($this->anyMatches($hosts, 'mybabyguessr.com.evil.example'), 'le motif doit être ancré des deux côtés');
        $this->assertFalse($this->anyMatches($hosts, 'evilmybabyguessr.com'));
    }

    public function testTheRealSiteIsServedAndAForgedHostIsRefused(): void
    {
        $client = static::createClient();
        Request::setTrustedHosts($this->productionTrustedHosts());

        foreach (['mybabyguessr.com', 'www.mybabyguessr.com'] as $host) {
            $client->request('GET', '/fr/', [], [], ['HTTP_HOST' => $host]);
            $this->assertResponseIsSuccessful($host);
        }

        $client->request('GET', '/fr/', [], [], ['HTTP_HOST' => 'evil.example']);
        $this->assertResponseStatusCodeSame(400);
    }

    /** @param list<string> $patterns */
    private function anyMatches(array $patterns, string $host): bool
    {
        foreach ($patterns as $pattern) {
            if (preg_match('{' . $pattern . '}i', $host)) {
                return true;
            }
        }

        return false;
    }
}
