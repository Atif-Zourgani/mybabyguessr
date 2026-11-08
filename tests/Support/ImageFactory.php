<?php

namespace App\Tests\Support;

/**
 * Fabrique de petites images de test (GD) et de fichiers volontairement piégés.
 */
final class ImageFactory
{
    /** JPEG avec un carré rouge de 10x10 pixels en haut à gauche sur fond bleu : permet de suivre les rotations. */
    public static function jpegWithRedCorner(int $width, int $height): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagefill($image, 0, 0, (int) imagecolorallocate($image, 0, 0, 255));
        imagefilledrectangle($image, 0, 0, 9, 9, (int) imagecolorallocate($image, 255, 0, 0));
        ob_start();
        imagejpeg($image, null, 100);

        return (string) ob_get_clean();
    }

    public static function png(int $width, int $height, bool $transparent = false): string
    {
        $image = imagecreatetruecolor($width, $height);
        if ($transparent) {
            imagealphablending($image, false);
            imagesavealpha($image, true);
            imagefill($image, 0, 0, (int) imagecolorallocatealpha($image, 0, 0, 0, 127));
        } else {
            imagefill($image, 0, 0, (int) imagecolorallocate($image, 10, 120, 10));
        }
        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }

    public static function webp(int $width, int $height): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagefill($image, 0, 0, (int) imagecolorallocate($image, 200, 50, 50));
        ob_start();
        imagewebp($image);

        return (string) ob_get_clean();
    }

    /** Insère une étiquette EXIF d'orientation (1 à 8) juste après le début d'un JPEG. */
    public static function withExifOrientation(string $jpeg, int $orientation): string
    {
        $tiff = "MM\x00\x2A\x00\x00\x00\x08"                      // en-tête TIFF, premier répertoire à l'octet 8
              . pack('n', 1)                                      // 1 entrée
              . pack('nnN', 0x0112, 3, 1) . pack('n', $orientation) . "\x00\x00" // Orientation (SHORT)
              . pack('N', 0);                                     // pas de répertoire suivant
        $payload = "Exif\x00\x00" . $tiff;
        $segment = "\xFF\xE1" . pack('n', strlen($payload) + 2) . $payload;

        return substr($jpeg, 0, 2) . $segment . substr($jpeg, 2);
    }

    /**
     * Fichier PNG minuscule (quelques dizaines d'octets) dont l'en-tête annonce de très grandes dimensions,
     * sans aucune donnée d'image : sert à tester le refus avant décodage.
     */
    public static function pngDeclaring(int $width, int $height): string
    {
        $chunk = static fn (string $type, string $data): string => pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));

        return "\x89PNG\r\n\x1a\n"
            . $chunk('IHDR', pack('NNCCCCC', $width, $height, 8, 2, 0, 0, 0))
            . $chunk('IEND', '');
    }
}
