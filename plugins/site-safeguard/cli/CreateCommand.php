<?php

declare(strict_types=1);

namespace Grav\Plugin\Console;

use Grav\Console\ConsoleCommand;
use Grav\Plugin\SiteSafeguard\Service\SafeguardService;
use Symfony\Component\Console\Input\InputOption;

class CreateCommand extends ConsoleCommand
{
    protected function configure(): void
    {
        $this
            ->setName('create')
            ->setDescription('Create a checksummed Site Safeguard package.')
            ->addOption('profile', 'p', InputOption::VALUE_OPTIONAL, 'Configured package profile.', 'portable_site')
            ->addOption('note', null, InputOption::VALUE_OPTIONAL, 'Operator note stored in the manifest.', '');
    }

    protected function serve(): int
    {
        require_once dirname(__DIR__) . '/classes/Service/SafeguardService.php';
        try {
            $result = (new SafeguardService())->createPackage(
                (string) $this->input->getOption('profile'),
                (string) $this->input->getOption('note')
            );
            $this->output->writeln('<green>Package created:</green> ' . $result['name']);
            $this->output->writeln('SHA-256: ' . $result['sha256']);
            $this->output->writeln('Bytes: ' . $result['size']);
            return 0;
        } catch (\Throwable $e) {
            $this->output->writeln('<red>Package creation failed:</red> ' . $e->getMessage());
            return 1;
        }
    }
}
