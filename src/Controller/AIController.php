<?php

namespace App\Controller;

use App\Entity\Article;
use App\Service\AI\AIArticleProcessor;
use App\Service\AI\PersonalizationService;
use App\Service\AI\SummarizationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[Route('/ai')]
#[IsGranted('ROLE_USER')]
class AIController extends AbstractController
{
    public function __construct(
        private AIArticleProcessor $aiProcessor,
        private PersonalizationService $personalizationService,
        private SummarizationService $summarizationService,
        private EntityManagerInterface $entityManager,
        private ValidatorInterface $validator
    ) {}

    #[Route('/smart-inbox', name: 'ai_smart_inbox', methods: ['GET'])]
    public function smartInbox(): Response
    {
        $user = $this->getUser();
        $personalizedArticles = $this->personalizationService->getPersonalizedFeed($user, 20);
        
        return $this->render('ai/smart_inbox.html.twig', [
            'articles' => $personalizedArticles,
            'user_insights' => $this->personalizationService->getUserInsights($user)
        ]);
    }

    #[Route('/smart-inbox/api', name: 'ai_smart_inbox_api', methods: ['GET'])]
    public function smartInboxAPI(Request $request): JsonResponse
    {
        $user = $this->getUser();
        $limit = (int) $request->query->get('limit', 20);
        
        $personalizedArticles = $this->personalizationService->getPersonalizedFeed($user, $limit);
        
        $articlesData = array_map(function($article) use ($user) {
            return [
                'id' => $article->getId(),
                'title' => $article->getTitle(),
                'summary' => $article->getSummary(),
                'ai_summary' => $article->getAiSummary(),
                'url' => $article->getUrl(),
                'published_at' => $article->getPublishedAt()?->format('c'),
                'reading_time' => $article->getAiReadingTime(),
                'categories' => $article->getAiCategories(),
                'ai_score' => $article->getAiScore(),
                'feed' => [
                    'title' => $article->getFeed()?->getTitle(),
                    'url' => $article->getFeed()?->getSiteUrl()
                ]
            ];
        }, $personalizedArticles);
        
        return $this->json([
            'success' => true,
            'articles' => $articlesData,
            'total' => count($articlesData)
        ]);
    }

    #[Route('/article/{id}/summarize', name: 'ai_summarize_article', methods: ['POST'])]
    public function summarizeArticle(Article $article): JsonResponse
    {
        try {
            $user = $this->getUser();
            
            // Check if summary already exists
            if ($article->getAiSummary()) {
                return $this->json([
                    'success' => true,
                    'summary' => $article->getAiSummary(),
                    'cached' => true
                ]);
            }
            
            // Generate new summary
            $summary = $this->summarizationService->generateSummary($article);
            $article->setAiSummary($summary);
            $article->setAiProcessedAt(new \DateTime());
            
            $this->entityManager->persist($article);
            $this->entityManager->flush();
            
            // Track user interaction
            $this->personalizationService->updateUserPreferences(
                $user,
                $article,
                'summarize',
                ['timestamp' => (new \DateTime())->format('c')]
            );
            
            return $this->json([
                'success' => true,
                'summary' => $summary,
                'cached' => false
            ]);
            
        } catch (\Exception $e) {
            return $this->json([
                'success' => false,
                'error' => 'Failed to generate summary'
            ], 500);
        }
    }

    #[Route('/article/{id}/track-interaction', name: 'ai_track_interaction', methods: ['POST'])]
    public function trackInteraction(Article $article, Request $request): JsonResponse
    {
        try {
            $user = $this->getUser();
            
            // Validate request size (max 10KB)
            $contentLength = $request->headers->get('Content-Length', 0);
            if ($contentLength > 10240) {
                return $this->json([
                    'success' => false,
                    'error' => 'Request payload too large (max 10KB)'
                ], 413);
            }
            
            $content = $request->getContent();
            if (strlen($content) > 10240) {
                return $this->json([
                    'success' => false,
                    'error' => 'Request payload too large (max 10KB)'
                ], 413);
            }
            
            $data = json_decode($content, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                return $this->json([
                    'success' => false,
                    'error' => 'Invalid JSON data'
                ], 400);
            }
            
            // Validate input structure with Symfony Validator
            $constraints = new Assert\Collection([
                'fields' => [
                    'action' => [
                        new Assert\NotBlank(),
                        new Assert\Choice(['view', 'read', 'star', 'unstar', 'share', 'click', 'summarize'])
                    ],
                    'metadata' => [
                        new Assert\Optional([
                            new Assert\Type('array'),
                            new Assert\Count(['max' => 20]) // Max 20 metadata fields
                        ])
                    ]
                ],
                'allowExtraFields' => false
            ]);
            
            $violations = $this->validator->validate($data, $constraints);
            if (count($violations) > 0) {
                $errors = [];
                foreach ($violations as $violation) {
                    $errors[] = $violation->getMessage();
                }
                return $this->json([
                    'success' => false,
                    'error' => 'Validation failed',
                    'details' => $errors
                ], 400);
            }
            
            $action = $data['action'];
            $metadata = $data['metadata'] ?? [];
            
            // Sanitize metadata values - additional security layer
            $sanitizedMetadata = [];
            foreach ($metadata as $key => $value) {
                if (is_string($key) && strlen($key) <= 50 && 
                    (is_string($value) || is_numeric($value) || is_bool($value))) {
                    $sanitizedMetadata[$key] = is_string($value) ? substr(strip_tags($value), 0, 200) : $value;
                }
            }
            
            $this->personalizationService->updateUserPreferences(
                $user,
                $article,
                $action,
                $sanitizedMetadata
            );
            
            return $this->json(['success' => true]);
            
        } catch (\Exception $e) {
            return $this->json([
                'success' => false,
                'error' => 'Failed to track interaction'
            ], 500);
        }
    }

    #[Route('/article/{id}/similar', name: 'ai_similar_articles', methods: ['GET'])]
    public function getSimilarArticles(Article $article, Request $request): JsonResponse
    {
        try {
            $user = $this->getUser();
            $limit = (int) $request->query->get('limit', 5);
            
            $similarArticles = $this->personalizationService->recommendSimilarArticles(
                $article,
                $user,
                $limit
            );
            
            $articlesData = array_map(function($similarArticle) {
                return [
                    'id' => $similarArticle->getId(),
                    'title' => $similarArticle->getTitle(),
                    'summary' => $similarArticle->getSummary(),
                    'url' => $similarArticle->getUrl(),
                    'published_at' => $similarArticle->getPublishedAt()?->format('c'),
                    'reading_time' => $similarArticle->getAiReadingTime(),
                    'categories' => $similarArticle->getAiCategories(),
                    'feed_title' => $similarArticle->getFeed()?->getTitle()
                ];
            }, $similarArticles);
            
            return $this->json([
                'success' => true,
                'articles' => $articlesData
            ]);
            
        } catch (\Exception $e) {
            return $this->json([
                'success' => false,
                'error' => 'Failed to get similar articles'
            ], 500);
        }
    }

    #[Route('/insights', name: 'ai_user_insights', methods: ['GET'])]
    public function getUserInsights(): JsonResponse
    {
        try {
            $user = $this->getUser();
            $insights = $this->personalizationService->getUserInsights($user);
            
            return $this->json([
                'success' => true,
                'insights' => $insights
            ]);
            
        } catch (\Exception $e) {
            return $this->json([
                'success' => false,
                'error' => 'Failed to get user insights'
            ], 500);
        }
    }

    #[Route('/process-article/{id}', name: 'ai_process_article', methods: ['POST'])]
    public function processArticle(Article $article): JsonResponse
    {
        try {
            // Check if processing is required
            if (!$this->aiProcessor->isProcessingRequired($article)) {
                return $this->json([
                    'success' => true,
                    'message' => 'Article already processed',
                    'processed' => false
                ]);
            }
            
            $this->aiProcessor->processArticle($article);
            
            return $this->json([
                'success' => true,
                'message' => 'Article processed successfully',
                'processed' => true,
                'ai_score' => $article->getAiScore(),
                'categories' => $article->getAiCategories(),
                'reading_time' => $article->getAiReadingTime()
            ]);
            
        } catch (\Exception $e) {
            return $this->json([
                'success' => false,
                'error' => 'Failed to process article'
            ], 500);
        }
    }
}