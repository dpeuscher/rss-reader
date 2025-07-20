<?php

namespace App\Repository;

use App\Entity\Feed;
use App\Entity\FeedHealthLog;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class FeedHealthLogRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, FeedHealthLog::class);
    }

    public function findLatestByFeed(Feed $feed): ?FeedHealthLog
    {
        return $this->createQueryBuilder('fhl')
            ->andWhere('fhl.feed = :feed')
            ->setParameter('feed', $feed)
            ->orderBy('fhl.checkedAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findHealthHistoryByFeed(Feed $feed, int $limit = 50): array
    {
        return $this->createQueryBuilder('fhl')
            ->andWhere('fhl.feed = :feed')
            ->setParameter('feed', $feed)
            ->orderBy('fhl.checkedAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    public function getConsecutiveFailureCount(Feed $feed): int
    {
        $latestHealthy = $this->createQueryBuilder('fhl')
            ->andWhere('fhl.feed = :feed')
            ->andWhere('fhl.status = :status')
            ->setParameter('feed', $feed)
            ->setParameter('status', FeedHealthLog::STATUS_HEALTHY)
            ->orderBy('fhl.checkedAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        if (!$latestHealthy) {
            $allLogs = $this->findHealthHistoryByFeed($feed, 1000);
            return count($allLogs);
        }

        $failures = $this->createQueryBuilder('fhl')
            ->select('COUNT(fhl.id)')
            ->andWhere('fhl.feed = :feed')
            ->andWhere('fhl.checkedAt > :since')
            ->andWhere('fhl.status != :status')
            ->setParameter('feed', $feed)
            ->setParameter('since', $latestHealthy->getCheckedAt())
            ->setParameter('status', FeedHealthLog::STATUS_HEALTHY)
            ->getQuery()
            ->getSingleScalarResult();

        return (int) $failures;
    }

    public function deleteOldLogs(\DateTimeInterface $before): int
    {
        return $this->createQueryBuilder('fhl')
            ->delete()
            ->where('fhl.checkedAt < :before')
            ->setParameter('before', $before)
            ->getQuery()
            ->execute();
    }
}