<?php

namespace App\Command;

use App\Repository\ModerationLogRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Purge du journal de modération : la politique de confidentialité annonce 12 mois de conservation.
 * À planifier une fois par jour (cron), par exemple :
 *   php bin/console app:moderation:purge-log --env=prod
 */
#[AsCommand(
    name: 'app:moderation:purge-log',
    description: 'Supprime les entrées du journal de modération plus anciennes que la durée de conservation (12 mois).',
)]
final class PurgeModerationLogCommand extends Command
{
    public const RETENTION_MONTHS = 12;

    public function __construct(private readonly ModerationLogRepository $logs)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('months', 'm', InputOption::VALUE_REQUIRED, 'Durée de conservation en mois', (string) self::RETENTION_MONTHS)
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Compte les entrées concernées sans rien supprimer');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io     = new SymfonyStyle($input, $output);
        $months = (string) $input->getOption('months');

        if (!ctype_digit($months) || (int) $months < 1) {
            $io->error('--months doit être un nombre entier de mois supérieur ou égal à 1.');

            return Command::INVALID;
        }

        $before = new \DateTimeImmutable(sprintf('-%d months', (int) $months));

        if ($input->getOption('dry-run')) {
            $io->success(sprintf('%d entrée(s) seraient supprimées (antérieures au %s).', $this->logs->countOlderThan($before), $before->format('d/m/Y')));

            return Command::SUCCESS;
        }

        $io->success(sprintf('%d entrée(s) supprimées (antérieures au %s).', $this->logs->purgeOlderThan($before), $before->format('d/m/Y')));

        return Command::SUCCESS;
    }
}
