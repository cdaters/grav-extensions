<?php

declare(strict_types=1);

namespace Grav\Plugin\Console;

use Grav\Console\ConsoleCommand;
use Grav\Plugin\LanternSearch\Service\SearchIndexService;
use Symfony\Component\Console\Input\InputArgument;

final class SearchCommand extends ConsoleCommand
{
    protected function configure(): void
    {
        $this->setName('search')->setDescription('Query the Lantern Search index.')
            ->addArgument('query', InputArgument::REQUIRED, 'Search words or phrase.');
    }

    protected function serve(): int
    {
        require_once dirname(__DIR__) . '/classes/Service/SearchIndexService.php';
        try {
            $result = (new SearchIndexService())->search((string) $this->input->getArgument('query'));
            $this->output->writeln(sprintf('<green>%d result(s)</green>', $result['total']));
            foreach ($result['results'] as $item) $this->output->writeln(sprintf('  %.3f  %s  %s', $item['score'], $item['route'], $item['title']));
            return 0;
        } catch (\Throwable $e) {
            $this->output->writeln('<red>Search failed:</red> ' . $e->getMessage());
            return 1;
        }
    }
}
