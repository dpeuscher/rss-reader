<?php

namespace App\Controller;

use App\Entity\Feed;
use App\Service\FeedManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/feed')]
class FeedController extends AbstractController
{
    public function __construct(
        private FeedManager $feedManager
    ) {}

    #[Route('/add', name: 'app_feed_add', methods: ['POST'])]
    public function add(Request $request): Response
    {
        $feedUrl = $request->request->get('feed_url');
        $customTitle = $request->request->get('custom_title');
        
        if (!$feedUrl) {
            $this->addFlash('error', 'Feed URL is required');
            return $this->redirectToRoute('app_home');
        }

        try {
            $feed = $this->feedManager->addFeed($feedUrl);
            $this->feedManager->subscribeFeedToUser($feed, $this->getUser(), $customTitle);
            
            $this->addFlash('success', 'Feed added successfully');
        } catch (\Exception $e) {
            $this->addFlash('error', 'Failed to add feed: ' . $e->getMessage());
        }

        return $this->redirectToRoute('app_home');
    }

    #[Route('/{id}', name: 'app_feed_view', requirements: ['id' => '\d+'])]
    public function view(Feed $feed): Response
    {
        $user = $this->getUser();
        $subscriptions = $this->feedManager->getUserFeeds($user);
        $articles = $this->feedManager->getUserArticles($user, $feed);
        $unreadCount = $this->feedManager->getUnreadCount($user, $feed);

        return $this->render('home/index.html.twig', [
            'subscriptions' => $subscriptions,
            'articles' => $articles,
            'unreadCount' => $unreadCount,
            'currentFeed' => $feed
        ]);
    }

    #[Route('/{id}/unread', name: 'app_feed_unread', requirements: ['id' => '\d+'])]
    public function unread(Feed $feed): Response
    {
        $user = $this->getUser();
        $subscriptions = $this->feedManager->getUserFeeds($user);
        $articles = $this->feedManager->getUserArticles($user, $feed, true);
        $unreadCount = $this->feedManager->getUnreadCount($user, $feed);

        return $this->render('home/index.html.twig', [
            'subscriptions' => $subscriptions,
            'articles' => $articles,
            'unreadCount' => $unreadCount,
            'currentFeed' => $feed,
            'unreadOnly' => true
        ]);
    }

    #[Route('/{id}/refresh', name: 'app_feed_refresh', methods: ['POST'])]
    public function refresh(Feed $feed): Response
    {
        try {
            $this->feedManager->updateFeed($feed);
            $this->addFlash('success', 'Feed refreshed successfully');
        } catch (\Exception $e) {
            $this->addFlash('error', 'Failed to refresh feed: ' . $e->getMessage());
        }

        return $this->redirectToRoute('app_feed_view', ['id' => $feed->getId()]);
    }
}