<?php

namespace App\Tests\Controller;

use App\Entity\ModerationLog;
use App\Enum\ModerationAction;
use App\Tests\Support\DatabaseWebTestCase;

class AdminModerationTest extends DatabaseWebTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $admin = $this->createUser('admin@example.test', ['ROLE_ADMIN']);
        $this->loginAs($admin);

        $this->em->persist(new ModerationLog($admin, ModerationAction::GameHidden, ModerationLog::TARGET_GAME, 1, 'Jeu A (de x@example.test)', 'Photo'));
        $this->em->persist(new ModerationLog($admin, ModerationAction::UserDeleted, ModerationLog::TARGET_USER, 2, 'spam@example.test (Bot Spam)', 'Spam répété'));
        $this->em->flush();
    }

    private function rows(string $query = ''): array
    {
        $crawler = $this->client->request('GET', '/fr/admin/moderation' . $query);
        $this->assertResponseIsSuccessful();

        return $crawler->filter('tbody tr td[data-label="Cible"]')->each(fn ($n) => trim($n->text()));
    }

    public function testListsActionsNewestFirst(): void
    {
        $this->assertSame(['spam@example.test (Bot Spam)', 'Jeu A (de x@example.test)'], $this->rows());
    }

    public function testFiltersByActionAndText(): void
    {
        $this->assertSame(['Jeu A (de x@example.test)'], $this->rows('?action=game_hidden'));
        $this->assertSame(['spam@example.test (Bot Spam)'], $this->rows('?q=spam'));
        $this->assertSame(['Jeu A (de x@example.test)'], $this->rows('?q=photo'));
        $this->assertSame([], $this->rows('?q=introuvable'));
    }

    public function testLogSurvivesTheDeletionOfItsAuthor(): void
    {
        // Pas de clé étrangère : l'historique garde l'email de l'admin même si son compte disparaît
        $this->em->clear();
        $admin = $this->em->getRepository(\App\Entity\User::class)->findOneBy(['email' => 'admin@example.test']);
        $this->em->remove($admin);
        $this->em->flush();

        $logs = $this->em->getRepository(ModerationLog::class)->findAll();
        $this->assertCount(2, $logs);
        $this->assertSame('admin@example.test', $logs[0]->getAdminEmail());
    }
}
