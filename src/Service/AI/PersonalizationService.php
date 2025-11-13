<?php

namespace App\Service\AI;

use App\Entity\Article;
use App\Entity\User;
use App\Entity\UserArticle;
use App\Repository\UserArticleRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

class PersonalizationService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private UserArticleRepository $userArticleRepository,
        private ScoringService $scoringService,
        private LoggerInterface $logger
    ) {}

    public function updateUserPreferences(User $user, Article $article, string $action, array $metadata = []): void
    {
        try {
            $userArticle = $this->userArticleRepository->findOneBy([
                'user' => $user,
                'article' => $article
            ]);

            if (!$userArticle) {
                $userArticle = new UserArticle();
                $userArticle->setUser($user);
                $userArticle->setArticle($article);
            }

            // Update interaction data
            $interactionData = $userArticle->getInteractionData() ?? [];
            $interactionData['actions'] = $interactionData['actions'] ?? [];
            
            $interactionData['actions'][] = [
                'type' => $action,
                'timestamp' => (new \DateTime())->format('c'),
                'metadata' => $metadata
            ];

            // Track reading time if provided
            if (isset($metadata['reading_time'])) {
                $interactionData['total_reading_time'] = 
                    ($interactionData['total_reading_time'] ?? 0) + $metadata['reading_time'];
            }

            $userArticle->setInteractionData($interactionData);

            // Update personalization score
            $personalizedScore = $this->scoringService->calculatePersonalizedScore($article, $user);
            $userArticle->setPersonalizationScore($personalizedScore);

            // Update reading status based on action
            switch ($action) {
                case 'read':
                    $userArticle->setIsRead(true);
                    break;
                case 'star':
                    $userArticle->setIsStarred(true);
                    break;
                case 'unstar':
                    $userArticle->setIsStarred(false);
                    break;
            }

            $this->entityManager->persist($userArticle);
            $this->entityManager->flush();

            $this->logger->info('User preferences updated', [
                'userId' => $user->getId(),
                'articleId' => $article->getId(),
                'action' => $action
            ]);

        } catch (\Exception $e) {
            $this->logger->error('Failed to update user preferences', [
                'userId' => $user->getId(),
                'articleId' => $article->getId(),
                'action' => $action,
                'error' => $e->getMessage()
            ]);
        }
    }

    public function getPersonalizedFeed(User $user, int $limit = 20): array
    {
        try {
            // Get all unread articles with AI scores
            $articles = $this->entityManager->getRepository(Article::class)
                ->createQueryBuilder('a')
                ->leftJoin('a.userArticles', 'ua', 'WITH', 'ua.user = :user')
                ->where('ua.isRead IS NULL OR ua.isRead = false')
                ->andWhere('a.aiScore IS NOT NULL')
                ->setParameter('user', $user)
                ->orderBy('a.publishedAt', 'DESC')
                ->setMaxResults($limit * 3) // Get more to allow for personalization
                ->getQuery()
                ->getResult();

            // Calculate personalized scores and sort
            $scoredArticles = [];
            foreach ($articles as $article) {
                $personalizedScore = $this->scoringService->calculatePersonalizedScore($article, $user);
                $scoredArticles[] = [
                    'article' => $article,
                    'score' => $personalizedScore
                ];
            }

            // Sort by personalized score
            usort($scoredArticles, fn($a, $b) => $b['score'] <=> $a['score']);

            // Return top articles
            $result = array_slice($scoredArticles, 0, $limit);

            $this->logger->info('Personalized feed generated', [
                'userId' => $user->getId(),
                'totalArticles' => count($articles),
                'returnedArticles' => count($result)
            ]);

            return array_map(fn($item) => $item['article'], $result);

        } catch (\Exception $e) {
            $this->logger->error('Failed to generate personalized feed', [
                'userId' => $user->getId(),
                'error' => $e->getMessage()
            ]);

            // Fallback to recent articles
            return $this->entityManager->getRepository(Article::class)
                ->createQueryBuilder('a')
                ->orderBy('a.publishedAt', 'DESC')
                ->setMaxResults($limit)
                ->getQuery()
                ->getResult();
        }
    }

    public function getUserInsights(User $user): array
    {
        try {
            $userArticles = $this->userArticleRepository->findRecentUserActivity($user, 100);
            
            $insights = [
                'total_articles_read' => 0,
                'total_articles_starred' => 0,
                'average_reading_time' => 0,
                'top_categories' => [],
                'reading_patterns' => [],
                'engagement_score' => 0.0
            ];

            if (empty($userArticles)) {
                return $insights;
            }

            $totalReadingTime = 0;
            $categoryEngagement = [];
            $hourlyActivity = array_fill(0, 24, 0);
            $readArticles = 0;
            $starredArticles = 0;

            foreach ($userArticles as $userArticle) {
                if ($userArticle->isRead()) {
                    $readArticles++;
                }

                if ($userArticle->isStarred()) {
                    $starredArticles++;
                }

                // Extract reading time from interaction data
                $interactionData = $userArticle->getInteractionData() ?? [];
                if (isset($interactionData['total_reading_time'])) {
                    $totalReadingTime += $interactionData['total_reading_time'];
                }

                // Track category engagement
                $article = $userArticle->getArticle();
                $categories = $article->getAiCategories() ?? [];
                
                foreach ($categories as $category) {
                    $categoryName = $category['name'] ?? '';
                    if (!empty($categoryName)) {
                        if (!isset($categoryEngagement[$categoryName])) {
                            $categoryEngagement[$categoryName] = ['interactions' => 0, 'engagement' => 0];
                        }
                        $categoryEngagement[$categoryName]['interactions']++;
                        
                        if ($userArticle->isRead() || $userArticle->isStarred()) {
                            $categoryEngagement[$categoryName]['engagement']++;
                        }
                    }
                }

                // Track reading time patterns
                if ($userArticle->getReadAt()) {
                    $hour = (int) $userArticle->getReadAt()->format('H');
                    $hourlyActivity[$hour]++;
                }
            }

            // Calculate insights
            $insights['total_articles_read'] = $readArticles;
            $insights['total_articles_starred'] = $starredArticles;
            $insights['average_reading_time'] = $readArticles > 0 ? round($totalReadingTime / $readArticles, 1) : 0;

            // Top categories by engagement rate
            $topCategories = [];
            foreach ($categoryEngagement as $category => $data) {
                if ($data['interactions'] >= 3) { // Minimum threshold
                    $engagementRate = $data['engagement'] / $data['interactions'];
                    $topCategories[] = [
                        'category' => $category,
                        'engagement_rate' => round($engagementRate, 2),
                        'total_interactions' => $data['interactions']
                    ];
                }
            }

            usort($topCategories, fn($a, $b) => $b['engagement_rate'] <=> $a['engagement_rate']);
            $insights['top_categories'] = array_slice($topCategories, 0, 5);

            // Reading patterns
            $peakHours = [];
            $maxActivity = max($hourlyActivity);
            if ($maxActivity > 0) {
                for ($hour = 0; $hour < 24; $hour++) {
                    if ($hourlyActivity[$hour] > $maxActivity * 0.5) {
                        $peakHours[] = $hour;
                    }
                }
            }
            $insights['reading_patterns'] = $peakHours;

            // Overall engagement score
            $totalArticles = count($userArticles);
            $engagementScore = $totalArticles > 0 ? 
                (($readArticles + $starredArticles * 2) / ($totalArticles * 2)) : 0;
            $insights['engagement_score'] = round($engagementScore, 2);

            $this->logger->info('User insights generated', [
                'userId' => $user->getId(),
                'totalArticles' => $totalArticles,
                'engagementScore' => $insights['engagement_score']
            ]);

            return $insights;

        } catch (\Exception $e) {
            $this->logger->error('Failed to generate user insights', [
                'userId' => $user->getId(),
                'error' => $e->getMessage()
            ]);

            return [
                'total_articles_read' => 0,
                'total_articles_starred' => 0,
                'average_reading_time' => 0,
                'top_categories' => [],
                'reading_patterns' => [],
                'engagement_score' => 0.0
            ];
        }
    }

    public function recommendSimilarArticles(Article $article, User $user, int $limit = 5): array
    {
        try {
            $categories = $article->getAiCategories() ?? [];
            
            if (empty($categories)) {
                return [];
            }

            // Get category names
            $categoryNames = array_map(fn($cat) => $cat['name'], $categories);

            // Find articles with similar categories
            $similarArticles = $this->entityManager->getRepository(Article::class)
                ->createQueryBuilder('a')
                ->leftJoin('a.userArticles', 'ua', 'WITH', 'ua.user = :user')
                ->where('a.id != :articleId')
                ->andWhere('ua.isRead IS NULL OR ua.isRead = false')
                ->andWhere('a.aiCategories IS NOT NULL')
                ->setParameter('user', $user)
                ->setParameter('articleId', $article->getId())
                ->orderBy('a.aiScore', 'DESC')
                ->setMaxResults($limit * 2)
                ->getQuery()
                ->getResult();

            // Score articles by category similarity
            $scoredArticles = [];
            foreach ($similarArticles as $similarArticle) {
                $similarity = $this->calculateCategorySimilarity($categories, $similarArticle->getAiCategories() ?? []);
                if ($similarity > 0.3) { // Minimum similarity threshold
                    $scoredArticles[] = [
                        'article' => $similarArticle,
                        'similarity' => $similarity
                    ];
                }
            }

            // Sort by similarity
            usort($scoredArticles, fn($a, $b) => $b['similarity'] <=> $a['similarity']);

            return array_map(fn($item) => $item['article'], array_slice($scoredArticles, 0, $limit));

        } catch (\Exception $e) {
            $this->logger->error('Failed to recommend similar articles', [
                'articleId' => $article->getId(),
                'userId' => $user->getId(),
                'error' => $e->getMessage()
            ]);

            return [];
        }
    }

    private function calculateCategorySimilarity(array $categories1, array $categories2): float
    {
        if (empty($categories1) || empty($categories2)) {
            return 0.0;
        }

        $names1 = array_map(fn($cat) => $cat['name'], $categories1);
        $names2 = array_map(fn($cat) => $cat['name'], $categories2);

        $intersection = array_intersect($names1, $names2);
        $union = array_unique(array_merge($names1, $names2));

        return count($union) > 0 ? count($intersection) / count($union) : 0.0;
    }
}