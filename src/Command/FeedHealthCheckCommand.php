<?php

namespace App\Command;

use App\Service\FeedHealthMonitor;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:feed:health-check',
    description: 'Check the health status of all RSS feeds',
)]
class FeedHealthCheckCommand extends Command
{
    public function __construct(
        private FeedHealthMonitor $healthMonitor
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('feed-id', null, InputOption::VALUE_OPTIONAL, 'Check specific feed ID only')
            ->addOption('cleanup', null, InputOption::VALUE_NONE, 'Clean up old health logs (30+ days)')
            ->addOption('summary', null, InputOption::VALUE_NONE, 'Show health summary only')
            ->setHelp('This command checks the health status of RSS feeds and logs the results.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if ($input->getOption('cleanup')) {
            return $this->cleanupOldLogs($io);
        }

        if ($input->getOption('summary')) {
            return $this->showHealthSummary($io);
        }

        $feedId = $input->getOption('feed-id');
        
        if ($feedId) {
            return $this->checkSpecificFeed($io, (int) $feedId);
        }

        return $this->checkAllFeeds($io);
    }

    private function checkAllFeeds(SymfonyStyle $io): int
    {
        $io->title('RSS Feed Health Check');
        $io->text('Checking health of all active feeds...');

        $startTime = microtime(true);
        $results = $this->healthMonitor->checkAllFeeds();
        $duration = round(microtime(true) - $startTime, 2);

        $healthy = 0;
        $warning = 0;
        $unhealthy = 0;

        foreach ($results as $result) {
            switch ($result->getStatus()) {
                case 'healthy':
                    $healthy++;
                    break;
                case 'warning':
                    $warning++;
                    break;
                case 'unhealthy':
                    $unhealthy++;
                    break;
            }
        }

        $io->success("Health check completed in {$duration}s");
        
        $io->table(
            ['Status', 'Count'],
            [
                ['<fg=green>Healthy</fg=green>', $healthy],
                ['<fg=yellow>Warning</fg=yellow>', $warning],
                ['<fg=red>Unhealthy</fg=red>', $unhealthy],
                ['<fg=blue>Total</fg=blue>', count($results)],
            ]
        );

        if ($unhealthy > 0) {
            $io->warning("Found {$unhealthy} unhealthy feeds that may need attention");
            return Command::FAILURE;
        }

        if ($warning > 0) {
            $io->note("Found {$warning} feeds with warnings");
        }

        return Command::SUCCESS;
    }

    private function checkSpecificFeed(SymfonyStyle $io, int $feedId): int
    {
        $io->title("Checking Feed #{$feedId}");
        
        // This would need to be implemented to check a specific feed
        $io->error('Specific feed checking not yet implemented');
        
        return Command::FAILURE;
    }

    private function showHealthSummary(SymfonyStyle $io): int
    {
        $summary = $this->healthMonitor->getFeedHealthSummary();
        
        $io->title('Feed Health Summary');
        $io->table(
            ['Status', 'Count'],
            [
                ['<fg=green>Healthy</fg=green>', $summary['healthy']],
                ['<fg=yellow>Warning</fg=yellow>', $summary['warning']],
                ['<fg=red>Unhealthy</fg=red>', $summary['unhealthy']],
                ['<fg=blue>Total</fg=blue>', $summary['total']],
            ]
        );

        return Command::SUCCESS;
    }

    private function cleanupOldLogs(SymfonyStyle $io): int
    {
        $io->title('Cleaning up old health logs');
        
        $deleted = $this->healthMonitor->cleanupOldHealthLogs(30);
        
        if ($deleted > 0) {
            $io->success("Deleted {$deleted} old health log entries");
        } else {
            $io->info('No old health logs to clean up');
        }

        return Command::SUCCESS;
    }
}