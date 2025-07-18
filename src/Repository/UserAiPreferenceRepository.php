<?php

namespace App\Repository;

use App\Entity\UserAiPreference;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<UserAiPreference>
 */
class UserAiPreferenceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, UserAiPreference::class);
    }

    public function save(UserAiPreference $entity, bool $flush = false): void
    {
        $this->getEntityManager()->persist($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(UserAiPreference $entity, bool $flush = false): void
    {
        $this->getEntityManager()->remove($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function findByUser(int $userId): ?UserAiPreference
    {
        return $this->createQueryBuilder('u')
            ->andWhere('u.user = :userId')
            ->setParameter('userId', $userId)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findUsersWithAiEnabled(): array
    {
        return $this->createQueryBuilder('u')
            ->andWhere('u.aiProcessingEnabled = :enabled')
            ->setParameter('enabled', true)
            ->getQuery()
            ->getResult();
    }

    public function getConsentStats(): array
    {
        return $this->createQueryBuilder('u')
            ->select('COUNT(u.id) as total_users')
            ->addSelect('SUM(CASE WHEN u.aiProcessingEnabled = true THEN 1 ELSE 0 END) as enabled_users')
            ->addSelect('SUM(CASE WHEN u.consentGivenAt IS NOT NULL THEN 1 ELSE 0 END) as consented_users')
            ->getQuery()
            ->getOneOrNullResult();
    }
}