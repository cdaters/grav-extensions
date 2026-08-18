<?php

declare(strict_types=1);

namespace Grav\Plugin\Console;

use Grav\Console\ConsoleCommand;
use Grav\Plugin\SiteSafeguard\Service\SafeguardService;
use Symfony\Component\Console\Input\InputArgument;

class InspectCommand extends ConsoleCommand
{
    protected function configure(): void
    {
        $this
            ->setName('inspect')
            ->setDescription('Validate a retained Site Safeguard package and every checksum.')
            ->addArgument('package', InputArgument::REQUIRED, 'Bare package filename from the protected package directory.');
    }

    protected function serve(): int
    {
        require_once dirname(__DIR__) . '/classes/Service/SafeguardService.php';
        try {
            $result = (new SafeguardService())->inspectPackage((string) $this->input->getArgument('package'));
            $this->output->writeln($result['valid'] ? '<green>Package is valid.</green>' : '<red>Package is invalid.</red>');
            $this->output->writeln('SHA-256: ' . $result['package_sha256']);
            $this->output->writeln('Files checked: ' . $result['archive_files']);
            $this->output->writeln('Checksum records: ' . $result['checksum_file_count']);
            foreach ($result['errors'] as $error) {
                $this->output->writeln('<red>ERROR:</red> ' . $error);
            }
            foreach ($result['warnings'] as $warning) {
                $this->output->writeln('<yellow>WARNING:</yellow> ' . $warning);
            }
            return $result['valid'] ? 0 : 2;
        } catch (\Throwable $e) {
            $this->output->writeln('<red>Inspection failed:</red> ' . $e->getMessage());
            return 1;
        }
    }
}
