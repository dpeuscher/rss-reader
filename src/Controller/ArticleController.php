<?php

namespace App\Controller;

use App\Repository\ArticleRepository;
use App\Repository\SubscriptionRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/articles')]
class ArticleController extends AbstractController
{
    #[Route('/', name: 'app_articles_index', methods: ['GET'])]
    public function index(
        Request $request,
        ArticleRepository $articleRepo,
        SubscriptionRepository $subscriptionRepo
    ): Response {
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');

        $page = max(1, (int) $request->query->get('page', 1));
        $feedId = $request->query->get('feed_id');
        $limit = 20; // Default global limit
        
        $filters = [];
        if ($feedId) {
            $filters['feed_id'] = $feedId;
            
            // Get subscription to check for custom limit
            $subscription = $subscriptionRepo->findByUserAndFeed($this->getUser()->getId(), $feedId);
            if ($subscription) {
                $limit = $subscription->getEffectiveEntryLimit();
            }
        }

        $offset = ($page - 1) * $limit;
        $articles = $articleRepo->findByUserAndFilters(
            $this->getUser()->getId(),
            $filters,
            $limit,
            $offset
        );

        $totalCount = $articleRepo->countByUserAndFilters($this->getUser()->getId(), $filters);
        $showingCount = count($articles);
        $hasMore = $totalCount > ($offset + $showingCount);

        return $this->render('article/index.html.twig', [
            'articles' => $articles,
            'total_count' => $totalCount,
            'showing_count' => $showingCount,
            'limit' => $limit,
            'page' => $page,
            'has_more' => $hasMore,
            'feed_id' => $feedId,
        ]);
    }

    #[Route('/feed/{feedId}', name: 'app_articles_by_feed', methods: ['GET'])]
    public function byFeed(
        int $feedId,
        Request $request,
        ArticleRepository $articleRepo,
        SubscriptionRepository $subscriptionRepo
    ): Response {
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');

        // Check if user is subscribed to this feed
        $subscription = $subscriptionRepo->findByUserAndFeed($this->getUser()->getId(), $feedId);
        if (!$subscription) {
            throw $this->createNotFoundException('Feed not found or not subscribed');
        }

        $page = max(1, (int) $request->query->get('page', 1));
        $limit = $subscription->getEffectiveEntryLimit();
        $offset = ($page - 1) * $limit;

        $filters = ['feed_id' => $feedId];
        $articles = $articleRepo->findByUserAndFilters(
            $this->getUser()->getId(),
            $filters,
            $limit,
            $offset
        );

        $totalCount = $articleRepo->countByUserAndFilters($this->getUser()->getId(), $filters);
        $showingCount = count($articles);
        $hasMore = $totalCount > ($offset + $showingCount);

        return $this->render('article/feed.html.twig', [
            'articles' => $articles,
            'subscription' => $subscription,
            'feed' => $subscription->getFeed(),
            'total_count' => $totalCount,
            'showing_count' => $showingCount,
            'limit' => $limit,
            'page' => $page,
            'has_more' => $hasMore,
        ]);
    }
}