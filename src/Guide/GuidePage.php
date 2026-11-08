<?php

namespace App\Guide;

/** Un guide dans une langue donnée : métadonnées pour les moteurs de recherche et gabarit du contenu. */
final class GuidePage
{
    public function __construct(
        public readonly string $id,
        public readonly string $locale,
        public readonly string $slug,
        public readonly string $title,
        public readonly string $description,
        public readonly string $heading,
        public readonly \DateTimeImmutable $published,
        public readonly \DateTimeImmutable $modified,
    ) {
    }

    /** Gabarit Twig du contenu, par exemple « guide/baby-shower-game.fr.html.twig ». */
    public function template(): string
    {
        return sprintf('guide/%s.%s.html.twig', $this->id, $this->locale);
    }
}
