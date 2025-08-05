<?php

namespace App\Repository;

use App\Entity\AiArticleSummary;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AiArticleSummary>
 */
class AiArticleSummaryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AiArticleSummary::class);
    }

    public function save(AiArticleSummary $entity, bool $flush = false): void
    {
        $this->getEntityManager()->persist($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(AiArticleSummary $entity, bool $flush = false): void
    {
        $this->getEntityManager()->remove($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function findLatestByArticle(int $articleId): ?AiArticleSummary
    {
        return $this->createQueryBuilder('a')
            ->andWhere('a.article = :articleId')
            ->setParameter('articleId', $articleId)
            ->orderBy('a.createdAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findByProvider(string $provider): array
    {
        return $this->createQueryBuilder('a')
            ->andWhere('a.aiProvider = :provider')
            ->setParameter('provider', $provider)
            ->orderBy('a.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function getProcessingStats(): array
    {
        return $this->createQueryBuilder('a')
            ->select('COUNT(a.id) as total_summaries')
            ->addSelect('AVG(a.processingTime) as avg_processing_time')
            ->addSelect('a.aiProvider')
            ->groupBy('a.aiProvider')
            ->getQuery()
            ->getResult();
    }
}