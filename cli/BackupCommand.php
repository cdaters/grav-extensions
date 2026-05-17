<?php

declare(strict_types=1);

namespace Grav\Plugin\Console;

use Grav\Console\ConsoleCommand;
use Grav\Plugin\GravCommander\Service\FileService;
use Symfony\Component\Console\Input\InputOption;

/**
 * Create a Grav Commander backup from the CLI.
 */
class BackupCommand extends ConsoleCommand
{
    protected function configure(): void
    {
        $this
            ->setName('backup')
            ->setDescription('Create a Grav Commander backup using a configured profile.')
            ->addOption('profile', 'p', InputOption::VALUE_OPTIONAL, 'Backup profile key to run.', 'full_site')
            ->addOption('reason', 'r', InputOption::VALUE_OPTIONAL, 'Reason stored in backup metadata.', 'cli')
            ->addOption('note', null, InputOption::VALUE_OPTIONAL, 'Optional note stored in backup metadata.', '')
            ->setHelp('Creates a Grav Commander ZIP backup using one of the configured Backup Center profiles.');
    }

    protected function serve(): int
    {
        require_once dirname(__DIR__) . '/classes/Service/FileService.php';

        $profile = (string) $this->input->getOption('profile');
        $reason = (string) $this->input->getOption('reason');
        $note = (string) $this->input->getOption('note');

        try {
            $backup = (new FileService())->backupSite($reason, $profile, $note);
            $this->output->writeln('<green>Backup created:</green> ' . ($backup['name'] ?? 'unknown'));
            $this->output->writeln('Size: ' . (string) ($backup['size'] ?? 0) . ' bytes');
            return 0;
        } catch (\Throwable $e) {
            $this->output->writeln('<red>Backup failed:</red> ' . $e->getMessage());
            return 1;
        }
    }
}
