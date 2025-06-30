<?php

namespace App\Service;

use App\Entity\Feed;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

class FeedUpdateService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private FeedManager $feedManager,
        private LoggerInterface $logger
    ) {}

    public function updateAllFeeds(): void
    {
        $feeds = $this->entityManager->getRepository(Feed::class)
            ->createQueryBuilder('f')
            ->where('f.active = true')
            ->getQuery()
            ->getResult();

        $this->logger->info('Starting feed update process', ['feedCount' => count($feeds)]);

        $successCount = 0;
        $errorCount = 0;

        foreach ($feeds as $feed) {
            try {
                $this->feedManager->updateFeed($feed);
                $successCount++;
                $this->logger->info('Feed updated successfully', ['feedId' => $feed->getId(), 'feedTitle' => $feed->getTitle()]);
            } catch (\Exception $e) {
                $errorCount++;
                $this->logger->error('Failed to update feed', [
                    'feedId' => $feed->getId(),
                    'feedTitle' => $feed->getTitle(),
                    'error' => $e->getMessage()
                ]);
            }
        }

        $this->logger->info('Feed update process completed', [
            'successCount' => $successCount,
            'errorCount' => $errorCount
        ]);
    }

    public function updateFeedsSinceLastUpdate(\DateTimeImmutable $since): void
    {
        $feeds = $this->entityManager->getRepository(Feed::class)
            ->createQueryBuilder('f')
            ->where('f.active = true')
            ->andWhere('f.lastFetched < :since OR f.lastFetched IS NULL')
            ->setParameter('since', $since)
            ->getQuery()
            ->getResult();

        foreach ($feeds as $feed) {
            try {
                $this->feedManager->updateFeed($feed);
            } catch (\Exception $e) {
                $this->logger->error('Failed to update feed', [
                    'feedId' => $feed->getId(),
                    'error' => $e->getMessage()
                ]);
            }
        }
    }
}