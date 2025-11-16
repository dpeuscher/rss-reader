<?php

namespace App\Command;

use App\Repository\FeedRepository;
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
        private FeedHealthMonitor $healthMonitor,
        private FeedRepository $feedRepository
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
        
        $feed = $this->feedRepository->find($feedId);
        
        if (!$feed) {
            $io->error("Feed #{$feedId} not found");
            return Command::FAILURE;
        }
        
        if ($feed->getStatus() !== 'active') {
            $io->warning("Feed #{$feedId} is not active (status: {$feed->getStatus()})");
            return Command::FAILURE;
        }
        
        $io->text("Checking feed: {$feed->getTitle()} ({$feed->getUrl()})");
        
        try {
            $startTime = microtime(true);
            $result = $this->healthMonitor->checkFeedHealth($feed);
            $duration = round(microtime(true) - $startTime, 2);
            
            $io->success("Health check completed in {$duration}s");
            
            $io->table(
                ['Property', 'Value'],
                [
                    ['Status', $this->formatStatus($result->getStatus())],
                    ['Response Time', $result->getResponseTime() ? $result->getResponseTime() . 'ms' : 'N/A'],
                    ['HTTP Status', $result->getHttpStatusCode() ?? 'N/A'],
                    ['Consecutive Failures', $result->getConsecutiveFailures() ?? 0],
                    ['Error Message', $result->getErrorMessage() ?? 'None'],
                    ['Checked At', $result->getCheckedAt()->format('Y-m-d H:i:s')],
                    ['Duration', "{$duration}s"],
                ]
            );
            
            if ($result->getStatus() === 'unhealthy') {
                $io->warning('Feed is unhealthy and may need attention');
                return Command::FAILURE;
            }
            
            if ($result->getStatus() === 'warning') {
                $io->note('Feed has warnings but is functional');
            } else {
                $io->success('Feed is healthy');
            }
            
            return Command::SUCCESS;
            
        } catch (\Exception $e) {
            $io->error("Failed to check feed health: {$e->getMessage()}");
            return Command::FAILURE;
        }
    }
    
    private function formatStatus(string $status): string
    {
        return match($status) {
            'healthy' => '<fg=green>Healthy</fg=green>',
            'warning' => '<fg=yellow>Warning</fg=yellow>',
            'unhealthy' => '<fg=red>Unhealthy</fg=red>',
            default => $status
        };
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