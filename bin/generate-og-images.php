#!/usr/bin/env php
<?php

/**
 * Génère les images de prévisualisation des réseaux sociaux (1200 x 630 px), une par langue :
 * assets/images/og/og-<langue>.png, à partir du logo, des illustrations et des textes de l'accueil.
 *
 * Usage : php bin/generate-og-images.php [--font=/chemin/police.ttf]
 * Nécessite l'extension GD avec FreeType et une police TrueType (par défaut Arial Rounded Bold de macOS).
 */

require dirname(__DIR__) . '/vendor/autoload.php';

use Symfony\Component\Yaml\Yaml;

$root = dirname(__DIR__);
$font = '/System/Library/Fonts/Supplemental/Arial Rounded Bold.ttf';
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--font=')) {
        $font = substr($arg, 7);
    }
}
if (!is_file($font) || !function_exists('imagettftext')) {
    fwrite(STDERR, "Police introuvable ou GD sans FreeType : $font\n");
    exit(1);
}

const WIDTH = 1200;
const HEIGHT = 630;

function color($image, string $hex): int
{
    return (int) imagecolorallocate($image, hexdec(substr($hex, 1, 2)), hexdec(substr($hex, 3, 2)), hexdec(substr($hex, 5, 2)));
}

/** Découpe un texte en lignes qui tiennent dans la largeur donnée. */
function wrap(string $text, string $font, float $size, int $maxWidth): array
{
    $lines = [];
    $line  = '';
    foreach (explode(' ', $text) as $word) {
        $try  = $line === '' ? $word : "$line $word";
        $box  = imagettfbbox($size, 0, $font, $try);
        if ($box[2] - $box[0] > $maxWidth && $line !== '') {
            $lines[] = $line;
            $line    = $word;
        } else {
            $line = $try;
        }
    }
    $lines[] = $line;

    return $lines;
}

/** Rectangle [x0, y0, x1, y1] qui entoure le contenu d'une image (tout ce qui diffère de la couleur de fond du coin). */
function contentBox($image): array
{
    $w  = imagesx($image);
    $h  = imagesy($image);
    $bg = imagecolorsforindex($image, imagecolorat($image, 2, 2));
    [$x0, $y0, $x1, $y1] = [$w, $h, 0, 0];
    for ($y = 0; $y < $h; $y++) {
        for ($x = 0; $x < $w; $x++) {
            $c = imagecolorsforindex($image, imagecolorat($image, $x, $y));
            if ($c['alpha'] < 100 && (abs($c['red'] - $bg['red']) + abs($c['green'] - $bg['green']) + abs($c['blue'] - $bg['blue'])) > 40) {
                $x0 = min($x0, $x); $y0 = min($y0, $y); $x1 = max($x1, $x); $y1 = max($y1, $y);
            }
        }
    }

    return [$x0, $y0, $x1 + 1, $y1 + 1];
}

/** Colle une image (avec transparence) redimensionnée à la hauteur voulue. */
function paste($canvas, string $file, int $x, int $bottomY, int $height): void
{
    $src = str_ends_with($file, '.webp') ? imagecreatefromwebp($file) : imagecreatefrompng($file);
    $w   = imagesx($src);
    $h   = imagesy($src);
    $nw  = (int) round($w * $height / $h);
    imagecopyresampled($canvas, $src, $x, $bottomY - $height, 0, 0, $nw, $height, $w, $h);
}

foreach (['fr', 'en', 'es', 'pt', 'de', 'it'] as $locale) {
    $hero = Yaml::parseFile("$root/translations/messages.$locale.yaml")['home']['hero'];

    $img = imagecreatetruecolor(WIDTH, HEIGHT);
    imagealphablending($img, true);
    imagefill($img, 0, 0, color($img, '#F8F3EE'));

    // Bandeau de couleur et formes douces en arrière-plan
    imagefilledellipse($img, 1010, 330, 760, 760, color($img, '#F1E4DA'));
    imagefilledellipse($img, 1130, 80, 260, 260, color($img, '#F6EADF'));
    imagefilledrectangle($img, 0, HEIGHT - 14, WIDTH, HEIGHT, color($img, '#C4714A'));

    // Illustrations à droite
    paste($img, "$root/assets/images/illustration/girafe.webp", 905, 560, 470);
    paste($img, "$root/assets/images/illustration/elephant.webp", 735, 560, 270);
    paste($img, "$root/assets/images/illustration/soleil.webp", 1010, 130, 100);

    // Logo rogné sur son contenu (le fichier a de grandes marges), en haut à gauche
    $logo = imagecreatefrompng("$root/assets/images/logo-mybabyguessr.png");
    [$lx0, $ly0, $lx1, $ly1] = contentBox($logo);
    $logoW = 380;
    $logoH = (int) round(($ly1 - $ly0) * $logoW / ($lx1 - $lx0));
    imagecopyresampled($img, $logo, 64, 52, $lx0, $ly0, $logoW, $logoH, $lx1 - $lx0, $ly1 - $ly0);

    // Titre : le plus grand corps qui tient sur 2 lignes (3 si la langue l'exige), sans toucher au bas de l'image
    $ink   = color($img, '#2C1F15');
    $brand = color($img, '#C4714A');
    $muted = color($img, '#6B5D52');
    $top   = 52 + $logoH + 80;
    $maxW  = 640;
    $size  = 60;
    do {
        $lines = wrap($hero['title'], $font, $size, $maxW);
        $fits  = count($lines) <= 2 || ($size <= 48 && count($lines) <= 3);
        if (!$fits) {
            $size -= 2;
        }
    } while (!$fits && $size > 36);

    $lineHeight = (int) round($size * 1.28);
    $y = $top;
    foreach ($lines as $line) {
        imagettftext($img, $size, 0, 64, $y, $ink, $font, $line);
        $y += $lineHeight;
    }

    // Devise (sous le titre) et adresse (en bas)
    imagettftext($img, 30, 0, 64, $y + 18, $brand, $font, $hero['subtitle']);
    imagettftext($img, 24, 0, 64, HEIGHT - 44, $muted, $font, 'mybabyguessr.com');

    $out = "$root/assets/images/og/og-$locale.png";
    imagetruecolortopalette($img, true, 256);
    imagepng($img, $out, 9);
    printf("%s  %4d Ko  (%d lignes de titre, corps %d)\n", basename($out), filesize($out) / 1024, count($lines), $size);
}
