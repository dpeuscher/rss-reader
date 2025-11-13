<?php

namespace App\Service\AI;

use App\Entity\Article;
use Psr\Log\LoggerInterface;

class CategoryService
{
    private const CATEGORY_KEYWORDS = [
        'Technology' => ['tech', 'software', 'AI', 'artificial intelligence', 'machine learning', 'programming', 'code', 'developer', 'startup', 'cloud'],
        'Business' => ['business', 'finance', 'economy', 'market', 'stock', 'investment', 'entrepreneur', 'company', 'corporate', 'strategy'],
        'Science' => ['science', 'research', 'study', 'experiment', 'discovery', 'scientific', 'medicine', 'health', 'biology', 'physics'],
        'Politics' => ['politics', 'government', 'election', 'policy', 'law', 'congress', 'senate', 'president', 'democracy', 'vote'],
        'Sports' => ['sports', 'football', 'basketball', 'soccer', 'baseball', 'hockey', 'olympics', 'athlete', 'game', 'team'],
        'Entertainment' => ['entertainment', 'movie', 'film', 'music', 'celebrity', 'tv', 'show', 'netflix', 'streaming', 'gaming'],
        'Travel' => ['travel', 'tourism', 'vacation', 'destination', 'hotel', 'flight', 'trip', 'adventure', 'culture', 'country'],
        'Food' => ['food', 'recipe', 'cooking', 'restaurant', 'chef', 'cuisine', 'diet', 'nutrition', 'meal', 'drink'],
        'Lifestyle' => ['lifestyle', 'fashion', 'beauty', 'home', 'design', 'wellness', 'fitness', 'relationship', 'family', 'personal'],
        'Environment' => ['environment', 'climate', 'green', 'sustainability', 'renewable', 'pollution', 'conservation', 'ecology', 'earth', 'nature']
    ];

    public function __construct(
        private LoggerInterface $logger
    ) {}

    public function categorize(Article $article): array
    {
        try {
            $content = $this->prepareContentForAnalysis($article);
            $categories = $this->detectCategories($content);
            
            // Add confidence scores and metadata
            $categoriesWithMetadata = [];
            foreach ($categories as $category => $score) {
                $categoriesWithMetadata[] = [
                    'name' => $category,
                    'confidence' => $score,
                    'detected_at' => (new \DateTime())->format('c')
                ];
            }
            
            $this->logger->info('Categories detected for article', [
                'articleId' => $article->getId(),
                'categories' => array_column($categoriesWithMetadata, 'name')
            ]);
            
            return $categoriesWithMetadata;
            
        } catch (\Exception $e) {
            $this->logger->error('Category detection failed', [
                'articleId' => $article->getId(),
                'error' => $e->getMessage()
            ]);
            
            return [];
        }
    }

    private function prepareContentForAnalysis(Article $article): string
    {
        $title = $article->getTitle() ?? '';
        $content = $article->getContent() ?? '';
        $summary = $article->getSummary() ?? '';
        
        // Combine all text sources
        $fullText = implode(' ', [$title, $summary, $content]);
        
        // Clean the text
        $fullText = strip_tags($fullText);
        $fullText = preg_replace('/\s+/', ' ', $fullText);
        $fullText = strtolower(trim($fullText));
        
        return $fullText;
    }

    private function detectCategories(string $content): array
    {
        $categoryScores = [];
        
        foreach (self::CATEGORY_KEYWORDS as $category => $keywords) {
            $score = 0;
            $totalWords = str_word_count($content);
            
            foreach ($keywords as $keyword) {
                $keyword = strtolower($keyword);
                $count = substr_count($content, $keyword);
                
                if ($count > 0) {
                    // Weight score by keyword importance and frequency
                    $weight = $this->getKeywordWeight($keyword);
                    $score += ($count * $weight) / max($totalWords, 1);
                }
            }
            
            if ($score > 0) {
                $categoryScores[$category] = round($score, 3);
            }
        }
        
        // Sort by score descending
        arsort($categoryScores);
        
        // Return top categories with minimum confidence threshold
        $minConfidence = 0.001;
        $topCategories = array_filter($categoryScores, fn($score) => $score >= $minConfidence);
        
        // Limit to top 5 categories
        return array_slice($topCategories, 0, 5, true);
    }

    private function getKeywordWeight(string $keyword): float
    {
        // Assign weights based on keyword specificity
        $highWeight = ['AI', 'artificial intelligence', 'machine learning', 'entrepreneur', 'investment'];
        $mediumWeight = ['technology', 'business', 'science', 'politics', 'entertainment'];
        
        if (in_array($keyword, $highWeight)) {
            return 2.0;
        }
        
        if (in_array($keyword, $mediumWeight)) {
            return 1.5;
        }
        
        return 1.0;
    }

    public function getSentiment(Article $article): array
    {
        try {
            $content = $this->prepareContentForAnalysis($article);
            
            // Simple sentiment analysis using keyword matching
            $positiveWords = ['good', 'great', 'excellent', 'amazing', 'wonderful', 'fantastic', 'positive', 'success', 'win', 'growth'];
            $negativeWords = ['bad', 'terrible', 'awful', 'horrible', 'negative', 'failure', 'lose', 'decline', 'crisis', 'problem'];
            
            $positiveCount = 0;
            $negativeCount = 0;
            
            foreach ($positiveWords as $word) {
                $positiveCount += substr_count($content, $word);
            }
            
            foreach ($negativeWords as $word) {
                $negativeCount += substr_count($content, $word);
            }
            
            $totalSentimentWords = $positiveCount + $negativeCount;
            
            if ($totalSentimentWords === 0) {
                return ['sentiment' => 'neutral', 'confidence' => 0.5];
            }
            
            $positiveRatio = $positiveCount / $totalSentimentWords;
            
            if ($positiveRatio > 0.6) {
                return ['sentiment' => 'positive', 'confidence' => $positiveRatio];
            } elseif ($positiveRatio < 0.4) {
                return ['sentiment' => 'negative', 'confidence' => 1 - $positiveRatio];
            } else {
                return ['sentiment' => 'neutral', 'confidence' => 0.5];
            }
            
        } catch (\Exception $e) {
            $this->logger->error('Sentiment analysis failed', [
                'articleId' => $article->getId(),
                'error' => $e->getMessage()
            ]);
            
            return ['sentiment' => 'neutral', 'confidence' => 0.5];
        }
    }
}