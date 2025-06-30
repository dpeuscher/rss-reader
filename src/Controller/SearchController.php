<?php

namespace App\Controller;

use App\Service\FeedManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class SearchController extends AbstractController
{
    public function __construct(
        private FeedManager $feedManager,
        private EntityManagerInterface $entityManager
    ) {}

    #[Route('/search', name: 'app_search')]
    public function search(Request $request): Response
    {
        $query = $request->query->get('q', '');
        $articles = [];

        if (strlen($query) >= 3) {
            $articles = $this->entityManager->getRepository(\App\Entity\Article::class)
                ->createQueryBuilder('a')
                ->innerJoin('a.feed', 'f')
                ->innerJoin('f.subscriptions', 's')
                ->where('s.user = :user')
                ->andWhere('f.active = true')
                ->andWhere('(a.title LIKE :query OR a.description LIKE :query OR a.content LIKE :query)')
                ->setParameter('user', $this->getUser())
                ->setParameter('query', '%' . $query . '%')
                ->orderBy('a.publishedAt', 'DESC')
                ->setMaxResults(50)
                ->getQuery()
                ->getResult();
        }

        $subscriptions = $this->feedManager->getUserFeeds($this->getUser());
        $unreadCount = $this->feedManager->getUnreadCount($this->getUser());

        return $this->render('search/results.html.twig', [
            'query' => $query,
            'articles' => $articles,
            'subscriptions' => $subscriptions,
            'unreadCount' => $unreadCount
        ]);
    }
}