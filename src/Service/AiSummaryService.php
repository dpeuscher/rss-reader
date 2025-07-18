<?php

namespace App\Service;

use App\Entity\Article;
use App\Entity\AiArticleSummary;
use App\Entity\User;
use App\Repository\AiArticleSummaryRepository;
use App\Repository\UserAiPreferenceRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Psr\Log\LoggerInterface;

class AiSummaryService
{
    private const OPENAI_API_URL = 'https://api.openai.com/v1/chat/completions';
    private const DEFAULT_MODEL = 'gpt-4o-mini';
    private const MAX_TOKENS = 2000;
    private const TEMPERATURE = 0.3;
    private const MAX_CONTENT_LENGTH = 10000;

    private HttpClientInterface $httpClient;
    private EntityManagerInterface $entityManager;
    private AiArticleSummaryRepository $summaryRepository;
    private UserAiPreferenceRepository $preferenceRepository;
    private LoggerInterface $logger;
    private string $openaiApiKey;

    public function __construct(
        HttpClientInterface $httpClient,
        EntityManagerInterface $entityManager,
        AiArticleSummaryRepository $summaryRepository,
        UserAiPreferenceRepository $preferenceRepository,
        LoggerInterface $logger,
        string $openaiApiKey
    ) {
        $this->httpClient = $httpClient;
        $this->entityManager = $entityManager;
        $this->summaryRepository = $summaryRepository;
        $this->preferenceRepository = $preferenceRepository;
        $this->logger = $logger;
        $this->openaiApiKey = $openaiApiKey;
    }

    public function hasUserConsent(User $user): bool
    {
        return $user->hasAiConsent();
    }

    public function summarizeArticle(Article $article, User $user = null): ?AiArticleSummary
    {
        $startTime = microtime(true);
        
        try {
            $article->setAiProcessingStatus('processing');
            $this->entityManager->flush();

            $summaryLength = $this->getUserSummaryLength($user);
            $content = $this->prepareContentForAi($article);
            
            if (empty($content)) {
                $article->setAiProcessingStatus('failed');
                $this->entityManager->flush();
                return null;
            }

            $prompt = $this->buildPrompt($content, $summaryLength);
            $response = $this->callOpenAiApi($prompt);

            if (!$response) {
                $article->setAiProcessingStatus('failed');
                $this->entityManager->flush();
                return null;
            }

            $summary = $this->createSummary($article, $response, $startTime);
            $article->setAiProcessingStatus('completed');
            $article->setEstimatedReadingTime($this->calculateReadingTime($content));
            
            $this->entityManager->persist($summary);
            $this->entityManager->flush();

            $this->logger->info('AI summary generated', [
                'article_id' => $article->getId(),
                'processing_time' => $summary->getProcessingTime(),
                'summary_length' => strlen($summary->getSummaryText())
            ]);

            return $summary;

        } catch (\Exception $e) {
            $article->setAiProcessingStatus('failed');
            $this->entityManager->flush();
            
            $this->logger->error('AI summarization failed', [
                'article_id' => $article->getId(),
                'error' => $e->getMessage()
            ]);
            
            return null;
        }
    }

    private function getUserSummaryLength(User $user = null): string
    {
        if (!$user || !$user->getAiPreference()) {
            return 'medium';
        }

        return $user->getAiPreference()->getPreferredSummaryLength();
    }

    private function prepareContentForAi(Article $article): string
    {
        $content = $article->getContent();
        
        // Remove HTML tags and normalize whitespace
        $content = strip_tags($content);
        $content = preg_replace('/\s+/', ' ', $content);
        $content = trim($content);
        
        // Truncate if too long
        if (strlen($content) > self::MAX_CONTENT_LENGTH) {
            $content = substr($content, 0, self::MAX_CONTENT_LENGTH) . '...';
        }
        
        // Filter out sensitive information
        $content = $this->filterSensitiveContent($content);
        
        return $content;
    }

    private function filterSensitiveContent(string $content): string
    {
        // Remove email addresses
        $content = preg_replace('/\S+@\S+\.\S+/', '[email]', $content);
        
        // Remove URLs
        $content = preg_replace('/https?:\/\/[^\s]+/', '[url]', $content);
        
        // Remove phone numbers (basic pattern)
        $content = preg_replace('/\b\d{3}[-.]?\d{3}[-.]?\d{4}\b/', '[phone]', $content);
        
        return $content;
    }

    private function buildPrompt(string $content, string $summaryLength): string
    {
        $lengthInstruction = $this->getLengthInstruction($summaryLength);
        
        return "Please provide a concise summary of the following article. {$lengthInstruction}

Also extract key topics and entities from the article as a JSON array.

Article content:
{$content}

Please respond with a JSON object containing:
- summary: The article summary text
- topics: Array of key topics/entities (max 5)

Example response:
{
  \"summary\": \"Your summary here...\",
  \"topics\": [\"technology\", \"AI\", \"business\"]
}";
    }

    private function getLengthInstruction(string $summaryLength): string
    {
        return match($summaryLength) {
            'short' => 'Keep the summary to 1-2 sentences.',
            'long' => 'Provide a detailed summary of 4-5 sentences.',
            default => 'Provide a summary of 2-3 sentences.'
        };
    }

    private function callOpenAiApi(string $prompt): ?array
    {
        try {
            $response = $this->httpClient->request('POST', self::OPENAI_API_URL, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->openaiApiKey,
                    'Content-Type' => 'application/json',
                ],
                'json' => [
                    'model' => self::DEFAULT_MODEL,
                    'messages' => [
                        [
                            'role' => 'system',
                            'content' => 'You are a helpful assistant that summarizes articles and extracts key topics. Always respond with valid JSON.'
                        ],
                        [
                            'role' => 'user',
                            'content' => $prompt
                        ]
                    ],
                    'max_tokens' => self::MAX_TOKENS,
                    'temperature' => self::TEMPERATURE,
                    'response_format' => ['type' => 'json_object']
                ],
                'timeout' => 30
            ]);

            if ($response->getStatusCode() !== 200) {
                $this->logger->error('OpenAI API error', [
                    'status_code' => $response->getStatusCode(),
                    'response' => $response->getContent(false)
                ]);
                return null;
            }

            $data = $response->toArray();
            
            if (!isset($data['choices'][0]['message']['content'])) {
                $this->logger->error('Invalid OpenAI response format', ['response' => $data]);
                return null;
            }

            $aiResponse = json_decode($data['choices'][0]['message']['content'], true);
            
            if (!$aiResponse || !isset($aiResponse['summary'])) {
                $this->logger->error('Invalid AI response content', ['content' => $data['choices'][0]['message']['content']]);
                return null;
            }

            return $aiResponse;

        } catch (\Exception $e) {
            $this->logger->error('OpenAI API call failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return null;
        }
    }

    private function createSummary(Article $article, array $response, float $startTime): AiArticleSummary
    {
        $processingTime = (int) round((microtime(true) - $startTime) * 1000);
        
        $summary = new AiArticleSummary();
        $summary->setArticle($article);
        $summary->setSummaryText($response['summary']);
        $summary->setTopics($response['topics'] ?? []);
        $summary->setProcessingTime($processingTime);
        $summary->setAiProvider('openai');
        
        return $summary;
    }

    private function calculateReadingTime(string $content): int
    {
        // Average reading speed: 200-250 words per minute
        $wordsPerMinute = 225;
        $wordCount = str_word_count($content);
        
        return max(1, (int) round($wordCount / $wordsPerMinute));
    }

    public function getProcessingStats(): array
    {
        return $this->summaryRepository->getProcessingStats();
    }
}