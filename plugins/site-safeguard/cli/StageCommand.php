<?php

declare(strict_types=1);

namespace Grav\Plugin\Console;

use Grav\Console\ConsoleCommand;
use Grav\Plugin\SiteSafeguard\Service\SafeguardService;
use Symfony\Component\Console\Input\InputArgument;

class StageCommand extends ConsoleCommand
{
    protected function configure(): void
    {
        $this
            ->setName('stage')
            ->setDescription('Validate and extract a deployable package outside the running Grav root.')
            ->addArgument('package', InputArgument::REQUIRED, 'Bare package filename from the protected package directory.');
    }

    protected function serve(): int
    {
        require_once dirname(__DIR__) . '/classes/Service/SafeguardService.php';
        try {
            $result = (new SafeguardService())->stagePackage((string) $this->input->getArgument('package'));
            $this->output->writeln('<green>Verified stage created:</green> ' . $result['id']);
            $this->output->writeln('Path: ' . $result['path']);
            $this->output->writeln('<yellow>The running site was not modified.</yellow>');
            return 0;
        } catch (\Throwable $e) {
            $this->output->writeln('<red>Staging failed:</red> ' . $e->getMessage());
            return 1;
        }
    }
}
