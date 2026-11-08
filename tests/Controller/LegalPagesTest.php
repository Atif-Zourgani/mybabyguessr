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
    }

    public function testEnglishPrivacyPolicyMentionsModeration(): void
    {
        $client = static::createClient();
        $client->request('GET', '/en/privacy');

        $this->assertResponseIsSuccessful();
        $html = $client->getResponse()->getContent();
        $this->assertStringContainsString('Moderation.', $html);
        $this->assertStringContainsString('Legitimate interest and legal obligations of a hosting provider', $html);
    }

    public function testOtherLanguagesShowTheEnglishVersionWithTheFrenchNotice(): void
    {
        $client = static::createClient();
        $client->request('GET', '/de/privacy');

        $this->assertResponseIsSuccessful();
        $this->assertStringContainsString('Moderation.', $client->getResponse()->getContent());
    }
}
