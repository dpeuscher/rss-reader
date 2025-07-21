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

    public function findRecentUserActivity($user, int $limit = 50): array
    {
        return $this->createQueryBuilder('ua')
            ->join('ua.article', 'a')
            ->where('ua.user = :user')
            ->setParameter('user', $user)
            ->orderBy('ua.id', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    public function getUserFeedEngagement($user, $feed): array
    {
        $qb = $this->createQueryBuilder('ua')
            ->select('COUNT(ua.id) as total')
            ->addSelect('SUM(CASE WHEN ua.isRead = true OR ua.isStarred = true THEN 1 ELSE 0 END) as engaged')
            ->join('ua.article', 'a')
            ->where('ua.user = :user')
            ->andWhere('a.feed = :feed')
            ->setParameter('user', $user)
            ->setParameter('feed', $feed)
            ->getQuery();

        $result = $qb->getSingleResult();
        
        return [
            'total' => (int) $result['total'],
            'engaged' => (int) $result['engaged']
        ];
    }
}