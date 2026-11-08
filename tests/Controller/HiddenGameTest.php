<?php

namespace App\Tests\Controller;

use App\Entity\Game;
use App\Entity\User;
use App\Enum\GameStatus;
use App\Tests\Support\DatabaseWebTestCase;

/** Effets d'un jeu masqué par la modération côté joueurs et côté créateur. */
class HiddenGameTest extends DatabaseWebTestCase
{
    private User $owner;
    private Game $game;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = $this->createUser('owner@example.test', [], true, 'Olive', 'Martin');
        $this->game  = $this->createGame($this->owner, 'Titre secret du bébé');
        $this->game->setHiddenAt(new \DateTimeImmutable());
        $this->em->flush();
    }

    private function playUrl(string $suffix = ''): string
    {
        return sprintf('/fr/play/%s/%s%s', $this->game->getSlug(), $this->game->getToken(), $suffix);
    }

    public function testPlayersSeeNeitherTheTitleNorThePhoto(): void
    {
        $this->game->setImage('uploads/games/photo-douteuse.jpg');
        $this->em->flush();

        $crawler = $this->client->request('GET', $this->playUrl());

        $this->assertResponseIsSuccessful();
        $this->assertStringNotContainsString('Titre secret', $this->client->getResponse()->getContent());
        $this->assertStringNotContainsString('photo-douteuse', $this->client->getResponse()->getContent());
        $this->assertSelectorTextContains('h1', 'MyBabyGuessr');
    }

    public function testAHiddenGameCannotBePlayedOrRevealedPublicly(): void
    {
        foreach (['/go', '/done', '/reveal'] as $suffix) {
            $this->client->request('GET', $this->playUrl($suffix));
            $this->assertResponseIsSuccessful($suffix);
            $this->assertStringNotContainsString('Titre secret', $this->client->getResponse()->getContent(), $suffix);
        }

        // Même révélé, le contenu n'est plus public
        $this->game->setStatus(GameStatus::Revealed)->setAnswerName('Léa');
        $this->em->flush();
        $this->client->request('GET', $this->playUrl('/reveal'));
        $this->assertStringNotContainsString('Titre secret', $this->client->getResponse()->getContent());
    }

    public function testTheOwnerCannotReopenShareOrRevealAHiddenGame(): void
    {
        $this->game->setStatus(GameStatus::Closed);
        $this->em->flush();
        $this->loginAs($this->owner);
        $token = $this->game->getToken();

        $crawler = $this->client->request('GET', '/fr/games/' . $token);
        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('.flash-error');

        foreach (['/share', '/reveal', '/reveal/share'] as $suffix) {
            $this->client->request('GET', '/fr/games/' . $token . $suffix);
            $this->assertResponseRedirects('/fr/games/' . $token, null, $suffix);
        }

        // Réouverture : refusée, le statut ne change pas
        $page  = $this->client->request('GET', '/fr/games/' . $token);
        $form  = $page->filter('form[action$="/toggle-status"]');
        if ($form->count() > 0) {
            $this->client->request('POST', '/fr/games/' . $token . '/toggle-status', ['_token' => $form->filter('input[name="_token"]')->attr('value')]);
            $this->assertResponseRedirects('/fr/games/' . $token);
        }
        $this->em->clear();
        $this->assertSame(GameStatus::Closed, $this->em->find(Game::class, $this->game->getId())->getStatus());
    }

    public function testARestoredGameIsPlayableAgain(): void
    {
        $this->game->setHiddenAt(null);
        $this->em->flush();

        $this->client->request('GET', $this->playUrl());

        $this->assertResponseIsSuccessful();
        $this->assertStringContainsString('Titre secret', $this->client->getResponse()->getContent());
    }
}
