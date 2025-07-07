<?php

namespace App\Repository;

use App\Entity\Subscription;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Subscription>
 */
class SubscriptionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Subscription::class);
    }

    public function findByUser(int $userId): array
    {
        return $this->createQueryBuilder('s')
            ->leftJoin('s.feed', 'f')
            ->leftJoin('s.category', 'c')
            ->where('s.user = :userId')
            ->setParameter('userId', $userId)
            ->orderBy('c.name', 'ASC')
            ->addOrderBy('f.title', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findByUserAndFeed(int $userId, int $feedId): ?Subscription
    {
        return $this->findOneBy(['user' => $userId, 'feed' => $feedId]);
    }
}