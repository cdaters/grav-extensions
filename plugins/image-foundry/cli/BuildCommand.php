<?php

declare(strict_types=1);

namespace Grav\Plugin\Console;

use Grav\Console\ConsoleCommand;
use Grav\Plugin\ImageFoundry\Service\ImageFoundryService;
use Symfony\Component\Console\Input\InputOption;

final class BuildCommand extends ConsoleCommand
{
    protected function configure(): void
    {
        $this
            ->setName('build')
            ->setDescription('Build responsive derivatives while preserving every original.')
            ->addOption('source', 's', InputOption::VALUE_OPTIONAL, 'One Grav-relative catalog source.')
            ->addOption('all', 'a', InputOption::VALUE_NONE, 'Rebuild every catalog source.')
            ->addOption('limit', 'l', InputOption::VALUE_OPTIONAL, 'Maximum sources when no source is named.');
    }

    protected function serve(): int
    {
        require_once dirname(__DIR__) . '/classes/Service/ImageFoundryService.php';
        try {
            $limit = $this->input->getOption('limit');
            $result = (new ImageFoundryService())->build(
                $this->input->getOption('source') !== null ? (string) $this->input->getOption('source') : null,
                (bool) $this->input->getOption('all'),
                $limit !== null ? (int) $limit : null
            );
            $this->output->writeln('<green>Derivative build complete.</green>');
            $this->output->writeln('Sources built: ' . $result['built_sources']);
            $this->output->writeln('Derivatives created: ' . $result['created_derivatives']);
            if ($result['failed'] !== []) {
                $this->output->writeln('<yellow>Failures: ' . count($result['failed']) . '</yellow>');
            }
            return $result['failed'] === [] ? 0 : 1;
        } catch (\Throwable $e) {
            $this->output->writeln('<red>Build failed:</red> ' . $e->getMessage());
            return 1;
        }
    }
}

