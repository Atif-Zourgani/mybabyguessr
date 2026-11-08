<?php

namespace App\Tests\Controller;

use App\Entity\Game;
use App\Entity\Guess;
use App\Enum\AnswerGender;
use App\Enum\GameStatus;
use App\Enum\NameMode;
use App\Tests\Support\DatabaseWebTestCase;

/**
 * Non-régression : les parcours invité et organisateur d'un jeu normal (non masqué) restent intacts.
 */
class PlayerJourneyTest extends DatabaseWebTestCase
{
    private function playUrl(Game $game, string $suffix = ''): string
    {
        return sprintf('/fr/play/%s/%s%s', $game->getSlug(), $game->getToken(), $suffix);
    }

    private function newFreeGame(): Game
    {
        $owner = $this->createUser('owner@example.test', [], true, 'Olive', 'Martin');
        $game  = $this->createGame($owner, 'Bébé Martin');
        $game->setGuessName(true)->setNameMode(NameMode::Free)->setGuessWeight(true);
        $this->em->flush();

        return $game;
    }

    /** Identification de l'invité : retourne le jeton du formulaire de pronostic. */
    private function identify(Game $game, string $name = 'Alice'): string
    {
        $title = $game->getDisplayTitle();
        // Le premier appel d'un test réutilise le gestionnaire de données déjà chargé : on repart de la base
        $this->em->clear();

        $crawler = $this->client->request('GET', $this->playUrl($game));
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', $title);

        $form = $crawler->filter('input[name="player_name"]')->ancestors()->filter('form')->form(['player_name' => $name]);
        $this->client->submit($form);
        $this->assertResponseRedirects($this->playUrl($game, '/go'));

        $crawler = $this->client->followRedirect();
        $this->assertResponseIsSuccessful();

        return $crawler->filter('input[name="_token"]')->first()->attr('value');
    }

    public function testGuestPlaysAFreeGameFromInvitationToConfirmation(): void
    {
        $game  = $this->newFreeGame();
        $token = $this->identify($game);

        $this->client->request('POST', $this->playUrl($game, '/go'), [
            '_token'       => $token,
            'guess_gender' => 'girl',
            'guess_name'   => 'Léa',
            'guess_weight' => '3,4',
        ]);
        $this->assertResponseRedirects($this->playUrl($game, '/done'));

        $this->client->followRedirect();
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'Alice');

        $this->em->clear();
        $guesses = $this->em->getRepository(Guess::class)->findAll();
        $this->assertCount(1, $guesses);
        $this->assertSame('Alice', $guesses[0]->getPlayerName());
        $this->assertSame(AnswerGender::Girl, $guesses[0]->getGuessGender());
        $this->assertSame('Léa', $guesses[0]->getGuessName());
        $this->assertSame(3400, $guesses[0]->getGuessWeight());
    }

    public function testASecondSubmissionFromTheSameBrowserDoesNotAddAGuess(): void
    {
        $game  = $this->newFreeGame();
        $token = $this->identify($game);
        $data  = ['_token' => $token, 'guess_gender' => 'girl', 'guess_name' => 'Léa', 'guess_weight' => '3,4'];

        $this->client->request('POST', $this->playUrl($game, '/go'), $data);
        $this->client->request('POST', $this->playUrl($game, '/go'), $data);

        $this->assertResponseRedirects($this->playUrl($game, '/done'));
        $this->assertCount(1, $this->em->getRepository(Guess::class)->findAll());
    }

    public function testInvalidGuessesAreRejectedWithTheFormShownAgain(): void
    {
        $game  = $this->newFreeGame();
        $token = $this->identify($game);

        $this->client->request('POST', $this->playUrl($game, '/go'), ['_token' => $token, 'guess_name' => '', 'guess_weight' => '99']);

        $this->assertResponseStatusCodeSame(422);
        $this->assertCount(0, $this->em->getRepository(Guess::class)->findAll());
    }

    public function testHintsModeStopsAtTheRightNameAndRecordsTheTries(): void
    {
        $owner = $this->createUser('owner@example.test');
        $game  = $this->createGame($owner, 'Mode indices');
        $game->setGuessName(true)->setNameMode(NameMode::Hints)->setAnswerName('Léa');
        $this->em->flush();
        $token = $this->identify($game);

        $this->client->request('POST', $this->playUrl($game, '/hint/1'), ['name' => 'Zoé']);
        $first = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertFalse($first['done']);
        $this->assertNotEmpty($first['clue']);

        $this->client->request('POST', $this->playUrl($game, '/hint/2'), ['name' => 'lea']);
        $this->assertSame(['done' => true, 'correct' => true], json_decode($this->client->getResponse()->getContent(), true));

        // Plus d'essai possible une fois le prénom trouvé
        $this->client->request('POST', $this->playUrl($game, '/hint/3'), ['name' => 'Autre']);
        $this->assertResponseStatusCodeSame(400);

        $this->client->request('POST', $this->playUrl($game, '/go'), ['_token' => $token, 'guess_gender' => 'boy']);
        $this->assertResponseRedirects($this->playUrl($game, '/done'));

        $guess = $this->em->getRepository(Guess::class)->findOneBy([]);
        $this->assertSame(2, $guess->getNameHintsUsed());
        $this->assertSame('lea', $guess->getGuessName());
    }

    public function testClosedGamesStillShowTheClosedPageWithTheirTitle(): void
    {
        $game = $this->newFreeGame();
        $game->setStatus(GameStatus::Closed);
        $this->em->flush();
        $this->em->clear();

        $this->client->request('GET', $this->playUrl($game));

        $this->assertResponseIsSuccessful();
        $this->assertStringContainsString('Bébé Martin', $this->client->getResponse()->getContent());
    }

    public function testRevealedGamePublicPageListsTheWinners(): void
    {
        $game = $this->newFreeGame();
        $game->setGuessGender(true)->setGuessName(true)->setAnswerGender(AnswerGender::Girl)->setAnswerName('Léa')->setStatus(GameStatus::Revealed);
        $winner = $this->createGuess($game, 'Gagnante');
        $winner->setGuessGender(AnswerGender::Girl)->setGuessName('lea');
        $loser = $this->createGuess($game, 'Perdant');
        $loser->setGuessGender(AnswerGender::Boy)->setGuessName('Zoé');
        $this->em->flush();
        $this->em->clear();

        $this->client->request('GET', $this->playUrl($game, '/reveal'));

        $this->assertResponseIsSuccessful();
        $html = $this->client->getResponse()->getContent();
        $this->assertStringContainsString('Gagnante', $html);
        $this->assertStringContainsString('Bébé Martin', $html);
    }

    public function testOrganizerJourneyDashboardCreationShareAndReveal(): void
    {
        $game  = $this->newFreeGame();
        $owner = $game->getUser();
        $this->loginAs($owner);

        foreach (['/fr/dashboard', '/fr/games/new', '/fr/games/' . $game->getToken(), '/fr/games/' . $game->getToken() . '/share'] as $url) {
            $this->client->request('GET', $url);
            $this->assertResponseIsSuccessful($url);
        }

        $crawler = $this->client->request('GET', '/fr/games/' . $game->getToken() . '/reveal');
        $this->assertResponseIsSuccessful();
        $this->client->request('POST', '/fr/games/' . $game->getToken() . '/reveal', [
            '_csrf_token' => $crawler->filter('input[name="_csrf_token"]')->attr('value'),
            'answerName'  => 'Léa',
        ]);
        $this->assertResponseRedirects('/fr/games/' . $game->getToken() . '/reveal/share');

        $this->em->clear();
        $this->assertSame(GameStatus::Revealed, $this->em->find(Game::class, $game->getId())->getStatus());
    }
}
