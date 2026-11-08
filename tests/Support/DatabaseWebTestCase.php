<?php

namespace App\Tests\Support;

use App\Entity\Game;
use App\Entity\Guess;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Base des tests fonctionnels avec base de données : schéma recréé à vide avant chaque test
 * (SQLite en environnement de test, voir .env.test) et quelques fabriques de données.
 */
abstract class DatabaseWebTestCase extends WebTestCase
{
    protected KernelBrowser $client;
    protected EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em     = static::getContainer()->get('doctrine')->getManager();

        $tool     = new SchemaTool($this->em);
        $metadata = $this->em->getMetadataFactory()->getAllMetadata();
        $tool->dropSchema($metadata);
        $tool->createSchema($metadata);
    }

    protected function createUser(string $email, array $roles = [], bool $verified = true, string $first = 'Jean', string $last = 'Dupont'): User
    {
        $user = (new User())
            ->setEmail($email)
            ->setFirstName($first)
            ->setLastName($last)
            ->setPassword('not-a-real-hash')
            ->setRoles($roles)
            ->setIsVerified($verified);

        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    protected function createGame(User $owner, ?string $title = null): Game
    {
        $game = (new Game())->setUser($owner)->setGuessGender(true);
        if ($title !== null) {
            $game->setTitle($title);
        }

        $this->em->persist($game);
        $this->em->flush();

        return $game;
    }

    protected function createGuess(Game $game, string $player = 'Alice', ?string $email = null): Guess
    {
        $guess = (new Guess())->setGame($game)->setPlayerName($player)->setPlayerEmail($email);

        $this->em->persist($guess);
        $this->em->flush();

        return $guess;
    }

    /**
     * Soumet un formulaire Symfony protégé par le jeton CSRF « sans état » (double envoi cookie + champ),
     * comme le fait le JavaScript du site : le champ `_token` et le cookie `csrf-token_<jeton>` doivent correspondre.
     *
     * @param array<string, mixed> $fields champs du formulaire (sans le jeton)
     */
    protected function postForm(string $url, string $formName, array $fields): void
    {
        $token = 'abcdefghijklmnopqrstuvwxyz012345';
        $this->client->getCookieJar()->set(new \Symfony\Component\BrowserKit\Cookie('csrf-token_' . $token, 'csrf-token'));
        $this->client->request('POST', $url, [$formName => $fields + ['_token' => $token]]);
    }

    protected function loginAs(User $user): void
    {
        $this->client->loginUser($user);
    }
}
