<?php

namespace App\Tests\Controller;

use App\Entity\Game;
use App\Tests\Support\DatabaseWebTestCase;
use App\Tests\Support\ImageFactory;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/** Création d'un jeu avec une photo : le jeu est toujours créé, et l'organisateur est prévenu si la photo est refusée. */
class GameCreationImageTest extends DatabaseWebTestCase
{
    /** @var list<string> */
    private array $tmpFiles = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->loginAs($this->createUser('owner@example.test'));
    }

    protected function tearDown(): void
    {
        // Seuls les fichiers créés par le test sont supprimés (jamais le reste du dossier d'envois)
        foreach ($this->tmpFiles as $file) {
            @unlink($file);
        }
        parent::tearDown();
    }

    private function uploaded(string $bytes, string $name): UploadedFile
    {
        $path = sys_get_temp_dir() . '/mbg-' . bin2hex(random_bytes(5)) . '-' . $name;
        file_put_contents($path, $bytes);
        $this->tmpFiles[] = $path;

        return new UploadedFile($path, $name, null, null, true);
    }

    /** Envoie le formulaire de création avec la photo donnée ; retourne le jeu créé. */
    private function createGameWithPhoto(string $bytes, string $name): Game
    {
        $crawler = $this->client->request('GET', '/fr/games/new');
        $this->assertResponseIsSuccessful();
        $token = $crawler->filter('input[name="_csrf_token"]')->attr('value');

        $this->client->request('POST', '/fr/games/new', ['_csrf_token' => $token, 'guessGender' => '1', 'title' => 'Avec photo'], ['imageFile' => $this->uploaded($bytes, $name)]);
        $this->assertResponseRedirects();

        $this->em->clear();
        $game = $this->em->getRepository(Game::class)->findOneBy(['title' => 'Avec photo']);
        if ($game->getImage()) {
            $this->tmpFiles[] = dirname(__DIR__, 2) . '/public/' . $game->getImage();
        }

        return $game;
    }

    public function testAValidPhotoIsStoredResizedAndLinkedToTheGame(): void
    {
        $game = $this->createGameWithPhoto(ImageFactory::jpegWithRedCorner(1600, 1200), 'photo.jpg');

        $this->assertNotNull($game->getImage());
        $info = getimagesize(dirname(__DIR__, 2) . '/public/' . $game->getImage());
        $this->assertSame([900, 675], [$info[0], $info[1]]);

        $this->client->followRedirect();
        $this->assertSelectorNotExists('.flash-error');
    }

    public function testAnImageWithAbsurdDimensionsCreatesTheGameWithoutPhotoAndExplainsWhy(): void
    {
        $game = $this->createGameWithPhoto(ImageFactory::pngDeclaring(20000, 20000), 'bomb.png');

        $this->assertNull($game->getImage());
        $this->client->followRedirect();
        $this->assertSelectorTextContains('.flash-error', 'Photo trop grande');
        $this->assertSelectorTextContains('.flash-error', 'sans photo');
    }

    public function testAFileThatIsNotAnImageCreatesTheGameWithoutPhotoAndExplainsWhy(): void
    {
        $game = $this->createGameWithPhoto('ceci n\'est pas une image', 'texte.jpg');

        $this->assertNull($game->getImage());
        $this->client->followRedirect();
        $this->assertSelectorTextContains('.flash-error', 'Format non accepté');
    }

    public function testAnUnreadableImageIsReportedInsteadOfBeingSilentlyDropped(): void
    {
        // Avant : l'échec était seulement écrit dans les journaux, l'organisateur ne voyait aucun message
        $game = $this->createGameWithPhoto(ImageFactory::pngDeclaring(10, 10), 'illisible.png');

        $this->assertNull($game->getImage());
        $this->client->followRedirect();
        $this->assertSelectorTextContains('.flash-error', 'n\'a pas pu être traitée');
    }

    public function testCreatingAGameWithoutAnyPhotoStillWorks(): void
    {
        $crawler = $this->client->request('GET', '/fr/games/new');
        $token   = $crawler->filter('input[name="_csrf_token"]')->attr('value');

        $this->client->request('POST', '/fr/games/new', ['_csrf_token' => $token, 'guessGender' => '1', 'title' => 'Sans photo']);

        $this->assertResponseRedirects();
        $this->em->clear();
        $this->assertNull($this->em->getRepository(Game::class)->findOneBy(['title' => 'Sans photo'])->getImage());
    }
}
