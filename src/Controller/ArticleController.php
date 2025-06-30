<?php

namespace App\Controller;

use App\Entity\Article;
use App\Service\FeedManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/article')]
class ArticleController extends AbstractController
{
    public function __construct(
        private FeedManager $feedManager
    ) {}

    #[Route('/{id}', name: 'app_article_view', requirements: ['id' => '\d+'])]
    public function view(Article $article): Response
    {
        $user = $this->getUser();
        
        $this->feedManager->markArticleAsRead($article, $user);

        return $this->render('article/view.html.twig', [
            'article' => $article
        ]);
    }

    #[Route('/{id}/mark-read', name: 'app_article_mark_read', methods: ['POST'])]
    public function markRead(Article $article): Response
    {
        $user = $this->getUser();
        $this->feedManager->markArticleAsRead($article, $user);

        return $this->json(['status' => 'success']);
    }
}