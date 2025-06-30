<?php

namespace App\Controller;

use App\Entity\Article;
use App\Entity\Feed;
use App\Service\FeedManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api')]
class ApiController extends AbstractController
{
    public function __construct(
        private FeedManager $feedManager,
        private EntityManagerInterface $entityManager
    ) {}

    #[Route('/feeds', name: 'api_feeds', methods: ['GET'])]
    public function getFeeds(): JsonResponse
    {
        $user = $this->getUser();
        $subscriptions = $this->feedManager->getUserFeeds($user);

        $feeds = array_map(function($subscription) {
            return [
                'id' => $subscription->getFeed()->getId(),
                'title' => $subscription->getTitle(),
                'url' => $subscription->getFeed()->getUrl(),
                'link' => $subscription->getFeed()->getLink(),
                'description' => $subscription->getFeed()->getDescription(),
                'unreadCount' => $this->feedManager->getUnreadCount($this->getUser(), $subscription->getFeed())
            ];
        }, $subscriptions);

        return $this->json([
            'feeds' => $feeds,
            'totalUnreadCount' => $this->feedManager->getUnreadCount($user)
        ]);
    }

    #[Route('/articles', name: 'api_articles', methods: ['GET'])]
    public function getArticles(Request $request): JsonResponse
    {
        $user = $this->getUser();
        $feedId = $request->query->get('feed_id');
        $unreadOnly = $request->query->getBoolean('unread_only');
        $limit = min($request->query->getInt('limit', 20), 100);

        $feed = null;
        if ($feedId) {
            $feed = $this->entityManager->getRepository(Feed::class)->find($feedId);
        }

        $articles = $this->feedManager->getUserArticles($user, $feed, $unreadOnly);
        $articles = array_slice($articles, 0, $limit);

        $articlesData = array_map(function(Article $article) use ($user) {
            return [
                'id' => $article->getId(),
                'title' => $article->getTitle(),
                'link' => $article->getLink(),
                'description' => $article->getDescription(),
                'content' => $article->getContent(),
                'author' => $article->getAuthor(),
                'publishedAt' => $article->getPublishedAt()?->format('c'),
                'feedId' => $article->getFeed()->getId(),
                'feedTitle' => $article->getFeed()->getTitle(),
                'isRead' => $article->isReadByUser($user)
            ];
        }, $articles);

        return $this->json(['articles' => $articlesData]);
    }

    #[Route('/articles/{id}', name: 'api_article', methods: ['GET'])]
    public function getArticle(Article $article): JsonResponse
    {
        $user = $this->getUser();

        return $this->json([
            'id' => $article->getId(),
            'title' => $article->getTitle(),
            'link' => $article->getLink(),
            'description' => $article->getDescription(),
            'content' => $article->getContent(),
            'author' => $article->getAuthor(),
            'publishedAt' => $article->getPublishedAt()?->format('c'),
            'feedId' => $article->getFeed()->getId(),
            'feedTitle' => $article->getFeed()->getTitle(),
            'isRead' => $article->isReadByUser($user)
        ]);
    }

    #[Route('/articles/{id}/mark-read', name: 'api_article_mark_read', methods: ['POST'])]
    public function markArticleRead(Article $article): JsonResponse
    {
        $user = $this->getUser();
        $this->feedManager->markArticleAsRead($article, $user);

        return $this->json(['success' => true]);
    }

    #[Route('/feeds/add', name: 'api_feed_add', methods: ['POST'])]
    public function addFeed(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $feedUrl = $data['feed_url'] ?? '';
        $customTitle = $data['custom_title'] ?? null;

        if (!$feedUrl) {
            return $this->json(['error' => 'Feed URL is required'], 400);
        }

        try {
            $feed = $this->feedManager->addFeed($feedUrl);
            $subscription = $this->feedManager->subscribeFeedToUser($feed, $this->getUser(), $customTitle);

            return $this->json([
                'success' => true,
                'feed' => [
                    'id' => $feed->getId(),
                    'title' => $subscription->getTitle(),
                    'url' => $feed->getUrl(),
                    'link' => $feed->getLink(),
                    'description' => $feed->getDescription()
                ]
            ]);
        } catch (\Exception $e) {
            return $this->json(['error' => $e->getMessage()], 400);
        }
    }

    #[Route('/feeds/{id}/refresh', name: 'api_feed_refresh', methods: ['POST'])]
    public function refreshFeed(Feed $feed): JsonResponse
    {
        try {
            $this->feedManager->updateFeed($feed);
            return $this->json(['success' => true]);
        } catch (\Exception $e) {
            return $this->json(['error' => $e->getMessage()], 400);
        }
    }

    #[Route('/search', name: 'api_search', methods: ['GET'])]
    public function search(Request $request): JsonResponse
    {
        $query = $request->query->get('q', '');
        $limit = min($request->query->getInt('limit', 20), 100);

        if (strlen($query) < 3) {
            return $this->json(['articles' => []]);
        }

        $articles = $this->entityManager->getRepository(Article::class)
            ->createQueryBuilder('a')
            ->innerJoin('a.feed', 'f')
            ->innerJoin('f.subscriptions', 's')
            ->where('s.user = :user')
            ->andWhere('f.active = true')
            ->andWhere('(a.title LIKE :query OR a.description LIKE :query OR a.content LIKE :query)')
            ->setParameter('user', $this->getUser())
            ->setParameter('query', '%' . $query . '%')
            ->orderBy('a.publishedAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        $articlesData = array_map(function(Article $article) {
            return [
                'id' => $article->getId(),
                'title' => $article->getTitle(),
                'link' => $article->getLink(),
                'description' => $article->getDescription(),
                'author' => $article->getAuthor(),
                'publishedAt' => $article->getPublishedAt()?->format('c'),
                'feedId' => $article->getFeed()->getId(),
                'feedTitle' => $article->getFeed()->getTitle(),
                'isRead' => $article->isReadByUser($this->getUser())
            ];
        }, $articles);

        return $this->json(['articles' => $articlesData]);
    }
}