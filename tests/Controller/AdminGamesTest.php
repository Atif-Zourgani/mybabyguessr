<?php

namespace App\Tests\Controller;

use App\Entity\Game;
use App\Entity\ModerationLog;
use App\Entity\User;
use App\Enum\GameStatus;
use App\Enum\ModerationAction;
use App\Tests\Support\DatabaseWebTestCase;

class AdminGamesTest extends DatabaseWebTestCase
{
    private User $admin;
    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->createUser('admin@example.test', ['ROLE_ADMIN']);
        $this->owner = $this->createUser('owner@example.test', [], true, 'Olive', 'Martin');
        $this->loginAs($this->admin);
    }

    /** @return list<string> titres affichés dans la liste */
    private function listedTitles(string $query = ''): array
    {
        $crawler = $this->client->request('GET', '/fr/admin/games' . $query);
        $this->assertResponseIsSuccessful();

        return $crawler->filter('tbody tr td[data-label="Jeu"] .adm-cell-main')->each(fn ($n) => trim($n->text()));
    }

    private function reload(Game $game): Game
    {
        $this->em->clear();

        return $this->em->find(Game::class, $game->getId());
    }

    private function moderationToken(Game $game): string
    {
        $crawler = $this->client->request('GET', '/fr/admin/games');

        return $crawler->filter('form[action$="/games/' . $game->getId() . '/hide"] input[name="_token"], form[action$="/games/' . $game->getId() . '/restore"] input[name="_token"]')->attr('value');
    }

    // ── Liste ────────────────────────────────────────────────────────────

    public function testSearchByTitleOrOwner(): void
    {
        $this->createGame($this->owner, 'Bébé Martin');
        $this->createGame($this->createUser('autre@example.test', [], true, 'Zoé', 'Durand'), 'Petit Durand');

        $this->assertSame(['Petit Durand'], $this->listedTitles('?q=durand'));
        $this->assertSame(['Bébé Martin'], $this->listedTitles('?q=owner%40example'));
        $this->assertSame([], $this->listedTitles('?q=inconnu'));
    }

    public function testStatusAndVisibilityFilters(): void
    {
        $open     = $this->createGame($this->owner, 'Ouvert');
        $revealed = $this->createGame($this->owner, 'Révélé');
        $revealed->setStatus(GameStatus::Revealed);
        $hidden = $this->createGame($this->owner, 'Caché');
        $hidden->setHiddenAt(new \DateTimeImmutable());
        $this->em->flush();

        $this->assertSame(['Révélé'], $this->listedTitles('?status=revealed'));
        $this->assertSame(['Caché'], $this->listedTitles('?visibility=hidden'));
        $this->assertEqualsCanonicalizing(['Ouvert', 'Révélé'], $this->listedTitles('?visibility=visible'));
        // Statut inconnu : ignoré plutôt qu'une erreur
        $this->assertCount(3, $this->listedTitles('?status=nimporte'));
    }

    public function testSortByParticipationsShowsCounts(): void
    {
        $quiet = $this->createGame($this->owner, 'Calme');
        $busy  = $this->createGame($this->owner, 'Animé');
        $this->createGuess($busy, 'A');
        $this->createGuess($busy, 'B');
        $this->createGuess($quiet, 'C');

        $this->assertSame(['Animé', 'Calme'], $this->listedTitles('?sort=guesses&dir=desc'));
        $this->assertSame(['Calme', 'Animé'], $this->listedTitles('?sort=guesses&dir=asc'));

        $crawler = $this->client->request('GET', '/fr/admin/games?sort=guesses&dir=desc');
        $this->assertSame(['2', '1'], $crawler->filter('tbody tr td[data-label="Part."]')->each(fn ($n) => trim($n->text())));
    }

    public function testPaginationSplitsLongLists(): void
    {
        for ($i = 1; $i <= 27; $i++) {
            $this->createGame($this->owner, sprintf('Jeu %02d', $i));
        }

        $this->assertCount(25, $this->listedTitles());
        $this->assertCount(2, $this->listedTitles('?page=2'));
    }

    // ── Masquer / rétablir ───────────────────────────────────────────────

    public function testHidingAGameRecordsTheActionAndRestoringReopensIt(): void
    {
        $game  = $this->createGame($this->owner, 'Photo douteuse');
        $token = $this->moderationToken($game);

        $this->client->request('POST', '/fr/admin/games/' . $game->getId() . '/hide', ['_token' => $token, 'reason' => 'Photo inappropriée']);
        $this->assertResponseRedirects('/fr/admin/games');
        $this->assertTrue($this->reload($game)->isHidden());

        $logs = $this->em->getRepository(ModerationLog::class)->findAll();
        $this->assertCount(1, $logs);
        $this->assertSame(ModerationAction::GameHidden, $logs[0]->getAction());
        $this->assertSame('Photo inappropriée', $logs[0]->getReason());
        $this->assertSame($game->getId(), $logs[0]->getTargetId());

        $token = $this->moderationToken($game);
        $this->client->request('POST', '/fr/admin/games/' . $game->getId() . '/restore', ['_token' => $token]);
        $this->assertFalse($this->reload($game)->isHidden());
        $this->assertSame(
            [ModerationAction::GameHidden, ModerationAction::GameRestored],
            array_map(fn (ModerationLog $l) => $l->getAction(), $this->em->getRepository(ModerationLog::class)->findBy([], ['id' => 'ASC']))
        );
    }

    public function testHidingTwiceDoesNotDuplicateTheTrace(): void
    {
        $game  = $this->createGame($this->owner);
        $token = $this->moderationToken($game);

        $this->client->request('POST', '/fr/admin/games/' . $game->getId() . '/hide', ['_token' => $token]);
        $firstDate = $this->reload($game)->getHiddenAt();
        $this->client->request('POST', '/fr/admin/games/' . $game->getId() . '/hide', ['_token' => $token]);

        $this->assertCount(1, $this->em->getRepository(ModerationLog::class)->findAll());
        $this->assertEquals($firstDate, $this->reload($game)->getHiddenAt());
    }

    public function testModerationNeedsAValidCsrfToken(): void
    {
        $game = $this->createGame($this->owner);

        $this->client->request('POST', '/fr/admin/games/' . $game->getId() . '/hide', ['_token' => 'forged']);

        $this->assertResponseStatusCodeSame(403);
        $this->assertFalse($this->reload($game)->isHidden());
    }

    public function testReturnParameterOnlyAcceptsInternalPaths(): void
    {
        $game  = $this->createGame($this->owner);
        $token = $this->moderationToken($game);

        $this->client->request('POST', '/fr/admin/games/' . $game->getId() . '/hide', ['_token' => $token, '_return' => '//evil.example/phish']);
        $this->assertResponseRedirects('/fr/admin/games');

        $token = $this->moderationToken($game);
        $this->client->request('POST', '/fr/admin/games/' . $game->getId() . '/restore', ['_token' => $token, '_return' => 'https://evil.example']);
        $this->assertResponseRedirects('/fr/admin/games');

        $token = $this->moderationToken($game);
        $this->client->request('POST', '/fr/admin/games/' . $game->getId() . '/hide', ['_token' => $token, '_return' => '/fr/admin/users/' . $this->owner->getId()]);
        $this->assertResponseRedirects('/fr/admin/users/' . $this->owner->getId());
    }

    public function testUserDetailListsTheirGamesWithModerationButtons(): void
    {
        $this->createGame($this->owner, 'Premier');
        $this->createGame($this->owner, 'Second');

        $crawler = $this->client->request('GET', '/fr/admin/users/' . $this->owner->getId());

        $this->assertResponseIsSuccessful();
        $this->assertCount(2, $crawler->filter('tbody tr td[data-label="Jeu"]'));
        $this->assertCount(2, $crawler->filter('form[action*="/hide"]'));
    }

    // ── Aperçu de modération ─────────────────────────────────────────────

    public function testPreviewShowsTheContentToModerate(): void
    {
        $game = $this->createGame($this->owner, 'Bébé Martin');
        $game->setGuessName(true)->setGuessDate(true)->setGuessWeight(true)->setGuessHeight(true)
            ->setAnswerName('Prénom-Secret')->setDueDate(new \DateTimeImmutable('2027-01-15'));
        $guess = $this->createGuess($game, 'Tante Sylvie', 'sylvie@example.test');
        $guess->setGuessName('Proposition-Libre')->setGuessGender(\App\Enum\AnswerGender::Boy)
            ->setGuessDate(new \DateTimeImmutable('2027-01-20 08:30'))->setGuessWeight(3400)->setGuessHeight(505);
        $guess->setMessage("Bravo <script>alert(1)</script>\nBisous");
        $this->em->flush();

        $crawler = $this->client->request('GET', '/fr/admin/games/' . $game->getId());

        $this->assertResponseIsSuccessful();
        $html = $this->client->getResponse()->getContent();
        $this->assertStringContainsString('Bébé Martin', $html);
        $this->assertStringContainsString('Tante Sylvie', $html);
        $this->assertStringContainsString('15/01/2027', $html);
        $this->assertStringContainsString('Proposition-Libre', $html);
        // Les pronostics des joueurs sont visibles (sexe, date, poids, taille)
        $this->assertStringContainsString('Garçon', $html);
        $this->assertStringContainsString('20/01/2027 08:30', $html);
        $this->assertStringContainsString('3,40 kg', $html);
        $this->assertStringContainsString('50,5 cm', $html);
        // Le message est affiché, mais échappé : aucune balise ne s'exécute
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        $this->assertStringNotContainsString('<script>alert(1)', $html);
        // Le prénom secret est modérable, mais replié dans un <details> pour ne pas gâcher la surprise
        $this->assertSame('Prénom-Secret', trim($crawler->filter('details.adm-secret p')->text()));
        // L'email d'un invité n'est jamais nécessaire à la modération
        $this->assertStringNotContainsString('sylvie@example.test', $html);
    }

    public function testPreviewNeedsAnAdminAndAnExistingGame(): void
    {
        $game = $this->createGame($this->owner, 'Privé');

        $this->client->request('GET', '/fr/admin/games/99999');
        $this->assertResponseStatusCodeSame(404);

        $this->loginAs($this->owner);
        $this->client->request('GET', '/fr/admin/games/' . $game->getId());
        $this->assertResponseStatusCodeSame(403);
    }

    public function testHidingFromThePreviewComesBackToThePreview(): void
    {
        $game = $this->createGame($this->owner, 'Photo douteuse');
        $url  = '/fr/admin/games/' . $game->getId();

        $crawler = $this->client->request('GET', $url);
        $form    = $crawler->filter('form[action$="/hide"]')->form(['reason' => 'Photo inappropriée']);
        $this->client->submit($form);

        $this->assertResponseRedirects($url);
        $crawler = $this->client->followRedirect();
        $this->assertStringContainsString('Masqué', $crawler->filter('.adm-chips')->first()->text());
        $this->assertStringContainsString('Photo inappropriée', $crawler->text());
        $this->assertTrue($this->reload($game)->isHidden());
    }

    public function testListLinksToThePreview(): void
    {
        $game = $this->createGame($this->owner, 'Lien test');

        $crawler = $this->client->request('GET', '/fr/admin/games');

        $this->assertSame('/fr/admin/games/' . $game->getId(), $crawler->filter('td[data-label="Jeu"] a.adm-cell-main')->attr('href'));
    }
}
