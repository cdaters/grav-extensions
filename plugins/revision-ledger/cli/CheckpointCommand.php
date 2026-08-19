<?php

declare(strict_types=1);

namespace Grav\Plugin\Console;

use Grav\Console\ConsoleCommand;
use Grav\Plugin\RevisionLedger\Service\RevisionLedgerService;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;

final class CheckpointCommand extends ConsoleCommand
{
    protected function configure(): void
    {
        $this->setName('checkpoint')->setDescription('Create a named checkpoint for a page route.')
            ->addArgument('route', InputArgument::REQUIRED, 'Page route, such as /about.')
            ->addOption('reason', null, InputOption::VALUE_REQUIRED, 'Why this checkpoint is being created.', 'CLI checkpoint');
    }

    protected function serve(): int
    {
        require_once dirname(__DIR__) . '/classes/Service/RevisionLedgerService.php';
        try {
            $item = (new RevisionLedgerService())->checkpointRoute(
                (string) $this->input->getArgument('route'),
                (string) $this->input->getOption('reason'),
                'manual',
                'CLI operator'
            );
            $this->output->writeln('<green>Checkpoint retained.</green> ' . $item['id']);
            return 0;
        } catch (\Throwable $e) {
            $this->output->writeln('<red>Checkpoint failed:</red> ' . $e->getMessage());
            return 1;
        }
    }
}
