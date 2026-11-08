<?php

namespace App\Service;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Valide, redresse, réduit et ré-encode la photo d'un jeu (JPEG, PNG ou WebP en entrée, JPEG en sortie).
 *
 * Points de sécurité et de qualité :
 *  - les dimensions annoncées par l'en-tête du fichier sont vérifiées AVANT de décoder : une image de 4 Mo peut
 *    représenter des centaines de millions de pixels et épuiser la mémoire du serveur (erreur fatale impossible à rattraper) ;
 *  - l'orientation EXIF est appliquée (les photos de téléphone ne s'affichent plus couchées) ;
 *  - le ré-encodage supprime toutes les métadonnées, y compris la position GPS.
 */
class GameImageProcessor
{
    public const MAX_BYTES  = 4 * 1024 * 1024;
    public const MAX_SIDE   = 900;
    public const MAX_PIXELS = 50_000_000;
    public const JPEG_QUALITY = 85;

    /** Octets nécessaires pour décoder un pixel avec GD (4) plus une marge. */
    private const BYTES_PER_PIXEL = 4.5;
    /** Part de la mémoire autorisée que l'on accepte de consommer pour une image. */
    private const MEMORY_SHARE = 0.8;

    private readonly string $uploadsDir;

    /**
     * @param int|null $memoryLimitBytes null : lit memory_limit de PHP (surchargé dans les tests) ; -1 : illimitée
     */
    public function __construct(
        #[Autowire('%kernel.project_dir%')] string $projectDir,
        private readonly LoggerInterface $logger,
        private readonly ?int $memoryLimitBytes = null,
    ) {
        $this->uploadsDir = $projectDir . '/public/uploads/games';
    }

    /**
     * @return string chemin relatif à public/, par exemple « uploads/games/ab12….jpg »
     *
     * @throws GameImageException
     */
    public function process(UploadedFile $file): string
    {
        if (!$file->isValid()) {
            throw new GameImageException(GameImageException::UPLOAD);
        }

        if ($file->getSize() > self::MAX_BYTES) {
            throw new GameImageException(GameImageException::SIZE);
        }

        $path = $file->getPathname();
        $info = @getimagesize($path);
        if ($info === false || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true) || $info[0] < 1 || $info[1] < 1) {
            throw new GameImageException(GameImageException::TYPE);
        }

        [$width, $height, $type] = $info;
        if ($width * $height > self::MAX_PIXELS || !$this->fitsInMemory($width, $height)) {
            throw new GameImageException(GameImageException::DIMENSIONS);
        }

        try {
            return $this->resizeAndSave($path, $width, $height, $type);
        } catch (GameImageException $e) {
            throw $e;
        } catch (\Throwable $e) {
            $this->logger->error('Image processing failed during game creation', ['error' => $e->getMessage()]);

            throw new GameImageException(GameImageException::PROCESSING, $e);
        }
    }

    private function resizeAndSave(string $path, int $width, int $height, int $type): string
    {
        $source = match ($type) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($path),
            IMAGETYPE_PNG  => @imagecreatefrompng($path),
            IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($path) : false,
        };
        if ($source === false) {
            throw new GameImageException(GameImageException::PROCESSING);
        }

        // Jamais d'agrandissement
        $ratio     = min(1, self::MAX_SIDE / $width, self::MAX_SIDE / $height);
        $newWidth  = max(1, (int) round($width * $ratio));
        $newHeight = max(1, (int) round($height * $ratio));

        $target = imagecreatetruecolor($newWidth, $newHeight);
        // Fond blanc sous les zones transparentes (PNG, WebP) pour ne pas obtenir du noir en JPEG
        imagefill($target, 0, 0, (int) imagecolorallocate($target, 255, 255, 255));
        imagecopyresampled($target, $source, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
        unset($source);

        if ($type === IMAGETYPE_JPEG) {
            $target = $this->applyExifOrientation($target, $path);
        }

        if (!is_dir($this->uploadsDir) && !@mkdir($this->uploadsDir, 0755, true) && !is_dir($this->uploadsDir)) {
            throw new GameImageException(GameImageException::PROCESSING);
        }

        $filename    = bin2hex(random_bytes(16)) . '.jpg';
        $destination = $this->uploadsDir . '/' . $filename;
        if (!@imagejpeg($target, $destination, self::JPEG_QUALITY)) {
            @unlink($destination);

            throw new GameImageException(GameImageException::PROCESSING);
        }
        chmod($destination, 0644);

        return 'uploads/games/' . $filename;
    }

    /** Redresse l'image selon l'orientation EXIF des photos de téléphone (sans EXIF : inchangée). */
    private function applyExifOrientation(\GdImage $image, string $path): \GdImage
    {
        if (!function_exists('exif_read_data')) {
            return $image;
        }

        $exif        = @exif_read_data($path);
        $orientation = is_array($exif) ? (int) ($exif['Orientation'] ?? 1) : 1;

        switch ($orientation) {
            case 2:
                imageflip($image, IMG_FLIP_HORIZONTAL);
                break;
            case 3:
                $image = imagerotate($image, 180, 0) ?: $image;
                break;
            case 4:
                imageflip($image, IMG_FLIP_VERTICAL);
                break;
            case 5:
                $image = imagerotate($image, -90, 0) ?: $image;
                imageflip($image, IMG_FLIP_HORIZONTAL);
                break;
            case 6:
                $image = imagerotate($image, -90, 0) ?: $image;
                break;
            case 7:
                $image = imagerotate($image, 90, 0) ?: $image;
                imageflip($image, IMG_FLIP_HORIZONTAL);
                break;
            case 8:
                $image = imagerotate($image, 90, 0) ?: $image;
                break;
        }

        return $image;
    }

    private function fitsInMemory(int $width, int $height): bool
    {
        $limit = $this->memoryLimitBytes ?? $this->phpMemoryLimit();
        if ($limit < 0) {
            return true;
        }

        return memory_get_usage(true) + (int) ($width * $height * self::BYTES_PER_PIXEL) < $limit * self::MEMORY_SHARE;
    }

    private function phpMemoryLimit(): int
    {
        $raw = trim((string) ini_get('memory_limit'));
        if ($raw === '' || $raw === '-1') {
            return -1;
        }

        $value = (int) $raw;

        return match (strtolower(substr($raw, -1))) {
            'g'     => $value * 1024 ** 3,
            'm'     => $value * 1024 ** 2,
            'k'     => $value * 1024,
            default => $value,
        };
    }
}
