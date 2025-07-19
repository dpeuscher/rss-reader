<?php

namespace App\Controller;

use App\Entity\Feed;
use App\Entity\Subscription;
use App\Repository\SubscriptionRepository;
use App\Service\FeedParserService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/feeds')]
class FeedController extends AbstractController
{
    #[Route('/', name: 'app_feeds_index', methods: ['GET'])]
    public function index(SubscriptionRepository $subscriptionRepo): Response
    {
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');
        
        $subscriptions = $subscriptionRepo->findByUser($this->getUser()->getId());
        
        return $this->render('feed/index.html.twig', [
            'subscriptions' => $subscriptions,
        ]);
    }

    #[Route('/add', name: 'app_feeds_add', methods: ['POST'])]
    public function add(
        Request $request,
        FeedParserService $feedParser,
        EntityManagerInterface $entityManager,
        SubscriptionRepository $subscriptionRepo
    ): Response {
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');
        
        $url = $request->request->get('url');
        
        if (!$url) {
            return $this->json(['error' => 'URL is required'], 400);
        }

        // Validate feed
        $validationResult = $feedParser->validateFeed($url);
        
        if (!$validationResult->isValid()) {
            return $this->json(['error' => $validationResult->getMessage()], 400);
        }

        // Check if user is already subscribed
        $existingFeed = $entityManager->getRepository(Feed::class)->findOneBy(['url' => $url]);
        
        if ($existingFeed) {
            $existingSubscription = $subscriptionRepo->findByUserAndFeed(
                $this->getUser()->getId(),
                $existingFeed->getId()
            );
            
            if ($existingSubscription) {
                return $this->json(['error' => 'Already subscribed to this feed'], 400);
            }
        }

        try {
            // Create or update feed
            $feed = $existingFeed ?: new Feed();
            $feed->setUrl($url);
            
            $parsedFeed = $feedParser->parseFeed($url);
            $feedParser->updateFeedFromParsed($feed, $parsedFeed);
            
            $entityManager->persist($feed);
            
            // Create subscription
            $subscription = new Subscription();
            $subscription->setUser($this->getUser());
            $subscription->setFeed($feed);
            
            $entityManager->persist($subscription);
            $entityManager->flush();
            
            return $this->json(['success' => 'Feed added successfully']);
        } catch (\InvalidArgumentException $e) {
            // Handle URL validation failures from parseFeed()
            return $this->json(['error' => 'Invalid URL: ' . $e->getMessage()], 400);
        } catch (\Exception $e) {
            return $this->json(['error' => 'Error adding feed: ' . $e->getMessage()], 500);
        }
    }

    #[Route('/{id}/preview', name: 'app_feeds_preview', methods: ['GET'])]
    public function preview(string $id, FeedParserService $feedParser): Response
    {
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');
        
        try {
            // Decode the URL parameter to handle encoded characters and prevent bypass attempts
            $decodedUrl = urldecode($id);
            
            // Validate the decoded URL format first
            if (!filter_var($decodedUrl, FILTER_VALIDATE_URL)) {
                return $this->json(['error' => 'Invalid URL format provided'], 400);
            }
            
            // Additional check for potential path traversal attempts in encoded form
            if (strpos($id, '%2E%2E') !== false || strpos($id, '..') !== false) {
                return $this->json(['error' => 'Path traversal attempt detected'], 400);
            }
            
            // The route parameter is treated as a URL - validate it for SSRF protection
            $validationResult = $feedParser->validateFeed($decodedUrl);
            
            if (!$validationResult->isValid()) {
                return $this->json(['error' => $validationResult->getMessage()], 400);
            }
            
            $parsedFeed = $feedParser->parseFeed($decodedUrl);
            $articles = $feedParser->extractArticles($parsedFeed);
            
            return $this->render('feed/preview.html.twig', [
                'feed_url' => $decodedUrl,
                'feed' => $parsedFeed->getFeed(),
                'articles' => array_slice($articles, 0, 5), // Show first 5 articles
            ]);
        } catch (\InvalidArgumentException $e) {
            // Handle URL validation failures from parseFeed()
            return $this->json(['error' => 'Invalid URL: ' . $e->getMessage()], 400);
        } catch (\Exception $e) {
            return $this->json(['error' => 'Error previewing feed: ' . $e->getMessage()], 500);
        }
    }

    #[Route('/{id}', name: 'app_feeds_delete', methods: ['DELETE'])]
    public function delete(
        int $id,
        SubscriptionRepository $subscriptionRepo,
        EntityManagerInterface $entityManager
    ): Response {
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');
        
        $subscription = $subscriptionRepo->findByUserAndFeed($this->getUser()->getId(), $id);
        
        if (!$subscription) {
            return $this->json(['error' => 'Subscription not found'], 404);
        }
        
        $entityManager->remove($subscription);
        $entityManager->flush();
        
        return $this->json(['success' => 'Subscription removed']);
    }
}