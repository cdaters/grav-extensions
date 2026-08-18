<?php

declare(strict_types=1);

namespace Grav\Plugin\Console;

use Grav\Console\ConsoleCommand;
use Grav\Plugin\ImageFoundry\Service\ImageFoundryService;
use Symfony\Component\Console\Input\InputOption;

final class PurgeCommand extends ConsoleCommand
{
    private const CONFIRMATION = 'PURGE GENERATED DERIVATIVES';

    protected function configure(): void
    {
        $this
            ->setName('purge')
            ->setDescription('Delete only Image Foundry generated derivatives and its catalog.')
            ->addOption('confirm', null, InputOption::VALUE_REQUIRED, 'Exact confirmation phrase.');
    }

    protected function serve(): int
    {
        require_once dirname(__DIR__) . '/classes/Service/ImageFoundryService.php';
        if ((string) $this->input->getOption('confirm') !== self::CONFIRMATION) {
            $this->output->writeln('<red>Refused.</red> Use --confirm="' . self::CONFIRMATION . '"');
            return 1;
        }
        try {
            $result = (new ImageFoundryService())->purge();
            $this->output->writeln('<green>Generated data purged.</green>');
            $this->output->writeln('Files removed: ' . $result['removed_derivatives']);
            return 0;
        } catch (\Throwable $e) {
            $this->output->writeln('<red>Purge failed:</red> ' . $e->getMessage());
            return 1;
        }
    }
}
