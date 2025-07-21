<?php

namespace App\Service\AI;

use App\Entity\Article;
use App\Entity\User;
use App\Entity\UserArticle;
use App\Repository\UserArticleRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

class ScoringService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private UserArticleRepository $userArticleRepository,
        private CategoryService $categoryService,
        private LoggerInterface $logger
    ) {}

    public function calculateBaseScore(Article $article): float
    {
        try {
            $score = 0.0;
            
            // Content quality factors (40% of score)
            $score += $this->calculateContentQualityScore($article) * 0.4;
            
            // Freshness factor (20% of score)
            $score += $this->calculateFreshnessScore($article) * 0.2;
            
            // Source credibility (20% of score)
            $score += $this->calculateSourceCredibilityScore($article) * 0.2;
            
            // Engagement potential (20% of score)
            $score += $this->calculateEngagementPotentialScore($article) * 0.2;
            
            // Ensure score is between 0 and 1
            $normalizedScore = max(0.0, min(1.0, $score));
            
            $this->logger->info('Base score calculated for article', [
                'articleId' => $article->getId(),
                'score' => $normalizedScore
            ]);
            
            return round($normalizedScore, 3);
            
        } catch (\Exception $e) {
            $this->logger->error('Base score calculation failed', [
                'articleId' => $article->getId(),
                'error' => $e->getMessage()
            ]);
            
            return 0.5; // Default middle score
        }
    }

    public function calculatePersonalizedScore(Article $article, User $user): float
    {
        try {
            $baseScore = $article->getAiScore() ?? $this->calculateBaseScore($article);
            
            // Get user reading patterns
            $userPreferences = $this->getUserPreferences($user);
            
            // Category preference boost
            $categoryBoost = $this->calculateCategoryPreferenceBoost($article, $userPreferences);
            
            // Reading behavior boost
            $behaviorBoost = $this->calculateBehaviorBoost($article, $user);
            
            // Time preference boost
            $timeBoost = $this->calculateTimePreferenceBoost($article, $userPreferences);
            
            // Combine scores with weights
            $personalizedScore = $baseScore * 0.6 + 
                               $categoryBoost * 0.25 + 
                               $behaviorBoost * 0.1 + 
                               $timeBoost * 0.05;
            
            // Ensure score is between 0 and 1
            $finalScore = max(0.0, min(1.0, $personalizedScore));
            
            $this->logger->info('Personalized score calculated', [
                'articleId' => $article->getId(),
                'userId' => $user->getId(),
                'baseScore' => $baseScore,
                'personalizedScore' => $finalScore
            ]);
            
            return round($finalScore, 3);
            
        } catch (\Exception $e) {
            $this->logger->error('Personalized score calculation failed', [
                'articleId' => $article->getId(),
                'userId' => $user->getId(),
                'error' => $e->getMessage()
            ]);
            
            return $article->getAiScore() ?? 0.5;
        }
    }

    private function calculateContentQualityScore(Article $article): float
    {
        $score = 0.0;
        
        // Content length factor
        $contentLength = strlen($article->getContent() ?? '');
        if ($contentLength > 1000) {
            $score += 0.3;
        } elseif ($contentLength > 500) {
            $score += 0.2;
        } elseif ($contentLength > 200) {
            $score += 0.1;
        }
        
        // Title quality
        $titleLength = strlen($article->getTitle() ?? '');
        if ($titleLength > 30 && $titleLength < 100) {
            $score += 0.2;
        }
        
        // Has summary
        if (!empty($article->getSummary())) {
            $score += 0.1;
        }
        
        // Content structure (basic check for paragraphs)
        $content = $article->getContent() ?? '';
        $paragraphs = substr_count($content, '</p>') + substr_count($content, '\n\n');
        if ($paragraphs > 2) {
            $score += 0.2;
        }
        
        // Reading time in reasonable range
        $readingTime = $article->getAiReadingTime();
        if ($readingTime && $readingTime >= 2 && $readingTime <= 15) {
            $score += 0.2;
        }
        
        return $score;
    }

    private function calculateFreshnessScore(Article $article): float
    {
        $publishedAt = $article->getPublishedAt();
        if (!$publishedAt) {
            return 0.5;
        }
        
        $now = new \DateTime();
        $hoursOld = ($now->getTimestamp() - $publishedAt->getTimestamp()) / 3600;
        
        // Fresher articles get higher scores
        if ($hoursOld < 1) {
            return 1.0;
        } elseif ($hoursOld < 6) {
            return 0.9;
        } elseif ($hoursOld < 24) {
            return 0.7;
        } elseif ($hoursOld < 72) {
            return 0.5;
        } elseif ($hoursOld < 168) { // 1 week
            return 0.3;
        } else {
            return 0.1;
        }
    }

    private function calculateSourceCredibilityScore(Article $article): float
    {
        $feed = $article->getFeed();
        if (!$feed) {
            return 0.5;
        }
        
        // Simple heuristics for source credibility
        $score = 0.5; // Default
        
        $feedTitle = strtolower($feed->getTitle() ?? '');
        $siteUrl = strtolower($feed->getSiteUrl() ?? '');
        
        // Known high-quality sources
        $highQualitySources = ['bbc', 'reuters', 'ap news', 'npr', 'guardian', 'nytimes', 'wsj'];
        foreach ($highQualitySources as $source) {
            if (strpos($feedTitle, $source) !== false || strpos($siteUrl, $source) !== false) {
                $score += 0.3;
                break;
            }
        }
        
        // TLD-based credibility
        if (strpos($siteUrl, '.edu') !== false || strpos($siteUrl, '.gov') !== false) {
            $score += 0.2;
        }
        
        return min(1.0, $score);
    }

    private function calculateEngagementPotentialScore(Article $article): float
    {
        $score = 0.5; // Base score
        
        // Check for engaging keywords in title
        $title = strtolower($article->getTitle() ?? '');
        $engagingWords = ['how to', 'why', 'what', 'best', 'top', 'guide', 'tips', 'secrets', 'ultimate'];
        
        foreach ($engagingWords as $word) {
            if (strpos($title, $word) !== false) {
                $score += 0.1;
                break;
            }
        }
        
        // Check sentiment (positive articles tend to be more engaging)
        $sentiment = $this->categoryService->getSentiment($article);
        if ($sentiment['sentiment'] === 'positive' && $sentiment['confidence'] > 0.6) {
            $score += 0.2;
        }
        
        return min(1.0, $score);
    }

    private function getUserPreferences(User $user): array
    {
        // Analyze user's reading history to determine preferences
        $userArticles = $this->userArticleRepository->findRecentUserActivity($user, 50);
        
        $categoryPreferences = [];
        $timePreferences = [];
        $totalArticles = count($userArticles);
        
        if ($totalArticles === 0) {
            return [
                'categories' => [],
                'reading_times' => [],
                'time_patterns' => []
            ];
        }
        
        foreach ($userArticles as $userArticle) {
            $article = $userArticle->getArticle();
            $categories = $article->getAiCategories() ?? [];
            
            // Track category preferences based on read/starred articles
            if ($userArticle->isRead() || $userArticle->isStarred()) {
                foreach ($categories as $category) {
                    $categoryName = $category['name'] ?? '';
                    if (!empty($categoryName)) {
                        $categoryPreferences[$categoryName] = ($categoryPreferences[$categoryName] ?? 0) + 1;
                    }
                }
            }
            
            // Track reading time preferences
            if ($userArticle->getReadAt()) {
                $hour = (int) $userArticle->getReadAt()->format('H');
                $timePreferences[$hour] = ($timePreferences[$hour] ?? 0) + 1;
            }
        }
        
        // Normalize preferences
        foreach ($categoryPreferences as $category => $count) {
            $categoryPreferences[$category] = $count / $totalArticles;
        }
        
        return [
            'categories' => $categoryPreferences,
            'reading_times' => [],
            'time_patterns' => $timePreferences
        ];
    }

    private function calculateCategoryPreferenceBoost(Article $article, array $userPreferences): float
    {
        $categories = $article->getAiCategories() ?? [];
        $categoryPreferences = $userPreferences['categories'] ?? [];
        
        if (empty($categories) || empty($categoryPreferences)) {
            return 0.5;
        }
        
        $totalBoost = 0.0;
        $categoryCount = 0;
        
        foreach ($categories as $category) {
            $categoryName = $category['name'] ?? '';
            $confidence = $category['confidence'] ?? 0;
            
            if (!empty($categoryName) && isset($categoryPreferences[$categoryName])) {
                $preference = $categoryPreferences[$categoryName];
                $totalBoost += $preference * $confidence;
                $categoryCount++;
            }
        }
        
        return $categoryCount > 0 ? min(1.0, $totalBoost / $categoryCount) : 0.5;
    }

    private function calculateBehaviorBoost(Article $article, User $user): float
    {
        // Check if user has interacted with similar articles
        $feed = $article->getFeed();
        if (!$feed) {
            return 0.5;
        }
        
        // Check user's engagement with this feed
        $feedEngagement = $this->userArticleRepository->getUserFeedEngagement($user, $feed);
        
        if ($feedEngagement['total'] === 0) {
            return 0.5;
        }
        
        $engagementRate = $feedEngagement['engaged'] / $feedEngagement['total'];
        return min(1.0, $engagementRate);
    }

    private function calculateTimePreferenceBoost(Article $article, array $userPreferences): float
    {
        $timePreferences = $userPreferences['time_patterns'] ?? [];
        
        if (empty($timePreferences)) {
            return 0.5;
        }
        
        $currentHour = (int) (new \DateTime())->format('H');
        $preference = $timePreferences[$currentHour] ?? 0;
        
        // Normalize based on total reading sessions
        $totalSessions = array_sum($timePreferences);
        
        return $totalSessions > 0 ? min(1.0, ($preference / $totalSessions) * 2) : 0.5;
    }
}