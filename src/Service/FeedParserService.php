<?php

namespace App\Service;

use App\Entity\Article;
use App\Entity\Feed;
use Laminas\Feed\Reader\Reader;
use Laminas\Feed\Reader\Entry\EntryInterface;
use Laminas\Feed\Reader\FeedInterface;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Psr\Log\LoggerInterface;

class FeedParserService
{
    private SecureHttpClient $secureHttpClient;
    private UrlSecurityValidator $urlValidator;
    private LoggerInterface $logger;

    public function __construct(
        SecureHttpClient $secureHttpClient,
        UrlSecurityValidator $urlValidator,
        LoggerInterface $logger
    ) {
        $this->secureHttpClient = $secureHttpClient;
        $this->urlValidator = $urlValidator;
        $this->logger = $logger;
    }

    public function validateFeed(string $url): FeedValidationResult
    {
        try {
            // Step 1: Validate URL for security compliance
            $urlValidation = $this->urlValidator->validateUrl($url);
            if (!$urlValidation->isValid()) {
                $this->logger->warning('Feed validation failed due to URL security violation', [
                    'url' => $url,
                    'reason' => $urlValidation->getMessage()
                ]);
                return new FeedValidationResult(false, $urlValidation->getMessage());
            }

            // Step 2: Make secure HTTP request
            $secureResponse = $this->secureHttpClient->request('GET', $url, [
                'headers' => [
                    'Accept' => 'application/rss+xml, application/atom+xml, application/xml, text/xml',
                ],
            ]);

            if (!$secureResponse->isSuccess()) {
                return new FeedValidationResult(false, $secureResponse->getError());
            }

            $response = $secureResponse->getResponse();
            if ($response->getStatusCode() !== 200) {
                return new FeedValidationResult(false, 'HTTP error: ' . $response->getStatusCode());
            }

            // Step 3: Parse and validate feed content
            $content = $secureResponse->getContent();
            $feed = Reader::importString($content);
            
            $this->logger->info('Feed validation successful', [
                'url' => $url,
                'feed_title' => $feed->getTitle(),
                'content_length' => strlen($content)
            ]);
            
            return new FeedValidationResult(true, 'Feed is valid', $feed);
        } catch (\Exception $e) {
            $this->logger->error('Feed validation error', [
                'url' => $url,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return new FeedValidationResult(false, 'Error parsing feed: ' . $e->getMessage());
        }
    }

    public function parseFeed(string $url): ParsedFeed
    {
        // Step 1: Validate URL for security compliance
        $urlValidation = $this->urlValidator->validateUrl($url);
        if (!$urlValidation->isValid()) {
            $this->logger->warning('Feed parsing failed due to URL security violation', [
                'url' => $url,
                'reason' => $urlValidation->getMessage()
            ]);
            throw new \InvalidArgumentException('URL validation failed: ' . $urlValidation->getMessage());
        }

        // Step 2: Make secure HTTP request
        $secureResponse = $this->secureHttpClient->request('GET', $url, [
            'headers' => [
                'Accept' => 'application/rss+xml, application/atom+xml, application/xml, text/xml',
            ],
        ]);

        if (!$secureResponse->isSuccess()) {
            throw new \RuntimeException('HTTP request failed: ' . $secureResponse->getError());
        }

        $response = $secureResponse->getResponse();
        if ($response->getStatusCode() !== 200) {
            throw new \RuntimeException('HTTP error: ' . $response->getStatusCode());
        }

        // Step 3: Parse feed content
        $content = $secureResponse->getContent();
        $feed = Reader::importString($content);

        $this->logger->info('Feed parsing successful', [
            'url' => $url,
            'feed_title' => $feed->getTitle(),
            'content_length' => strlen($content)
        ]);

        return new ParsedFeed($feed);
    }

    public function extractArticles(ParsedFeed $parsedFeed): array
    {
        $articles = [];
        $feed = $parsedFeed->getFeed();

        foreach ($feed as $entry) {
            $articles[] = $this->createArticleFromEntry($entry);
        }

        return $articles;
    }

    private function createArticleFromEntry(EntryInterface $entry): Article
    {
        $article = new Article();
        
        $article->setGuid($entry->getId() ?: $entry->getLink());
        $article->setTitle($entry->getTitle());
        $article->setUrl($entry->getLink());
        $article->setPublishedAt($entry->getDateCreated() ?: new \DateTime());
        
        $content = $entry->getContent() ?: $entry->getDescription();
        $article->setContent($this->normalizeContent($content));
        
        if ($entry->getDescription()) {
            $article->setSummary($this->normalizeContent($entry->getDescription()));
        }

        return $article;
    }

    public function normalizeContent(string $content): string
    {
        // Remove potentially harmful tags and attributes
        $content = strip_tags($content, '<p><br><strong><em><ul><ol><li><h1><h2><h3><h4><h5><h6><a><img>');
        
        // Remove javascript and other dangerous attributes
        $content = preg_replace('/on\w+="[^"]*"/i', '', $content);
        $content = preg_replace('/javascript:/i', '', $content);
        
        return trim($content);
    }

    public function updateFeedFromParsed(Feed $feed, ParsedFeed $parsedFeed): Feed
    {
        $laminasFeed = $parsedFeed->getFeed();
        
        $feed->setTitle($laminasFeed->getTitle());
        $feed->setDescription($laminasFeed->getDescription());
        $feed->setSiteUrl($laminasFeed->getLink());
        $feed->setLastUpdated(new \DateTime());
        
        return $feed;
    }
}

class FeedValidationResult
{
    private bool $valid;
    private string $message;
    private ?FeedInterface $feed;

    public function __construct(bool $valid, string $message, ?FeedInterface $feed = null)
    {
        $this->valid = $valid;
        $this->message = $message;
        $this->feed = $feed;
    }

    public function isValid(): bool
    {
        return $this->valid;
    }

    public function getMessage(): string
    {
        return $this->message;
    }

    public function getFeed(): ?FeedInterface
    {
        return $this->feed;
    }
}

class ParsedFeed
{
    private FeedInterface $feed;

    public function __construct(FeedInterface $feed)
    {
        $this->feed = $feed;
    }

    public function getFeed(): FeedInterface
    {
        return $this->feed;
    }
}