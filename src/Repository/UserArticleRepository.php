<?php

namespace App\Repository;

use App\Entity\UserArticle;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<UserArticle>
 */
class UserArticleRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, UserArticle::class);
    }

    public function findByUserAndArticle(int $userId, int $articleId): ?UserArticle
    {
        return $this->findOneBy(['user' => $userId, 'article' => $articleId]);
    }

    public function getUnreadCount(int $userId): int
    {
        return $this->createQueryBuilder('ua')
            ->select('COUNT(ua.id)')
            ->where('ua.user = :userId')
            ->andWhere('ua.isRead = false')
            ->setParameter('userId', $userId)
            ->getQuery()
            ->getSingleScalarResult();
    }
}