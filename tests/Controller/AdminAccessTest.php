<?php

namespace App\Tests\Controller;

use App\Tests\Support\DatabaseWebTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class AdminAccessTest extends DatabaseWebTestCase
{
    public static function adminPages(): iterable
    {
        yield 'vue d\'ensemble' => ['/fr/admin'];
        yield 'utilisateurs' => ['/fr/admin/users'];
        yield 'jeux' => ['/fr/admin/games'];
        yield 'modération' => ['/fr/admin/moderation'];
        yield 'activité' => ['/fr/admin/logs'];
        yield 'sécurité' => ['/fr/admin/security'];
    }

    #[DataProvider('adminPages')]
    public function testAnonymousVisitorsAreSentToTheLogin(string $url): void
    {
        $this->client->request('GET', $url);

        $this->assertResponseRedirects('/fr/login');
    }

    #[DataProvider('adminPages')]
    public function testRegularUsersAreRefused(string $url): void
    {
        $this->loginAs($this->createUser('user@example.test'));

        $this->client->request('GET', $url);

        $this->assertResponseStatusCodeSame(403);
    }

    #[DataProvider('adminPages')]
    public function testAdminsCanOpenEveryPage(string $url): void
    {
        $this->loginAs($this->createUser('admin@example.test', ['ROLE_ADMIN']));

        $this->client->request('GET', $url);

        $this->assertResponseIsSuccessful();
    }

    public function testUserDetailPageNeedsAnAdminAndAnExistingUser(): void
    {
        $target = $this->createUser('target@example.test');

        $this->loginAs($this->createUser('user@example.test'));
        $this->client->request('GET', '/fr/admin/users/' . $target->getId());
        $this->assertResponseStatusCodeSame(403);

        $this->loginAs($this->createUser('admin@example.test', ['ROLE_ADMIN']));
        $this->client->request('GET', '/fr/admin/users/' . $target->getId());
        $this->assertResponseIsSuccessful();

        $this->client->request('GET', '/fr/admin/users/99999');
        $this->assertResponseStatusCodeSame(404);
    }

    public function testModerationActionsRefuseNonAdmins(): void
    {
        $owner = $this->createUser('owner@example.test');
        $game  = $this->createGame($owner, 'Mon jeu');
        $this->loginAs($owner);

        $this->client->request('POST', '/fr/admin/games/' . $game->getId() . '/hide');
        $this->assertResponseStatusCodeSame(403);

        $this->client->request('POST', '/fr/admin/users/' . $owner->getId() . '/delete');
        $this->assertResponseStatusCodeSame(403);
    }
}
