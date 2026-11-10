<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\AiAssistant\Console\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Zero\AiAssistant\Model\Retention;

/**
 * bin/magento zero:ai:purge: what the assistant keeps past its time purged now (the cron does it daily).
 */
class PurgeCommand extends Command
{
    public function __construct(private readonly Retention $retention)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('zero:ai:purge')
            ->setDescription('Purge the AI Assistant\'s and the Claude connector\'s data past its time (the cron does it daily)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $days = $this->retention->days();
        $output->writeln($days ? sprintf('Keeping %d days.', $days) : 'Keeping everything (Keep Data For is 0): only expired codes and drafts go.');
        foreach ($this->retention->purge() as $kind => $count) {
            $output->writeln(sprintf('%s: %d removed', str_replace('_', ' ', $kind), $count));
        }

        return Command::SUCCESS;
    }
}
