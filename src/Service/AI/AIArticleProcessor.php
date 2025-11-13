<?php

namespace App\Service\AI;

use App\Entity\Article;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

class AIArticleProcessor
{
    public function __construct(
        private SummarizationService $summarizationService,
        private CategoryService $categoryService,
        private ScoringService $scoringService,
        private EntityManagerInterface $entityManager,
        private LoggerInterface $logger
    ) {}

    public function processArticle(Article $article): void
    {
        try {
            $this->logger->info('Starting AI processing for article', ['articleId' => $article->getId()]);

            // Generate AI summary
            $summary = $this->summarizationService->generateSummary($article);
            $article->setAiSummary($summary);

            // Categorize content
            $categories = $this->categoryService->categorize($article);
            $article->setAiCategories($categories);

            // Calculate reading time
            $readingTime = $this->calculateReadingTime($article);
            $article->setAiReadingTime($readingTime);

            // Calculate base AI score
            $score = $this->scoringService->calculateBaseScore($article);
            $article->setAiScore($score);

            // Mark as processed
            $article->setAiProcessedAt(new \DateTime());

            $this->entityManager->persist($article);
            $this->entityManager->flush();

            $this->logger->info('AI processing completed for article', ['articleId' => $article->getId()]);
        } catch (\Exception $e) {
            $this->logger->error('AI processing failed for article', [
                'articleId' => $article->getId(),
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    public function processPersonalization(Article $article, User $user): float
    {
        try {
            return $this->scoringService->calculatePersonalizedScore($article, $user);
        } catch (\Exception $e) {
            $this->logger->error('Personalization scoring failed', [
                'articleId' => $article->getId(),
                'userId' => $user->getId(),
                'error' => $e->getMessage()
            ]);
            
            // Fallback to base AI score
            return $article->getAiScore() ?? 0.5;
        }
    }

    public function isProcessingRequired(Article $article): bool
    {
        // Process if never processed or content changed significantly
        return $article->getAiProcessedAt() === null || 
               $this->hasContentChanged($article);
    }

    private function calculateReadingTime(Article $article): int
    {
        $content = $article->getContent() ?? '';
        $wordCount = str_word_count(strip_tags($content));
        
        // Average reading speed: 200 words per minute
        $readingTimeMinutes = ceil($wordCount / 200);
        
        return max(1, $readingTimeMinutes);
    }

    private function hasContentChanged(Article $article): bool
    {
        // Simple check - in production, you might store content hash
        $lastProcessed = $article->getAiProcessedAt();
        $publishedAt = $article->getPublishedAt();
        
        return $lastProcessed && $publishedAt && $publishedAt > $lastProcessed;
    }
}