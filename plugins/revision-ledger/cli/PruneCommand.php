<?php

declare(strict_types=1);

namespace Grav\Plugin\Console;

use Grav\Console\ConsoleCommand;
use Grav\Plugin\RevisionLedger\Service\RevisionLedgerService;
use Symfony\Component\Console\Input\InputOption;

final class PruneCommand extends ConsoleCommand
{
    protected function configure(): void
    {
        $this->setName('prune')->setDescription('Preview or apply configured revision retention.')
            ->addOption('route', null, InputOption::VALUE_REQUIRED, 'Limit pruning to one route.')
            ->addOption('execute', null, InputOption::VALUE_NONE, 'Delete eligible revisions; without this flag only preview.');
    }

    protected function serve(): int
    {
        require_once dirname(__DIR__) . '/classes/Service/RevisionLedgerService.php';
        try {
            $route = trim((string) $this->input->getOption('route'));
            $result = (new RevisionLedgerService())->prune($route !== '' ? $route : null, (bool) $this->input->getOption('execute'));
            $this->output->writeln(sprintf('%s %d revision(s).', $result['executed'] ? 'Removed' : 'Would remove', $result['count']));
            foreach ($result['revisions'] as $item) {
                $this->output->writeln('  ' . $item['id'] . '  ' . $item['route']);
            }
            return 0;
        } catch (\Throwable $e) {
            $this->output->writeln('<red>Prune failed:</red> ' . $e->getMessage());
            return 1;
        }
    }
}
