<?php

declare(strict_types=1);

namespace Grav\Plugin\Console;

use Grav\Console\ConsoleCommand;
use Grav\Plugin\RevisionLedger\Service\RevisionLedgerService;
use Symfony\Component\Console\Input\InputOption;

final class RevisionsCommand extends ConsoleCommand
{
    protected function configure(): void
    {
        $this->setName('revisions')->setDescription('List retained page revisions.')
            ->addOption('route', null, InputOption::VALUE_REQUIRED, 'Limit results to one page route.');
    }

    protected function serve(): int
    {
        require_once dirname(__DIR__) . '/classes/Service/RevisionLedgerService.php';
        try {
            $route = trim((string) $this->input->getOption('route'));
            $items = (new RevisionLedgerService())->revisions($route !== '' ? $route : null);
            foreach ($items as $item) {
                $this->output->writeln(sprintf('%s  %s  %s  %s  %s', $item['id'], $item['created_at'], $item['route'], $item['source'], $item['reason'] ?: '—'));
            }
            $this->output->writeln(sprintf('<green>%d revision(s).</green>', count($items)));
            return 0;
        } catch (\Throwable $e) {
            $this->output->writeln('<red>List failed:</red> ' . $e->getMessage());
            return 1;
        }
    }
}
