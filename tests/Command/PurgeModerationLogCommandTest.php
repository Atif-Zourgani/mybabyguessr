<?php

namespace App\Tests\Command;

use App\Entity\ModerationLog;
use App\Enum\ModerationAction;
use App\Tests\Support\DatabaseWebTestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class PurgeModerationLogCommandTest extends DatabaseWebTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $admin = $this->createUser('admin@example.test', ['ROLE_ADMIN']);
        foreach (['-13 months' => 'trop ancien', '-12 months -2 days' => 'juste trop ancien', '-11 months' => 'récent', 'now' => 'tout frais'] as $age => $label) {
            $log = new ModerationLog($admin, ModerationAction::GameHidden, ModerationLog::TARGET_GAME, 1, $label);
            (new \ReflectionProperty(ModerationLog::class, 'createdAt'))->setValue($log, new \DateTimeImmutable($age));
            $this->em->persist($log);
        }
        $this->em->flush();
    }

    private function purge(array $input = []): CommandTester
    {
        $command = (new Application(static::$kernel))->find('app:moderation:purge-log');
        $tester  = new CommandTester($command);
        $tester->execute($input);

        return $tester;
    }

    /** @return list<string> */
    private function remainingLabels(): array
    {
        $this->em->clear();
        $labels = array_map(fn (ModerationLog $l) => $l->getTargetLabel(), $this->em->getRepository(ModerationLog::class)->findBy([], ['id' => 'ASC']));
        sort($labels);

        return $labels;
    }

    public function testEntriesOlderThanTwelveMonthsAreDeleted(): void
    {
        $tester = $this->purge();

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $this->assertStringContainsString('2 entrée(s) supprimées', $tester->getDisplay());
        $this->assertSame(['récent', 'tout frais'], $this->remainingLabels());
    }

    public function testDryRunOnlyCounts(): void
    {
        $tester = $this->purge(['--dry-run' => true]);

        $this->assertStringContainsString('2 entrée(s) seraient supprimées', $tester->getDisplay());
        $this->assertCount(4, $this->remainingLabels());
    }

    public function testRetentionCanBeChanged(): void
    {
        $this->purge(['--months' => '6']);

        $this->assertSame(['tout frais'], $this->remainingLabels());
    }

    public function testInvalidRetentionIsRejectedAndNothingIsDeleted(): void
    {
        foreach (['0', '-3', 'abc', '1.5'] as $value) {
            $tester = $this->purge(['--months' => $value]);
            $this->assertSame(Command::INVALID, $tester->getStatusCode(), $value);
        }

        $this->assertCount(4, $this->remainingLabels());
    }
}
