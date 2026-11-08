<?php

namespace App\Tests\Controller;

use App\Entity\ModerationLog;
use App\Entity\User;
use App\Enum\ModerationAction;
use App\Tests\Support\DatabaseWebTestCase;

class AdminUsersTest extends DatabaseWebTestCase
{
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->createUser('admin@example.test', ['ROLE_ADMIN'], true, 'Ada', 'Admin');
        $this->loginAs($this->admin);
    }

    private function listedEmails(string $query = ''): array
    {
        $crawler = $this->client->request('GET', '/fr/admin/users' . $query);
        $this->assertResponseIsSuccessful();

        return $crawler->filter('tbody tr td[data-label="Utilisateur"] .adm-cell-sub')->each(fn ($n) => trim($n->text()));
    }

    // ── Liste : recherche, filtres, tri, pagination ──────────────────────

    public function testSearchMatchesNameOrEmail(): void
    {
        $this->createUser('marie@example.test', [], true, 'Marie', 'Curie');
        $this->createUser('pierre@example.test', [], true, 'Pierre', 'Dupont');

        $this->assertSame(['marie@example.test'], $this->listedEmails('?q=curie'));
        $this->assertSame(['pierre@example.test'], $this->listedEmails('?q=pierre%40example'));
        $this->assertSame(['marie@example.test'], $this->listedEmails('?q=' . urlencode('Marie Curie')));
        $this->assertSame([], $this->listedEmails('?q=personne'));
    }

    public function testSearchTreatsLikeWildcardsAsPlainText(): void
    {
        $this->createUser('marie@example.test');

        // "%" et "_" ne doivent pas servir de jokers : "m%e" ne trouve pas "marie"
        $this->assertSame([], $this->listedEmails('?q=' . urlencode('m%e')));
        $this->assertSame([], $this->listedEmails('?q=' . urlencode('m_rie')));
    }

    public function testVerifiedAndRoleFilters(): void
    {
        $this->createUser('ok@example.test', [], true);
        $this->createUser('pending@example.test', [], false);

        $this->assertSame(['pending@example.test'], $this->listedEmails('?verified=0'));
        $this->assertContains('ok@example.test', $this->listedEmails('?verified=1'));
        $this->assertNotContains('pending@example.test', $this->listedEmails('?verified=1'));

        $this->assertSame(['admin@example.test'], $this->listedEmails('?role=admin'));
        $this->assertNotContains('admin@example.test', $this->listedEmails('?role=user'));
    }

    public function testSortByNumberOfGamesAndUnknownSortFallsBack(): void
    {
        $busy  = $this->createUser('busy@example.test');
        $quiet = $this->createUser('quiet@example.test');
        $this->createGame($busy, 'A');
        $this->createGame($busy, 'B');
        $this->createGame($quiet, 'C');

        $emails = $this->listedEmails('?sort=games&dir=desc');
        $this->assertSame('busy@example.test', $emails[0]);
        $this->assertSame('quiet@example.test', $emails[1]);

        // Colonne inconnue (tentative d'injection) : tri par défaut, pas d'erreur
        $this->listedEmails('?sort=' . urlencode('id; DROP TABLE user') . '&dir=sideways');
        $this->assertResponseIsSuccessful();
    }

    public function testPaginationSplitsLongLists(): void
    {
        for ($i = 1; $i <= 29; $i++) {
            $this->createUser(sprintf('user%02d@example.test', $i));
        }

        // 30 comptes au total, 25 par page
        $this->assertCount(25, $this->listedEmails());
        $this->assertCount(5, $this->listedEmails('?page=2'));
        // Page hors limites : liste vide mais pas d'erreur
        $this->assertCount(0, $this->listedEmails('?page=9'));
        $this->assertCount(25, $this->listedEmails('?page=-3'));
    }

    // ── Fiche utilisateur et suppression ─────────────────────────────────

    private function deleteToken(User $user): string
    {
        $crawler = $this->client->request('GET', '/fr/admin/users/' . $user->getId());
        $this->assertResponseIsSuccessful();

        return $crawler->filter('form[action$="/delete"] input[name="_token"]')->attr('value');
    }

    private function postDelete(User $user, array $fields): void
    {
        $this->client->request('POST', '/fr/admin/users/' . $user->getId() . '/delete', $fields);
    }

    private function userExists(string $email): bool
    {
        $this->em->clear();

        return $this->em->getRepository(User::class)->findOneBy(['email' => $email]) !== null;
    }

    public function testDeletingAnAccountRemovesItsDataAndKeepsATrace(): void
    {
        $target = $this->createUser('target@example.test', [], true, 'Tom', 'Cible');
        $game   = $this->createGame($target, 'Jeu à supprimer');
        $this->createGuess($game, 'Alice', 'alice@example.test');

        $image = dirname(__DIR__, 2) . '/public/uploads/games/test-moderation.jpg';
        file_put_contents($image, 'x');
        $game->setImage('uploads/games/test-moderation.jpg');
        $this->em->flush();

        $token = $this->deleteToken($target);
        $id    = $target->getId();
        $this->postDelete($target, ['_token' => $token, 'confirm_email' => 'TARGET@example.test', 'reason' => 'Contenu inapproprié']);

        $this->assertResponseRedirects('/fr/admin/users');
        $this->assertFalse($this->userExists('target@example.test'));
        $this->assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM game'));
        $this->assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM guess'));
        $this->assertFileDoesNotExist($image);

        $logs = $this->em->getRepository(ModerationLog::class)->findAll();
        $this->assertCount(1, $logs);
        $this->assertSame(ModerationAction::UserDeleted, $logs[0]->getAction());
        $this->assertSame($id, $logs[0]->getTargetId());
        $this->assertSame('admin@example.test', $logs[0]->getAdminEmail());
        $this->assertSame('Contenu inapproprié', $logs[0]->getReason());
        $this->assertStringContainsString('target@example.test', $logs[0]->getTargetLabel());
    }

    public function testDeletionNeedsAValidCsrfToken(): void
    {
        $target = $this->createUser('target@example.test');

        $this->postDelete($target, ['_token' => 'forged', 'confirm_email' => 'target@example.test']);

        $this->assertResponseStatusCodeSame(403);
        $this->assertTrue($this->userExists('target@example.test'));
    }

    public function testDeletionNeedsTheEmailToBeRetyped(): void
    {
        $target = $this->createUser('target@example.test');
        $token  = $this->deleteToken($target);

        $this->postDelete($target, ['_token' => $token, 'confirm_email' => 'autre@example.test']);

        $this->assertResponseRedirects();
        $this->assertTrue($this->userExists('target@example.test'));
        $this->assertCount(0, $this->em->getRepository(ModerationLog::class)->findAll());
    }

    public function testAnAdminCannotDeleteThemselvesOrAnotherAdmin(): void
    {
        $other = $this->createUser('other-admin@example.test', ['ROLE_ADMIN']);

        // Soi-même : le formulaire n'est même pas proposé, on forge donc la requête
        $this->client->request('GET', '/fr/admin/users/' . $this->admin->getId());
        $this->assertSelectorNotExists('form[action$="/delete"]');
        $this->postDelete($this->admin, ['_token' => 'forged', 'confirm_email' => 'admin@example.test']);
        $this->assertResponseStatusCodeSame(403); // jeton invalide, rien n'est supprimé

        $this->client->request('GET', '/fr/admin/users/' . $other->getId());
        $this->assertSelectorNotExists('form[action$="/delete"]');
        $this->assertTrue($this->userExists('admin@example.test'));
        $this->assertTrue($this->userExists('other-admin@example.test'));
    }

    // ── Dernière connexion ───────────────────────────────────────────────

    public function testLoggingInRecordsTheLastLoginDate(): void
    {
        $hasher = static::getContainer()->get('security.user_password_hasher');
        $member = $this->createUser('member@example.test');
        $member->setPassword($hasher->hashPassword($member, 'Secret-123'));
        $this->em->flush();
        $this->assertNull($member->getLastLoginAt());

        // Le formulaire de connexion utilise un jeton CSRF « sans état » : cookie + champ identiques
        $csrf = 'abcdefghijklmnopqrstuvwxyz012345';
        $this->client->restart(); // on repart d'un navigateur sans session
        $this->client->getCookieJar()->set(new \Symfony\Component\BrowserKit\Cookie('csrf-token_' . $csrf, 'csrf-token'));
        $this->client->request('POST', '/fr/login', ['_csrf_token' => $csrf, 'email' => 'member@example.test', 'password' => 'Secret-123']);

        $this->assertResponseRedirects('/fr/dashboard');
        $this->em->clear();
        $fresh = $this->em->getRepository(User::class)->findOneBy(['email' => 'member@example.test']);
        $this->assertNotNull($fresh->getLastLoginAt());
        $this->assertEqualsWithDelta(time(), $fresh->getLastLoginAt()->getTimestamp(), 30);
    }
}
