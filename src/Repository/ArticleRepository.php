<?php

namespace App\Repository;

use App\Entity\Article;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Article>
 */
class ArticleRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Article::class);
    }

    public function findByUserAndFilters(int $userId, array $filters = []): array
    {
        $qb = $this->createQueryBuilder('a')
            ->leftJoin('a.userArticles', 'ua')
            ->leftJoin('a.feed', 'f')
            ->leftJoin('f.subscriptions', 's')
            ->where('s.user = :userId')
            ->setParameter('userId', $userId)
            ->orderBy('a.publishedAt', 'DESC');

        if (isset($filters['read']) && $filters['read'] !== null) {
            $qb->andWhere('ua.isRead = :read')
               ->setParameter('read', $filters['read']);
        }

        if (isset($filters['starred']) && $filters['starred'] === true) {
            $qb->andWhere('ua.isStarred = :starred')
               ->setParameter('starred', true);
        }

        if (isset($filters['feed_id'])) {
            $qb->andWhere('f.id = :feedId')
               ->setParameter('feedId', $filters['feed_id']);
        }

        return $qb->getQuery()->getResult();
    }

    public function findByGuidAndFeed(string $guid, int $feedId): ?Article
    {
        return $this->findOneBy(['guid' => $guid, 'feed' => $feedId]);
    }
}