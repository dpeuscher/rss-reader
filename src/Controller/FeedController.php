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
            $errorMessage = $this->sanitizeErrorMessage($validationResult->getMessage());
            return $this->json(['error' => $errorMessage], 400);
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
            // URL validation errors
            return $this->json(['error' => $e->getMessage()], 400);
        } catch (\RuntimeException $e) {
            // Request failures
            $errorMessage = $this->sanitizeErrorMessage($e->getMessage());
            return $this->json(['error' => $errorMessage], 400);
        } catch (\Exception $e) {
            // All other errors
            return $this->json(['error' => 'Error adding feed'], 500);
        }
    }

    #[Route('/{id}/preview', name: 'app_feeds_preview', methods: ['GET'])]
    public function preview(string $id, FeedParserService $feedParser): Response
    {
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');
        
        try {
            $validationResult = $feedParser->validateFeed($id);
            
            if (!$validationResult->isValid()) {
                // Return sanitized error message to prevent information disclosure
                $errorMessage = $this->sanitizeErrorMessage($validationResult->getMessage());
                return $this->json(['error' => $errorMessage], 400);
            }
            
            $parsedFeed = $feedParser->parseFeed($id);
            $articles = $feedParser->extractArticles($parsedFeed);
            
            return $this->render('feed/preview.html.twig', [
                'feed_url' => $id,
                'feed' => $parsedFeed->getFeed(),
                'articles' => array_slice($articles, 0, 5), // Show first 5 articles
            ]);
        } catch (\InvalidArgumentException $e) {
            // URL validation errors - these are safe to return
            return $this->json(['error' => $e->getMessage()], 400);
        } catch (\RuntimeException $e) {
            // Request failures - sanitize these messages
            $errorMessage = $this->sanitizeErrorMessage($e->getMessage());
            return $this->json(['error' => $errorMessage], 400);
        } catch (\Exception $e) {
            // All other errors - use generic message to prevent information disclosure
            return $this->json(['error' => 'Error previewing feed'], 500);
        }
    }

    private function sanitizeErrorMessage(string $message): string
    {
        // List of allowed error messages that don't leak internal information
        $allowedMessages = [
            'Invalid URL format',
            'Access to private IPs not allowed',
            'Request failed: Response size limit exceeded',
            'Request failed: Request timeout',
            'Feed parsing error',
            'HTTP error: 404',
            'HTTP error: 403',
            'HTTP error: 500',
            'HTTP error: 502',
            'HTTP error: 503',
        ];

        // Check if the message starts with any allowed pattern
        foreach ($allowedMessages as $allowedMessage) {
            if (strpos($message, $allowedMessage) === 0) {
                return $allowedMessage;
            }
        }

        // For HTTP errors, extract just the status code
        if (preg_match('/HTTP error: (\d{3})/', $message, $matches)) {
            return 'HTTP error: ' . $matches[1];
        }

        // For timeout/size errors, return generic message
        if (strpos($message, 'timeout') !== false || strpos($message, 'size') !== false) {
            return 'Request failed';
        }

        // Default generic message for any other errors
        return 'Invalid feed URL';
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