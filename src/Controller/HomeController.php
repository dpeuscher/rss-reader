<?php

namespace App\Controller;

use App\Service\FeedManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class HomeController extends AbstractController
{
    public function __construct(
        private FeedManager $feedManager
    ) {}

    #[Route('/', name: 'app_home')]
    public function index(): Response
    {
        $user = $this->getUser();
        
        if (!$user) {
            return $this->redirectToRoute('app_login');
        }

        $subscriptions = $this->feedManager->getUserFeeds($user);
        $articles = $this->feedManager->getUserArticles($user);
        $unreadCount = $this->feedManager->getUnreadCount($user);

        return $this->render('home/index.html.twig', [
            'subscriptions' => $subscriptions,
            'articles' => $articles,
            'unreadCount' => $unreadCount,
            'currentFeed' => null
        ]);
    }

    #[Route('/unread', name: 'app_unread')]
    public function unread(): Response
    {
        $user = $this->getUser();
        $subscriptions = $this->feedManager->getUserFeeds($user);
        $articles = $this->feedManager->getUserArticles($user, null, true);
        $unreadCount = $this->feedManager->getUnreadCount($user);

        return $this->render('home/index.html.twig', [
            'subscriptions' => $subscriptions,
            'articles' => $articles,
            'unreadCount' => $unreadCount,
            'currentFeed' => null,
            'unreadOnly' => true
        ]);
    }
}