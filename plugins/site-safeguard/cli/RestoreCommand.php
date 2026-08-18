<?php

declare(strict_types=1);

namespace Grav\Plugin\Console;

use Grav\Console\ConsoleCommand;
use Grav\Plugin\SiteSafeguard\Service\SafeguardService;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;

class RestoreCommand extends ConsoleCommand
{
    protected function configure(): void
    {
        $this
            ->setName('restore')
            ->setDescription('Rollback-first full-site restore from a verified Site Safeguard stage.')
            ->addArgument('stage', InputArgument::REQUIRED, 'Verified stage identifier.')
            ->addOption('confirm', null, InputOption::VALUE_REQUIRED, 'Must be exactly: RESTORE THIS SITE');
    }

    protected function serve(): int
    {
        require_once dirname(__DIR__) . '/classes/Service/SafeguardService.php';
        try {
            $result = (new SafeguardService())->restoreStage(
                (string) $this->input->getArgument('stage'),
                (string) $this->input->getOption('confirm')
            );
            $operation = (array) ($result['operation'] ?? []);
            $this->output->writeln('<green>Full-site restore completed and verified.</green>');
            $this->output->writeln('Operation: ' . ($operation['id'] ?? 'unknown'));
            $this->output->writeln('Files verified: ' . ($operation['verified_files'] ?? 0));
            $this->output->writeln('Rollback package: ' . ($operation['rollback_package'] ?? 'unknown'));
            $this->output->writeln('Rollback stage: ' . ($operation['rollback_stage'] ?? 'unknown'));
            return 0;
        } catch (\Throwable $e) {
            $this->output->writeln('<red>Restore failed:</red> ' . $e->getMessage());
            return 1;
        }
    }
}
