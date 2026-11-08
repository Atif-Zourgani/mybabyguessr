<?php

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class LegalPagesTest extends WebTestCase
{
    public function testFrenchPrivacyPolicyMentionsModeration(): void
    {
        $client = static::createClient();
        $client->request('GET', '/fr/privacy');

        $this->assertResponseIsSuccessful();
        $html = $client->getResponse()->getContent();
        $this->assertStringContainsString('Modération.', $html);
        $this->assertStringContainsString('Intérêt légitime et obligations légales de l\'hébergeur', $html);
        $this->assertStringContainsString('Les adresses email des joueurs ne sont pas affichées', $html);
        $this->assertStringContainsString('Journal de modération</strong> : conservé 12 mois', $html);
        $this->assertStringContainsString('date de dernière connexion', $html);
    }

    public function testEnglishPrivacyPolicyMentionsModeration(): void
    {
        $client = static::createClient();
        $client->request('GET', '/en/privacy');

        $this->assertResponseIsSuccessful();
        $html = $client->getResponse()->getContent();
        $this->assertStringContainsString('Moderation.', $html);
        $this->assertStringContainsString('Legitimate interest and legal obligations of a hosting provider', $html);
        $this->assertStringContainsString('Moderation log</strong>: retained for 12 months', $html);
    }

    public function testOtherLanguagesShowTheEnglishVersionWithTheFrenchNotice(): void
    {
        $client = static::createClient();
        $client->request('GET', '/de/privacy');

        $this->assertResponseIsSuccessful();
        $this->assertStringContainsString('Moderation.', $client->getResponse()->getContent());
    }
}
