<?php

namespace App\Tests\Design;

use PHPUnit\Framework\TestCase;

/**
 * La couleur de marque sert de texte (liens, étiquettes) et de fond de bouton (texte blanc). Ce test empêche de
 * la rendre à nouveau trop claire : en dessous de 4,5 pour 1 le texte est difficile à lire (norme WCAG AA).
 */
class BrandContrastTest extends TestCase
{
    private const ROOT = __DIR__ . '/../..';

    private static function themeColor(string $file, string $name): string
    {
        $css = (string) file_get_contents(self::ROOT . '/' . $file);
        self::assertMatchesRegularExpression('/--color-' . $name . ':\s*(#[0-9A-Fa-f]{6})/', $css, "$name introuvable dans $file");
        preg_match('/--color-' . $name . ':\s*(#[0-9A-Fa-f]{6})/', $css, $m);

        return strtoupper($m[1]);
    }

    /** @return array{0: float, 1: float, 2: float} */
    private static function rgb(string $hex): array
    {
        return [hexdec(substr($hex, 1, 2)), hexdec(substr($hex, 3, 2)), hexdec(substr($hex, 5, 2))];
    }

    private static function luminance(array $rgb): float
    {
        [$r, $g, $b] = array_map(static function (float $v): float {
            $v /= 255;

            return $v <= 0.03928 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4;
        }, $rgb);

        return 0.2126 * $r + 0.7152 * $g + 0.0722 * $b;
    }

    private static function contrast(array $a, array $b): float
    {
        $la = self::luminance($a);
        $lb = self::luminance($b);

        return (max($la, $lb) + 0.05) / (min($la, $lb) + 0.05);
    }

    /** Fond de la pastille « teinte de marque » : la couleur à 12 % d'opacité sur le fond. */
    private static function tint(array $brand, array $background): array
    {
        return array_map(static fn ($c, $bg) => $c * 0.12 + $bg * 0.88, $brand, $background);
    }

    public function testBrandColourIsReadableAsTextAndAsButtonBackground(): void
    {
        $brand = self::rgb(self::themeColor('var/tailwind/app.built.css', 'brand'));
        $cream = self::rgb(self::themeColor('var/tailwind/app.built.css', 'cream'));
        $white = [255, 255, 255];

        $this->assertGreaterThanOrEqual(4.5, self::contrast($brand, $cream), 'texte de marque sur le fond crème');
        $this->assertGreaterThanOrEqual(4.5, self::contrast($brand, $white), 'texte de marque sur fond blanc, et texte blanc des boutons');
        $this->assertGreaterThanOrEqual(4.5, self::contrast($brand, self::tint([196, 113, 74], $cream)), 'texte de marque dans une pastille teintée (admin, étiquettes)');
    }

    public function testStylesheetSourceAndBuiltFileAgreeOnTheBrandColour(): void
    {
        // Le CSS compilé est versionné : s'il diverge de la source, la prochaine compilation changerait le site
        $this->assertSame(
            self::themeColor('assets/styles/app.css', 'brand'),
            self::themeColor('var/tailwind/app.built.css', 'brand'),
        );
    }

    public function testNoStrayCopyOfTheOldBrandColourRemains(): void
    {
        $dirs = ['templates', 'assets/styles', 'src', 'config'];
        foreach ($dirs as $dir) {
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::ROOT . '/' . $dir, \FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                if (!$file->isFile() || !preg_match('/\.(twig|css|php|yaml)$/', $file->getFilename())) {
                    continue;
                }
                $this->assertStringNotContainsStringIgnoringCase('#C4714A', (string) file_get_contents($file->getPathname()), $file->getPathname() . ' contient l\'ancienne couleur de marque');
            }
        }
    }
}
