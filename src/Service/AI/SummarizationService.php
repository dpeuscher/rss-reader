<?php

namespace App\Service\AI;

use App\Entity\Article;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Psr\Log\LoggerInterface;

class SummarizationService
{
    private const MAX_CONTENT_LENGTH = 4000; // Limit content for LLM processing
    private const CACHE_TTL = 86400; // 24 hours

    public function __construct(
        private HttpClientInterface $httpClient,
        private LoggerInterface $logger,
        private string $openaiApiKey = '',
        private string $anthropicApiKey = ''
    ) {}

    public function generateSummary(Article $article): string
    {
        try {
            $content = $this->prepareContent($article);
            
            if (empty($content)) {
                return 'Content not available for summarization.';
            }

            // Check if AI services are configured
            $hasAIServices = !empty($this->openaiApiKey) || !empty($this->anthropicApiKey);
            
            if (!$hasAIServices) {
                $this->logger->notice('No AI services configured, falling back to extractive summary', [
                    'articleId' => $article->getId(),
                    'suggestion' => 'Configure OPENAI_API_KEY or ANTHROPIC_API_KEY for enhanced AI summarization'
                ]);
                return $this->generateExtractiveSummary($content);
            }

            // Try primary LLM (OpenAI)
            if (!empty($this->openaiApiKey)) {
                try {
                    return $this->generateOpenAISummary($content);
                } catch (\Exception $e) {
                    $this->logger->warning('OpenAI summarization failed, trying fallback', [
                        'error' => $e->getMessage()
                    ]);
                }
            }

            // Fallback to Anthropic
            if (!empty($this->anthropicApiKey)) {
                try {
                    return $this->generateAnthropicSummary($content);
                } catch (\Exception $e) {
                    $this->logger->warning('Anthropic summarization failed', [
                        'error' => $e->getMessage()
                    ]);
                }
            }

            // Final fallback to extractive summary
            $this->logger->info('All AI services failed, using extractive summarization', [
                'articleId' => $article->getId()
            ]);
            return $this->generateExtractiveSummary($content);

        } catch (\Exception $e) {
            $this->logger->error('All summarization methods failed', [
                'articleId' => $article->getId(),
                'error' => $e->getMessage()
            ]);
            
            return 'Summary unavailable.';
        }
    }

    public function isAIServiceConfigured(): bool
    {
        return !empty($this->openaiApiKey) || !empty($this->anthropicApiKey);
    }

    public function getConfigurationStatus(): array
    {
        return [
            'openai_configured' => !empty($this->openaiApiKey),
            'anthropic_configured' => !empty($this->anthropicApiKey),
            'ai_services_available' => $this->isAIServiceConfigured()
        ];
    }

    private function prepareContent(Article $article): string
    {
        $content = $article->getContent() ?? '';
        $title = $article->getTitle() ?? '';
        
        // Clean and prepare content
        $content = strip_tags($content);
        $content = preg_replace('/\s+/', ' ', $content);
        $content = trim($content);
        
        // Combine title and content
        $fullContent = $title . "\n\n" . $content;
        
        // Truncate if too long
        if (strlen($fullContent) > self::MAX_CONTENT_LENGTH) {
            $fullContent = substr($fullContent, 0, self::MAX_CONTENT_LENGTH) . '...';
        }
        
        return $fullContent;
    }

    private function generateOpenAISummary(string $content): string
    {
        $response = $this->httpClient->request('POST', 'https://api.openai.com/v1/chat/completions', [
            'headers' => [
                'Authorization' => 'Bearer ' . $this->openaiApiKey,
                'Content-Type' => 'application/json',
            ],
            'json' => [
                'model' => 'gpt-4-turbo',
                'messages' => [
                    [
                        'role' => 'system',
                        'content' => 'You are a helpful assistant that creates concise, informative summaries of articles. Provide a 2-3 sentence summary that captures the key points and main insights.'
                    ],
                    [
                        'role' => 'user',
                        'content' => "Please summarize this article:\n\n{$content}"
                    ]
                ],
                'max_tokens' => 150,
                'temperature' => 0.3
            ],
            'timeout' => 30
        ]);

        $data = $response->toArray();
        
        if (isset($data['choices'][0]['message']['content'])) {
            return trim($data['choices'][0]['message']['content']);
        }
        
        throw new \Exception('Invalid OpenAI response format');
    }

    private function generateAnthropicSummary(string $content): string
    {
        $response = $this->httpClient->request('POST', 'https://api.anthropic.com/v1/messages', [
            'headers' => [
                'x-api-key' => $this->anthropicApiKey,
                'Content-Type' => 'application/json',
                'anthropic-version' => '2023-06-01'
            ],
            'json' => [
                'model' => 'claude-3-sonnet-20240229',
                'max_tokens' => 150,
                'messages' => [
                    [
                        'role' => 'user',
                        'content' => "Please provide a concise 2-3 sentence summary of this article:\n\n{$content}"
                    ]
                ]
            ],
            'timeout' => 30
        ]);

        $data = $response->toArray();
        
        if (isset($data['content'][0]['text'])) {
            return trim($data['content'][0]['text']);
        }
        
        throw new \Exception('Invalid Anthropic response format');
    }

    private function generateExtractiveSummary(string $content): string
    {
        // Simple extractive summary as fallback
        $sentences = preg_split('/[.!?]+/', $content);
        $sentences = array_filter(array_map('trim', $sentences));
        
        if (empty($sentences)) {
            return 'Content summary not available.';
        }
        
        // Take first 2-3 sentences or up to 200 characters
        $summary = '';
        $charCount = 0;
        $maxChars = 200;
        
        foreach (array_slice($sentences, 0, 3) as $sentence) {
            if ($charCount + strlen($sentence) > $maxChars && !empty($summary)) {
                break;
            }
            $summary .= $sentence . '. ';
            $charCount += strlen($sentence) + 2;
        }
        
        return trim($summary) ?: 'Brief summary not available.';
    }
}