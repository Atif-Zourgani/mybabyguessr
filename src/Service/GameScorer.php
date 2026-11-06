<?php

namespace App\Service;

use App\Entity\Game;
use App\Entity\Guess;

/**
 * Règles de comparaison et de classement des pronostics.
 * Source unique utilisée par la page organisateur et la page publique de révélation.
 */
final class GameScorer
{
    private const LIGATURES = [
        'œ' => 'oe',
        'æ' => 'ae',
        'ß' => 'ss',
        'ø' => 'o',
        'ł' => 'l',
        'đ' => 'd',
    ];

    /**
     * Clé de comparaison d'un prénom : insensible à la casse, aux accents,
     * aux espaces, tirets et apostrophes ("Léa" = "lea", "Jean-Pierre" = "jean pierre").
     */
    public function normalizeName(?string $name): string
    {
        $name = mb_strtolower(trim((string) $name));
        if ($name === '') {
            return '';
        }

        $name = strtr($name, self::LIGATURES);

        if (class_exists(\Normalizer::class)) {
            $decomposed = \Normalizer::normalize($name, \Normalizer::FORM_D);
            if ($decomposed !== false) {
                $name = (string) preg_replace('/\p{Mn}+/u', '', $decomposed);
            }
        }

        $key = preg_replace('/[^\p{L}\p{N}]+/u', '', $name);

        return $key !== null && $key !== '' ? $key : $name;
    }

    public function sameName(?string $a, ?string $b): bool
    {
        $keyA = $this->normalizeName($a);

        return $keyA !== '' && $keyA === $this->normalizeName($b);
    }

    public function isCorrectName(Game $game, Guess $guess): bool
    {
        return $this->sameName($guess->getGuessName(), $game->getAnswerName());
    }

    /**
     * Fréquence des prénoms proposés, regroupés par clé normalisée.
     * Retourne [orthographe la plus courante => nombre], triée par fréquence décroissante.
     *
     * @param Guess[] $guesses
     * @return array<string, int>
     */
    public function nameFrequencies(array $guesses): array
    {
        $counts     = [];
        $spellings  = [];

        foreach ($guesses as $guess) {
            $raw = trim((string) $guess->getGuessName());
            if ($raw === '') {
                continue;
            }
            $key = $this->normalizeName($raw);
            $counts[$key]        = ($counts[$key] ?? 0) + 1;
            $spellings[$key][$raw] = ($spellings[$key][$raw] ?? 0) + 1;
        }

        arsort($counts);

        $freq = [];
        foreach ($counts as $key => $count) {
            // Orthographe la plus fréquente ; à égalité, celle qui a une majuscule et des accents
            $options = $spellings[$key];
            uksort($options, fn ($a, $b) =>
                $options[$b] <=> $options[$a]
                ?: $this->spellingScore((string) $b) <=> $this->spellingScore((string) $a)
            );
            $freq[(string) array_key_first($options)] = $count;
        }

        return $freq;
    }

    private function spellingScore(string $spelling): int
    {
        $first = mb_substr($spelling, 0, 1);

        return (mb_strtoupper($first) === $first && mb_strtolower($first) !== $first ? 2 : 0)
            + (strlen($spelling) > mb_strlen($spelling) ? 1 : 0);
    }

    /**
     * Gagnants par catégorie pour la page publique de révélation.
     *
     * @param Guess[] $guesses
     * @return array<string, list<array<string, mixed>>>
     */
    public function winners(Game $game, array $guesses): array
    {
        usort($guesses, fn (Guess $a, Guess $b) => $a->getCreatedAt() <=> $b->getCreatedAt());

        $winners = [];

        // Sexe : tous les corrects, ordre chronologique
        if ($game->isGuessGender() && $game->getAnswerGender() !== null) {
            $correct = array_values(array_filter(
                $guesses,
                fn (Guess $g) => $g->getGuessGender() === $game->getAnswerGender()
            ));
            $winners['gender'] = array_map(fn (Guess $g) => ['guess' => $g, 'diff' => null], $correct);
        }

        // Prénom : tous les corrects
        if ($game->isGuessName() && $game->getAnswerName() !== null) {
            $correct = array_values(array_filter(
                $guesses,
                fn (Guess $g) => $this->isCorrectName($game, $g)
            ));

            if ($game->getNameMode()?->value === 'hints') {
                // Indices : moins d'essais = meilleur, puis ordre chronologique
                usort($correct, fn (Guess $a, Guess $b) =>
                    $a->getNameHintsUsed() <=> $b->getNameHintsUsed()
                    ?: $a->getCreatedAt() <=> $b->getCreatedAt()
                );
                $winners['name'] = array_map(
                    fn (Guess $g) => ['guess' => $g, 'diff' => null, 'attempts' => $g->getNameHintsUsed()],
                    $correct
                );
            } else {
                // Libre : le premier à avoir trouvé est le meilleur
                $winners['name'] = array_map(fn (Guess $g) => ['guess' => $g, 'diff' => null], $correct);
            }
        }

        // Date, poids, taille : top 3 les plus proches
        if ($game->isGuessDate() && $game->getAnswerDate() !== null) {
            $closest = $this->closest(
                $guesses,
                $game->getAnswerDate()->getTimestamp(),
                fn (Guess $g) => $g->getGuessDate()?->getTimestamp()
            );
            if ($closest) {
                $winners['date'] = $closest;
            }
        }

        if ($game->isGuessWeight() && $game->getAnswerWeight() !== null) {
            $closest = $this->closest($guesses, $game->getAnswerWeight(), fn (Guess $g) => $g->getGuessWeight());
            if ($closest) {
                $winners['weight'] = $closest;
            }
        }

        if ($game->isGuessHeight() && $game->getAnswerHeight() !== null) {
            $closest = $this->closest($guesses, $game->getAnswerHeight(), fn (Guess $g) => $g->getGuessHeight());
            if ($closest) {
                $winners['height'] = $closest;
            }
        }

        return $winners;
    }

    /**
     * Classement général pour la vue organisateur : 1 point par catégorie gagnée
     * (sexe et prénom justes, valeur la plus proche en date, poids et taille).
     *
     * @param Guess[] $guesses
     * @return array{items: list<array{guess: Guess, score: int}>, max: int}
     */
    public function rank(Game $game, array $guesses): array
    {
        $hasGender = $game->isGuessGender() && $game->getAnswerGender() !== null;
        $hasName   = $game->isGuessName() && $game->getAnswerName() !== null;

        $answerDate   = $game->isGuessDate() ? $game->getAnswerDate()?->getTimestamp() : null;
        $answerWeight = $game->isGuessWeight() ? $game->getAnswerWeight() : null;
        $answerHeight = $game->isGuessHeight() ? $game->getAnswerHeight() : null;

        $dateOf   = fn (Guess $g) => $g->getGuessDate()?->getTimestamp();
        $weightOf = fn (Guess $g) => $g->getGuessWeight();
        $heightOf = fn (Guess $g) => $g->getGuessHeight();

        $minDate   = $this->minDiff($guesses, $answerDate, $dateOf);
        $minWeight = $this->minDiff($guesses, $answerWeight, $weightOf);
        $minHeight = $this->minDiff($guesses, $answerHeight, $heightOf);

        $max = (int) $hasGender + (int) $hasName
            + (int) ($answerDate !== null)
            + (int) ($answerWeight !== null)
            + (int) ($answerHeight !== null);

        $items = [];
        foreach ($guesses as $g) {
            $score = 0;
            if ($hasGender && $g->getGuessGender() === $game->getAnswerGender()) {
                $score++;
            }
            if ($hasName && $this->isCorrectName($game, $g)) {
                $score++;
            }
            if ($minDate !== null && $dateOf($g) !== null && abs($dateOf($g) - $answerDate) === $minDate) {
                $score++;
            }
            if ($minWeight !== null && $weightOf($g) !== null && abs($weightOf($g) - $answerWeight) === $minWeight) {
                $score++;
            }
            if ($minHeight !== null && $heightOf($g) !== null && abs($heightOf($g) - $answerHeight) === $minHeight) {
                $score++;
            }
            $items[] = ['guess' => $g, 'score' => $score];
        }

        usort($items, fn (array $a, array $b) =>
            $b['score'] <=> $a['score']
            ?: $a['guess']->getCreatedAt() <=> $b['guess']->getCreatedAt()
        );

        return ['items' => $items, 'max' => $max];
    }

    /**
     * @param Guess[] $guesses
     * @param callable(Guess): ?int $value
     * @return list<array{guess: Guess, diff: int}>
     */
    private function closest(array $guesses, int $answer, callable $value, int $limit = 3): array
    {
        $withValue = array_values(array_filter($guesses, fn (Guess $g) => $value($g) !== null));

        usort($withValue, fn (Guess $a, Guess $b) =>
            abs($value($a) - $answer) <=> abs($value($b) - $answer)
        );

        return array_map(
            fn (Guess $g) => ['guess' => $g, 'diff' => abs($value($g) - $answer)],
            array_slice($withValue, 0, $limit)
        );
    }

    /**
     * @param Guess[] $guesses
     * @param callable(Guess): ?int $value
     */
    private function minDiff(array $guesses, ?int $answer, callable $value): ?int
    {
        if ($answer === null) {
            return null;
        }

        $min = null;
        foreach ($guesses as $g) {
            $v = $value($g);
            if ($v === null) {
                continue;
            }
            $diff = abs($v - $answer);
            if ($min === null || $diff < $min) {
                $min = $diff;
            }
        }

        return $min;
    }
}
