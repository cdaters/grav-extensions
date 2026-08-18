<?php

declare(strict_types=1);

namespace Grav\Plugin\Console;

use Grav\Console\ConsoleCommand;
use Grav\Plugin\ImageFoundry\Service\ImageFoundryService;

final class ScanCommand extends ConsoleCommand
{
    protected function configure(): void
    {
        $this->setName('scan')->setDescription('Catalog configured image source roots without modifying originals.');
    }

    protected function serve(): int
    {
        require_once dirname(__DIR__) . '/classes/Service/ImageFoundryService.php';
        try {
            $result = (new ImageFoundryService())->scan();
            $this->output->writeln('<green>Catalog updated.</green>');
            $this->output->writeln('Sources: ' . $result['scanned']);
            $this->output->writeln('Stale: ' . $result['stale']);
            return 0;
        } catch (\Throwable $e) {
            $this->output->writeln('<red>Scan failed:</red> ' . $e->getMessage());
            return 1;
        }
    }
}

