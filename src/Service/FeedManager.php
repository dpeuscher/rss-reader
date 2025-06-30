<?php

namespace App\Service;

use App\Entity\Feed;
use App\Entity\Article;
use App\Entity\User;
use App\Entity\Subscription;
use Doctrine\ORM\EntityManagerInterface;

class FeedManager
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private FeedParser $feedParser
    ) {}

    public function addFeed(string $feedUrl): Feed
    {
        $existingFeed = $this->entityManager->getRepository(Feed::class)
            ->findOneBy(['url' => $feedUrl]);

        if ($existingFeed) {
            return $existingFeed;
        }

        try {
            $feedData = $this->feedParser->parseFeed($feedUrl);
            
            $feed = new Feed();
            $feed->setUrl($feedUrl);
            $this->feedParser->updateFeedFromData($feed, $feedData);
            
            $this->entityManager->persist($feed);
            $this->entityManager->flush();

            $this->updateFeedArticles($feed, $feedData['articles']);

            return $feed;
        } catch (\Exception $e) {
            throw new \Exception('Failed to add feed: ' . $e->getMessage());
        }
    }

    public function subscribeFeedToUser(Feed $feed, User $user, ?string $customTitle = null): Subscription
    {
        $existingSubscription = $this->entityManager->getRepository(Subscription::class)
            ->findOneBy(['user' => $user, 'feed' => $feed]);

        if ($existingSubscription) {
            return $existingSubscription;
        }

        $subscription = new Subscription();
        $subscription->setUser($user)
                    ->setFeed($feed)
                    ->setTitle($customTitle);

        $this->entityManager->persist($subscription);
        $this->entityManager->flush();

        return $subscription;
    }

    public function updateFeed(Feed $feed): void
    {
        try {
            $feedData = $this->feedParser->parseFeed($feed->getUrl());
            $this->feedParser->updateFeedFromData($feed, $feedData);
            
            $this->entityManager->flush();
            
            $this->updateFeedArticles($feed, $feedData['articles']);
        } catch (\Exception $e) {
            $feed->setActive(false);
            $this->entityManager->flush();
            throw new \Exception('Failed to update feed: ' . $e->getMessage());
        }
    }

    private function updateFeedArticles(Feed $feed, array $articlesData): void
    {
        $existingGuids = $this->entityManager->getRepository(Article::class)
            ->createQueryBuilder('a')
            ->select('a.guid')
            ->where('a.feed = :feed')
            ->setParameter('feed', $feed)
            ->getQuery()
            ->getSingleColumnResult();

        $existingGuids = array_flip($existingGuids);

        foreach ($articlesData as $articleData) {
            if (!isset($existingGuids[$articleData['guid']])) {
                $article = $this->feedParser->createArticleFromData($articleData, $feed);
                $this->entityManager->persist($article);
            }
        }

        $this->entityManager->flush();
    }

    public function getUserFeeds(User $user): array
    {
        return $this->entityManager->getRepository(Subscription::class)
            ->createQueryBuilder('s')
            ->innerJoin('s.feed', 'f')
            ->where('s.user = :user')
            ->andWhere('f.active = true')
            ->setParameter('user', $user)
            ->orderBy('s.title', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function getUserArticles(User $user, ?Feed $feed = null, bool $unreadOnly = false): array
    {
        $qb = $this->entityManager->getRepository(Article::class)
            ->createQueryBuilder('a')
            ->innerJoin('a.feed', 'f')
            ->innerJoin('f.subscriptions', 's')
            ->where('s.user = :user')
            ->andWhere('f.active = true')
            ->setParameter('user', $user);

        if ($feed) {
            $qb->andWhere('a.feed = :feed')
               ->setParameter('feed', $feed);
        }

        if ($unreadOnly) {
            $qb->leftJoin('a.readByUsers', 'r', 'WITH', 'r.user = :user')
               ->andWhere('r.id IS NULL');
        }

        return $qb->orderBy('a.publishedAt', 'DESC')
                  ->setMaxResults(50)
                  ->getQuery()
                  ->getResult();
    }

    public function markArticleAsRead(Article $article, User $user): void
    {
        if (!$article->isReadByUser($user)) {
            $readArticle = new \App\Entity\ReadArticle();
            $readArticle->setUser($user)
                       ->setArticle($article);
            
            $this->entityManager->persist($readArticle);
            $this->entityManager->flush();
        }
    }

    public function getUnreadCount(User $user, ?Feed $feed = null): int
    {
        $qb = $this->entityManager->getRepository(Article::class)
            ->createQueryBuilder('a')
            ->select('COUNT(a.id)')
            ->innerJoin('a.feed', 'f')
            ->innerJoin('f.subscriptions', 's')
            ->leftJoin('a.readByUsers', 'r', 'WITH', 'r.user = :user')
            ->where('s.user = :user')
            ->andWhere('f.active = true')
            ->andWhere('r.id IS NULL')
            ->setParameter('user', $user);

        if ($feed) {
            $qb->andWhere('a.feed = :feed')
               ->setParameter('feed', $feed);
        }

        return (int) $qb->getQuery()->getSingleScalarResult();
    }
}