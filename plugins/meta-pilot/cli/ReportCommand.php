<?php

declare(strict_types=1);

namespace Grav\Plugin\Console;

use Grav\Common\Grav;
use Grav\Console\ConsoleCommand;
use Grav\Plugin\MetaPilot\Service\MetaPilotService;

final class ReportCommand extends ConsoleCommand
{
    protected function configure(): void
    {
        $this->setName('report')->setDescription('Inspect public-page metadata and print a diagnostic summary.');
    }

    protected function serve(): int
    {
        require_once dirname(__DIR__) . '/classes/Service/MetaPilotService.php';
        try {
            $pages = Grav::instance()['pages'];
            if (method_exists($pages, 'init')) {
                $pages->init();
            }
            $report = (new MetaPilotService())->report();
            $summary = $report['summary'];
            $this->output->writeln('<green>Meta Pilot report complete.</green>');
            $this->output->writeln(sprintf(
                'Score: %d | Pages: %d | Sitemap: %d | Errors: %d | Warnings: %d',
                $summary['score'],
                $summary['pages'],
                $summary['sitemap_pages'],
                $summary['errors'],
                $summary['warnings']
            ));
            foreach ($report['pages'] as $page) {
                if ($page['issues'] === []) {
                    continue;
                }
                $this->output->writeln('');
                $this->output->writeln(sprintf('<yellow>%s</yellow> — %s', $page['route'], $page['title'] ?: 'Untitled'));
                foreach ($page['issues'] as $issue) {
                    $this->output->writeln(sprintf('  [%s] %s', strtoupper($issue['severity']), $issue['message']));
                }
            }
            return $summary['errors'] > 0 ? 2 : 0;
        } catch (\Throwable $e) {
            $this->output->writeln('<red>Report failed:</red> ' . $e->getMessage());
            return 1;
        }
    }
}
