<?php

declare(strict_types=1);

namespace Grav\Plugin\Console;

use Grav\Console\ConsoleCommand;
use Grav\Plugin\LanternSearch\Service\SearchIndexService;
use Symfony\Component\Console\Input\InputOption;

final class IndexCommand extends ConsoleCommand
{
    protected function configure(): void
    {
        $this->setName('index')->setDescription('Build the public Lantern Search index.')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Rebuild every document instead of reusing unchanged records.');
    }

    protected function serve(): int
    {
        require_once dirname(__DIR__) . '/classes/Service/SearchIndexService.php';
        try {
            $result = (new SearchIndexService())->build((bool) $this->input->getOption('force'));
            $this->output->writeln(sprintf('<green>Indexed %d public pages.</green> Updated %d, reused %d, removed %d, excluded %d.', $result['indexed_pages'], $result['updated'], $result['reused'], $result['removed'], $result['excluded']));
            return 0;
        } catch (\Throwable $e) {
            $messages = [];
            for ($current = $e; $current !== null; $current = $current->getPrevious()) {
                $messages[] = sprintf('%s: %s', $current::class, $current->getMessage());
            }
            $this->output->writeln('<red>Index failed:</red> ' . implode(' <- ', $messages));
            if ($this->output->isVerbose()) {
                $this->output->writeln($e->getTraceAsString());
            }
            return 1;
        }
    }
}
