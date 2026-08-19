<?php

declare(strict_types=1);

namespace Grav\Plugin\Console;

use Grav\Console\ConsoleCommand;
use Grav\Plugin\RevisionLedger\Service\RevisionLedgerService;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;

final class RestoreCommand extends ConsoleCommand
{
    protected function configure(): void
    {
        $this->setName('restore')->setDescription('Restore one page revision after creating a safety checkpoint.')
            ->addArgument('revision', InputArgument::REQUIRED, 'Revision identifier.')
            ->addOption('confirm', null, InputOption::VALUE_REQUIRED, 'Must be exactly: RESTORE PAGE');
    }

    protected function serve(): int
    {
        require_once dirname(__DIR__) . '/classes/Service/RevisionLedgerService.php';
        try {
            $result = (new RevisionLedgerService())->restore(
                (string) $this->input->getArgument('revision'),
                (string) $this->input->getOption('confirm'),
                'CLI operator'
            );
            $this->output->writeln('<green>' . $result['message'] . '</green>');
            $this->output->writeln('Safety checkpoint: ' . $result['safety_checkpoint']['id']);
            return 0;
        } catch (\Throwable $e) {
            $this->output->writeln('<red>Restore failed:</red> ' . $e->getMessage());
            return 1;
        }
    }
}
