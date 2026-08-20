<?php

declare(strict_types=1);

namespace Grav\Plugin\Console;

use Grav\Console\ConsoleCommand;
use Grav\Plugin\SiteWorkshop\Service\IconBenchService;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;

final class IconsCommand extends ConsoleCommand
{
    protected function configure(): void
    {
        $this->setName('icons')
            ->setDescription('List safe SVG icons discovered by Site Workshop.')
            ->addArgument('query', InputArgument::OPTIONAL, 'Optional icon or pack search text.', '')
            ->addOption('pack', 'p', InputOption::VALUE_REQUIRED, 'Limit results to one pack.', '')
            ->addOption('limit', 'l', InputOption::VALUE_REQUIRED, 'Maximum references to print.', '100');
    }

    protected function serve(): int
    {
        require_once dirname(__DIR__) . '/classes/Service/IconBenchService.php';

        try {
            $limit = max(1, min(100, (int) $this->input->getOption('limit')));
            $result = (new IconBenchService())->search(
                (string) $this->input->getArgument('query'),
                (string) $this->input->getOption('pack'),
                1,
                $limit
            );

            foreach ($result['items'] as $item) {
                $this->output->writeln(sprintf(
                    '<info>%s</info>  %s',
                    $item['reference'],
                    $item['shortcode']
                ));
            }
            $this->output->writeln(sprintf(
                '<green>%d icon%s matched.</green>',
                $result['pagination']['total'],
                $result['pagination']['total'] === 1 ? '' : 's'
            ));

            return 0;
        } catch (\Throwable $e) {
            $this->output->writeln('<red>Icon discovery failed:</red> ' . $e->getMessage());
            return 1;
        }
    }
}
