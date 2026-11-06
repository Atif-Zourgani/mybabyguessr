<?php

namespace App\Tests\Service;

use App\Entity\Game;
use App\Entity\Guess;
use App\Enum\AnswerGender;
use App\Enum\NameMode;
use App\Service\GameScorer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class GameScorerTest extends TestCase
{
    private GameScorer $scorer;

    protected function setUp(): void
    {
        $this->scorer = new GameScorer();
    }

    // ── Normalisation des prénoms ─────────────────────────────────

    #[DataProvider('equivalentNames')]
    public function testEquivalentNamesShareTheSameKey(string $a, string $b): void
    {
        $this->assertSame($this->scorer->normalizeName($a), $this->scorer->normalizeName($b));
        $this->assertTrue($this->scorer->sameName($a, $b));
    }

    public static function equivalentNames(): iterable
    {
        yield 'accent' => ['Léa', 'lea'];
        yield 'casse et espaces' => ['  ÉLOÏSE ', 'eloise'];
        yield 'tiret et espace' => ['Jean-Pierre', 'Jean Pierre'];
        yield 'apostrophe' => ["D'Artagnan", 'dartagnan'];
        yield 'accent final et casse' => ['Chloé', 'CHLOE'];
        yield 'non latin' => ['Анна', 'анна'];
    }

    #[DataProvider('differentNames')]
    public function testDifferentNamesStayDifferent(string $a, string $b): void
    {
        $this->assertFalse($this->scorer->sameName($a, $b));
    }

    public static function differentNames(): iterable
    {
        yield ['Anna', 'Anne'];
        yield ['Léo', 'Léon'];
        yield ['Marie', 'Marie-Anne'];
    }

    public function testEmptyOrNullNamesNeverMatch(): void
    {
        $this->assertFalse($this->scorer->sameName(null, 'Léa'));
        $this->assertFalse($this->scorer->sameName('Léa', null));
        $this->assertFalse($this->scorer->sameName('', ''));
        $this->assertSame('', $this->scorer->normalizeName(null));
    }

    public function testNameMadeOnlyOfSymbolsStillComparable(): void
    {
        $this->assertTrue($this->scorer->sameName('!!!', '!!!'));
        $this->assertFalse($this->scorer->sameName('!!!', '???'));
    }

    public function testNameFrequenciesMergeSpellingsAndKeepTheMostCommon(): void
    {
        $guesses = [
            $this->guess('p1', ['name' => 'Léa']),
            $this->guess('p2', ['name' => 'lea']),
            $this->guess('p3', ['name' => 'Léa']),
            $this->guess('p4', ['name' => 'Anna']),
            $this->guess('p5', ['name' => 'Anna']),
            $this->guess('p6', ['name' => 'Zoé']),
        ];

        $this->assertSame(['Léa' => 3, 'Anna' => 2, 'Zoé' => 1], $this->scorer->nameFrequencies($guesses));
    }

    public function testNameFrequenciesPreferCapitalisedAccentedSpellingOnTies(): void
    {
        $guesses = [
            $this->guess('p1', ['name' => 'lea']),
            $this->guess('p2', ['name' => 'Léa']),
        ];

        $this->assertSame(['Léa' => 2], $this->scorer->nameFrequencies($guesses));
    }

    // ── Gagnants (page publique) ──────────────────────────────────

    public function testGenderWinnersAreChronological(): void
    {
        $game = (new Game())->setGuessGender(true)->setAnswerGender(AnswerGender::Girl);

        $boy   = $this->guess('boy', ['gender' => AnswerGender::Boy], '2026-01-01 10:00');
        $late  = $this->guess('late', ['gender' => AnswerGender::Girl], '2026-01-01 11:00');
        $early = $this->guess('early', ['gender' => AnswerGender::Girl], '2026-01-01 09:00');

        $winners = $this->scorer->winners($game, [$boy, $late, $early]);

        $this->assertSame(['early', 'late'], $this->playerNames($winners['gender']));
    }

    public function testFreeNameWinnersIgnoreAccentsAndCase(): void
    {
        $game = (new Game())->setGuessName(true)->setNameMode(NameMode::Free)->setAnswerName('Léa');

        $late  = $this->guess('late', ['name' => 'LEA'], '2026-01-02 10:00');
        $early = $this->guess('early', ['name' => 'léa'], '2026-01-01 10:00');
        $wrong = $this->guess('wrong', ['name' => 'Zoé'], '2026-01-01 08:00');

        $winners = $this->scorer->winners($game, [$late, $wrong, $early]);

        $this->assertSame(['early', 'late'], $this->playerNames($winners['name']));
        $this->assertArrayNotHasKey('attempts', $winners['name'][0]);
    }

    public function testHintsNameWinnersAreOrderedByAttempts(): void
    {
        $game = (new Game())->setGuessName(true)->setNameMode(NameMode::Hints)->setAnswerName('Léa');

        $slow   = $this->guess('slow', ['name' => 'Léa', 'hints' => 3], '2026-01-01 08:00');
        $fast   = $this->guess('fast', ['name' => 'Léa', 'hints' => 1], '2026-01-01 12:00');
        $middle = $this->guess('middle', ['name' => 'Léa', 'hints' => 2], '2026-01-01 09:00');
        $lost   = $this->guess('lost', ['name' => 'Zoé', 'hints' => 3], '2026-01-01 07:00');

        $winners = $this->scorer->winners($game, [$slow, $fast, $middle, $lost]);

        $this->assertSame(['fast', 'middle', 'slow'], $this->playerNames($winners['name']));
        $this->assertSame([1, 2, 3], array_column($winners['name'], 'attempts'));
    }

    public function testClosestWeightsAreLimitedToThreeWithDiff(): void
    {
        $game = (new Game())->setGuessWeight(true)->setAnswerWeight(3500);

        $guesses = [
            $this->guess('a', ['weight' => 3000]),
            $this->guess('b', ['weight' => 3450]),
            $this->guess('c', ['weight' => 3600]),
            $this->guess('d', ['weight' => 3500]),
            $this->guess('e', ['weight' => 4200]),
        ];

        $winners = $this->scorer->winners($game, $guesses);

        $this->assertSame(['d', 'b', 'c'], $this->playerNames($winners['weight']));
        $this->assertSame([0, 50, 100], array_column($winners['weight'], 'diff'));
    }

    public function testClosestDatesUseTheDifferenceInSeconds(): void
    {
        $game = (new Game())->setGuessDate(true)->setAnswerDate(new \DateTimeImmutable('2026-03-10 12:00'));

        $winners = $this->scorer->winners($game, [
            $this->guess('far', ['date' => '2026-03-20 12:00']),
            $this->guess('near', ['date' => '2026-03-10 13:00']),
        ]);

        $this->assertSame(['near', 'far'], $this->playerNames($winners['date']));
        $this->assertSame(3600, $winners['date'][0]['diff']);
    }

    public function testCategoriesWithoutAnswerAreSkipped(): void
    {
        $game = (new Game())->setGuessGender(true)->setGuessName(true)->setGuessWeight(true);

        $winners = $this->scorer->winners($game, [
            $this->guess('a', ['gender' => AnswerGender::Boy, 'name' => 'Léo', 'weight' => 3200]),
        ]);

        $this->assertSame([], $winners);
    }

    // ── Classement (vue organisateur) ─────────────────────────────

    public function testRankCountsOnePointPerWonCategoryAndSortsByScoreThenDate(): void
    {
        $game = (new Game())
            ->setGuessGender(true)->setAnswerGender(AnswerGender::Boy)
            ->setGuessWeight(true)->setAnswerWeight(3500);

        $a = $this->guess('a', ['gender' => AnswerGender::Boy, 'weight' => 3400], '2026-01-01 09:00');
        $b = $this->guess('b', ['gender' => AnswerGender::Boy, 'weight' => 3500], '2026-01-01 10:00');
        $c = $this->guess('c', ['gender' => AnswerGender::Girl, 'weight' => 3500], '2026-01-01 08:00');
        $d = $this->guess('d', ['gender' => AnswerGender::Girl, 'weight' => 3000], '2026-01-01 07:00');

        $ranking = $this->scorer->rank($game, [$a, $b, $c, $d]);

        $this->assertSame(2, $ranking['max']);
        $this->assertSame(['b', 'c', 'a', 'd'], array_map(fn ($i) => $i['guess']->getPlayerName(), $ranking['items']));
        $this->assertSame([2, 1, 1, 0], array_column($ranking['items'], 'score'));
    }

    public function testRankGivesThePointToEveryTiedClosestGuess(): void
    {
        $game = (new Game())->setGuessHeight(true)->setAnswerHeight(500);

        $ranking = $this->scorer->rank($game, [
            $this->guess('a', ['height' => 490]),
            $this->guess('b', ['height' => 510]),
            $this->guess('c', ['height' => 450]),
        ]);

        $this->assertSame([1, 1, 0], array_column($ranking['items'], 'score'));
    }

    public function testRankScoresNameWithTheSameRuleAsWinners(): void
    {
        $game = (new Game())->setGuessName(true)->setNameMode(NameMode::Free)->setAnswerName('Léa');

        $ranking = $this->scorer->rank($game, [
            $this->guess('a', ['name' => 'lea']),
            $this->guess('b', ['name' => 'Zoé']),
        ]);

        $this->assertSame(1, $ranking['max']);
        $this->assertSame([1, 0], array_column($ranking['items'], 'score'));
    }

    public function testRankWithoutAnyAnswerHasNoPoints(): void
    {
        $game = (new Game())->setGuessGender(true)->setGuessWeight(true);

        $ranking = $this->scorer->rank($game, [
            $this->guess('a', ['gender' => AnswerGender::Boy, 'weight' => 3200]),
        ]);

        $this->assertSame(0, $ranking['max']);
        $this->assertSame([0], array_column($ranking['items'], 'score'));
    }

    // ── Helpers ───────────────────────────────────────────────────

    /**
     * @param array{gender?: AnswerGender, name?: string, hints?: int, date?: string, weight?: int, height?: int} $data
     */
    private function guess(string $player, array $data = [], string $createdAt = '2026-01-01 12:00'): Guess
    {
        $guess = (new Guess())->setPlayerName($player);

        if (isset($data['gender'])) {
            $guess->setGuessGender($data['gender']);
        }
        if (isset($data['name'])) {
            $guess->setGuessName($data['name']);
        }
        if (isset($data['hints'])) {
            $guess->setNameHintsUsed($data['hints']);
        }
        if (isset($data['date'])) {
            $guess->setGuessDate(new \DateTimeImmutable($data['date']));
        }
        if (isset($data['weight'])) {
            $guess->setGuessWeight($data['weight']);
        }
        if (isset($data['height'])) {
            $guess->setGuessHeight($data['height']);
        }

        // createdAt est posé par le constructeur et n'a pas de setter
        (new \ReflectionProperty(Guess::class, 'createdAt'))->setValue($guess, new \DateTimeImmutable($createdAt));

        return $guess;
    }

    /**
     * @param list<array{guess: Guess}> $entries
     * @return list<string>
     */
    private function playerNames(array $entries): array
    {
        return array_map(fn (array $e) => $e['guess']->getPlayerName(), $entries);
    }
}
