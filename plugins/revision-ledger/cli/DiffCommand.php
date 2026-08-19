<?php

declare(strict_types=1);

namespace Grav\Plugin\Console;

use Grav\Console\ConsoleCommand;
use Grav\Plugin\RevisionLedger\Service\RevisionLedgerService;
use Symfony\Component\Console\Input\InputArgument;

final class DiffCommand extends ConsoleCommand
{
    protected function configure(): void
    {
        $this->setName('diff')->setDescription('Compare a retained revision with the current page file.')
            ->addArgument('revision', InputArgument::REQUIRED, 'Revision identifier.');
    }

    protected function serve(): int
    {
        require_once dirname(__DIR__) . '/classes/Service/RevisionLedgerService.php';
        try {
            $result = (new RevisionLedgerService())->compare((string) $this->input->getArgument('revision'));
            $this->output->writeln($result['unified']);
            return $result['changed'] ? 2 : 0;
        } catch (\Throwable $e) {
            $this->output->writeln('<red>Diff failed:</red> ' . $e->getMessage());
            return 1;
        }
    }
}
