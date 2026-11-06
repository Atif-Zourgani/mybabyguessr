<?php

namespace App\Twig;

use App\Service\GameScorer;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

class GameExtension extends AbstractExtension
{
    public function __construct(private readonly GameScorer $scorer)
    {
    }

    public function getFilters(): array
    {
        return [
            // Clé de comparaison de prénoms, identique à celle du scoring côté PHP
            new TwigFilter('name_key', $this->scorer->normalizeName(...)),
        ];
    }
}
