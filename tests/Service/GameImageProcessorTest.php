<?php

namespace App\Tests\Service;

use App\Service\GameImageException;
use App\Service\GameImageProcessor;
use App\Tests\Support\ImageFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\File\UploadedFile;

class GameImageProcessorTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/mbg-images-' . bin2hex(random_bytes(6));
        mkdir($this->dir . '/public/uploads/games', 0755, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/{,public/uploads/games/}*', GLOB_BRACE) ?: [] as $file) {
            is_file($file) && unlink($file);
        }
        @rmdir($this->dir . '/public/uploads/games');
        @rmdir($this->dir . '/public/uploads');
        @rmdir($this->dir . '/public');
        @rmdir($this->dir);
    }

    private function processor(?int $memoryLimit = -1): GameImageProcessor
    {
        return new GameImageProcessor($this->dir, new NullLogger(), $memoryLimit);
    }

    private function upload(string $bytes, string $name = 'photo.jpg'): UploadedFile
    {
        $path = $this->dir . '/' . bin2hex(random_bytes(4)) . '-' . $name;
        file_put_contents($path, $bytes);

        return new UploadedFile($path, $name, null, null, true);
    }

    /** @return array{0: int, 1: int, 2: int} largeur, hauteur, type de l'image enregistrée */
    private function stored(string $relative): array
    {
        $file = $this->dir . '/public/' . $relative;
        $this->assertFileExists($file);
        $info = getimagesize($file);

        return [$info[0], $info[1], $info[2]];
    }

    private function assertRejected(string $reason, callable $action): void
    {
        try {
            $action();
        } catch (GameImageException $e) {
            $this->assertSame($reason, $e->reason);
            $this->assertCount(0, glob($this->dir . '/public/uploads/games/*') ?: [], 'aucun fichier ne doit être écrit');

            return;
        }
        $this->fail("Une GameImageException « $reason » était attendue.");
    }

    // ── Redimensionnement et formats ─────────────────────────────────────

    public function testALargeImageIsShrunkToTheMaximumSideKeepingItsRatio(): void
    {
        $path = $this->processor()->process($this->upload(ImageFactory::jpegWithRedCorner(1800, 900)));

        $this->assertSame([900, 450, IMAGETYPE_JPEG], $this->stored($path));
    }

    public function testASmallImageIsNeverEnlarged(): void
    {
        $path = $this->processor()->process($this->upload(ImageFactory::jpegWithRedCorner(300, 200)));

        $this->assertSame([300, 200, IMAGETYPE_JPEG], $this->stored($path));
    }

    public function testPngAndWebpAreConvertedToJpegAtAUniqueRandomName(): void
    {
        $png  = $this->processor()->process($this->upload(ImageFactory::png(120, 80), 'a.png'));
        $webp = $this->processor()->process($this->upload(ImageFactory::webp(120, 80), 'b.webp'));

        $this->assertSame([120, 80, IMAGETYPE_JPEG], $this->stored($png));
        $this->assertSame([120, 80, IMAGETYPE_JPEG], $this->stored($webp));
        $this->assertMatchesRegularExpression('#^uploads/games/[a-f0-9]{32}\.jpg$#', $png);
        $this->assertNotSame($png, $webp);
        $this->assertSame('0644', substr(sprintf('%o', fileperms($this->dir . '/public/' . $png)), -4));
    }

    public function testTransparentPixelsBecomeWhiteNotBlack(): void
    {
        $path  = $this->processor()->process($this->upload(ImageFactory::png(40, 40, true), 'transparent.png'));
        $image = imagecreatefromjpeg($this->dir . '/public/' . $path);
        $rgb   = imagecolorat($image, 20, 20);

        $this->assertGreaterThan(240, ($rgb >> 16) & 255);
        $this->assertGreaterThan(240, ($rgb >> 8) & 255);
        $this->assertGreaterThan(240, $rgb & 255);
    }

    public function testTheUploadFolderIsCreatedWhenMissing(): void
    {
        rmdir($this->dir . '/public/uploads/games');

        $path = $this->processor()->process($this->upload(ImageFactory::jpegWithRedCorner(50, 50)));

        $this->assertFileExists($this->dir . '/public/' . $path);
    }

    // ── Orientation des photos de téléphone ──────────────────────────────

    /**
     * Le carré rouge est en haut à gauche dans les pixels enregistrés ; l'étiquette EXIF dit comment afficher la photo.
     * Résultat attendu : dimensions finales et position du carré rouge après redressement.
     */
    public static function orientations(): iterable
    {
        yield 'sans rotation (1)'              => [1, [40, 20], [3, 3]];
        yield 'miroir horizontal (2)'          => [2, [40, 20], [36, 3]];
        yield 'rotation 180 (3)'               => [3, [40, 20], [36, 16]];
        yield 'rotation 90 horaire (6)'        => [6, [20, 40], [16, 3]];
        yield 'rotation 90 anti-horaire (8)'   => [8, [20, 40], [3, 36]];
    }

    #[DataProvider('orientations')]
    public function testExifOrientationIsApplied(int $orientation, array $expectedSize, array $redAt): void
    {
        if (!function_exists('exif_read_data')) {
            $this->markTestSkipped('Extension exif absente.');
        }

        $bytes = ImageFactory::withExifOrientation(ImageFactory::jpegWithRedCorner(40, 20), $orientation);
        $path  = $this->processor()->process($this->upload($bytes));

        [$w, $h] = $this->stored($path);
        $this->assertSame($expectedSize, [$w, $h]);

        $image = imagecreatefromjpeg($this->dir . '/public/' . $path);
        $rgb   = imagecolorat($image, $redAt[0], $redAt[1]);
        $this->assertGreaterThan(180, ($rgb >> 16) & 255, 'le carré rouge doit être à la position attendue');
        $this->assertLessThan(100, $rgb & 255);
    }

    public function testMetadataIsRemovedFromTheStoredImage(): void
    {
        if (!function_exists('exif_read_data')) {
            $this->markTestSkipped('Extension exif absente.');
        }

        $bytes = ImageFactory::withExifOrientation(ImageFactory::jpegWithRedCorner(40, 20), 6);
        $path  = $this->processor()->process($this->upload($bytes));

        $exif = @exif_read_data($this->dir . '/public/' . $path);
        $this->assertArrayNotHasKey('Orientation', is_array($exif) ? $exif : []);
        $this->assertStringNotContainsString('Exif', substr((string) file_get_contents($this->dir . '/public/' . $path), 0, 4096));
    }

    // ── Refus ────────────────────────────────────────────────────────────

    public function testFilesOverFourMegabytesAreRejected(): void
    {
        $this->assertRejected(GameImageException::SIZE, fn () => $this->processor()->process($this->upload(str_repeat('x', GameImageProcessor::MAX_BYTES + 1))));
    }

    public function testAnUploadErrorIsRejected(): void
    {
        $path = $this->dir . '/broken.jpg';
        file_put_contents($path, 'x');
        $file = new UploadedFile($path, 'broken.jpg', null, UPLOAD_ERR_INI_SIZE, true);

        $this->assertRejected(GameImageException::UPLOAD, fn () => $this->processor()->process($file));
    }

    public static function notSupported(): iterable
    {
        yield 'texte renommé en jpg' => ['ceci n\'est pas une image'];
        yield 'GIF'                  => [base64_decode('R0lGODlhAQABAAAAACH5BAEKAAEALAAAAAABAAEAAAICTAEAOw==')];
        yield 'SVG'                  => ['<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10"></svg>'];
        yield 'fichier vide'         => [''];
    }

    #[DataProvider('notSupported')]
    public function testOtherFormatsAreRejected(string $bytes): void
    {
        $this->assertRejected(GameImageException::TYPE, fn () => $this->processor()->process($this->upload($bytes)));
    }

    public function testHugeDeclaredDimensionsAreRejectedBeforeDecoding(): void
    {
        // Un fichier de quelques dizaines d'octets qui annonce 400 millions de pixels
        $bomb = $this->upload(ImageFactory::pngDeclaring(20000, 20000), 'bomb.png');
        $this->assertLessThan(200, $bomb->getSize());

        $this->assertRejected(GameImageException::DIMENSIONS, fn () => $this->processor(-1)->process($bomb));
    }

    public function testAnImageThatWouldNotFitInMemoryIsRejected(): void
    {
        // 6000 x 4000 = 24 millions de pixels : ok en nombre, mais trop pour une limite mémoire de 128 Mo
        $file = $this->upload(ImageFactory::pngDeclaring(6000, 4000), 'big.png');

        $this->assertRejected(GameImageException::DIMENSIONS, fn () => $this->processor(128 * 1024 * 1024)->process($file));
    }

    public function testMemoryCheckAllowsNormalPhotosAndSkipsWhenUnlimited(): void
    {
        // 12 millions de pixels (photo de téléphone courante) : la vérification mémoire laisse passer ;
        // l'échec vient ensuite du décodage (le fichier ne contient pas de données), pas des dimensions
        $file = $this->upload(ImageFactory::pngDeclaring(4000, 3000), 'phone.png');

        $this->assertRejected(GameImageException::PROCESSING, fn () => $this->processor(512 * 1024 * 1024)->process($file));
        $this->assertRejected(GameImageException::PROCESSING, fn () => $this->processor(-1)->process($file));
    }

    public function testACorruptImageIsRejectedCleanly(): void
    {
        // En-tête valide (10 x 10) mais aucune donnée d'image
        $this->assertRejected(GameImageException::PROCESSING, fn () => $this->processor()->process($this->upload(ImageFactory::pngDeclaring(10, 10), 'corrupt.png')));
    }
}
