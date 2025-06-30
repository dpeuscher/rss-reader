<?php

namespace App\Command;

use App\Service\FeedUpdateService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:update-feeds',
    description: 'Update all RSS feeds',
)]
class UpdateFeedsCommand extends Command
{
    public function __construct(
        private FeedUpdateService $feedUpdateService
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $io->title('RSS Feed Update');
        $io->text('Starting feed update process...');

        try {
            $this->feedUpdateService->updateAllFeeds();
            $io->success('All feeds updated successfully!');
            return Command::SUCCESS;
        } catch (\Exception $e) {
            $io->error('Feed update failed: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }
}