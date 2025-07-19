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
    private HttpClientInterface $httpClient;

    public function __construct(
        private UrlValidatorInterface $urlValidator,
        private LoggerInterface $logger,
        HttpClientInterface $httpClient = null
    ) {
        $this->httpClient = $httpClient ?: HttpClient::create();
    }

    public function validateFeed(string $url): FeedValidationResult
    {
        try {
            // Validate URL for SSRF protection
            if (!$this->urlValidator->validateFeedUrl($url)) {
                $this->logger->warning('URL validation failed during feed validation', ['url' => $url]);
                return new FeedValidationResult(false, 'Invalid or unsafe URL provided');
            }

            $response = $this->httpClient->request('GET', $url, [
                'timeout' => 10,
                'max_redirects' => 0,  // Disable automatic redirects for security
                'max_duration' => 30,  // Prevent long-running requests
                'headers' => [
                    'User-Agent' => 'RSS Reader/1.0',
                ],
            ]);

            if ($response->getStatusCode() !== 200) {
                $this->logger->info('HTTP error during feed validation', [
                    'url' => $url,
                    'status_code' => $response->getStatusCode()
                ]);
                return new FeedValidationResult(false, 'HTTP error: ' . $response->getStatusCode());
            }

            $content = $response->getContent();
            $feed = Reader::importString($content);
            
            $this->logger->info('Feed validation successful', ['url' => $url]);
            return new FeedValidationResult(true, 'Feed is valid', $feed);
        } catch (\Exception $e) {
            $this->logger->error('Exception during feed validation', [
                'url' => $url,
                'error' => $e->getMessage()
            ]);
            return new FeedValidationResult(false, 'Error parsing feed: ' . $e->getMessage());
        }
    }

    public function parseFeed(string $url): ParsedFeed
    {
        // Validate URL for SSRF protection
        if (!$this->urlValidator->validateFeedUrl($url)) {
            $this->logger->warning('URL validation failed during feed parsing', ['url' => $url]);
            throw new \InvalidArgumentException('Invalid or unsafe URL provided');
        }

        $response = $this->httpClient->request('GET', $url, [
            'timeout' => 10,
            'max_redirects' => 0,  // Disable automatic redirects for security
            'max_duration' => 30,  // Prevent long-running requests
            'headers' => [
                'User-Agent' => 'RSS Reader/1.0',
            ],
        ]);

        $content = $response->getContent();
        $feed = Reader::importString($content);

        $this->logger->info('Feed parsing successful', ['url' => $url]);
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