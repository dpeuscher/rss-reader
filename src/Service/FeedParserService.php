<?php

namespace App\Service;

use App\Entity\Article;
use App\Entity\Feed;
use Laminas\Feed\Reader\Reader;
use Laminas\Feed\Reader\Entry\EntryInterface;
use Laminas\Feed\Reader\FeedInterface;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class FeedParserService
{
    private HttpClientInterface $httpClient;

    public function __construct(
        private UrlValidator $urlValidator,
        HttpClientInterface $httpClient = null
    ) {
        $this->httpClient = $httpClient ?: HttpClient::create([
            'timeout' => 30,
            'max_redirects' => 5,
            'headers' => [
                'User-Agent' => 'RSS Reader/1.0',
            ],
        ]);
    }

    public function validateFeed(string $url): FeedValidationResult
    {
        try {
            // First validate the URL for security
            $urlValidation = $this->urlValidator->validateUrl($url);
            if (!$urlValidation->isValid()) {
                return new FeedValidationResult(false, 'Invalid feed URL: ' . $urlValidation->getMessage());
            }

            // Use the validated URL (may be normalized)
            $validatedUrl = $urlValidation->getValidatedUrl() ?: $url;

            $response = $this->httpClient->request('GET', $validatedUrl, [
                'max_duration' => 30,
                'max_size' => 10 * 1024 * 1024, // 10MB limit
            ]);

            if ($response->getStatusCode() !== 200) {
                return new FeedValidationResult(false, 'Unable to fetch feed');
            }

            $content = $response->getContent();
            $feed = Reader::importString($content);
            
            return new FeedValidationResult(true, 'Feed is valid', $feed);
        } catch (\Exception $e) {
            return new FeedValidationResult(false, 'Unable to validate feed');
        }
    }

    public function parseFeed(string $url): ParsedFeed
    {
        // Validate URL first for security
        $urlValidation = $this->urlValidator->validateUrl($url);
        if (!$urlValidation->isValid()) {
            throw new \InvalidArgumentException('Invalid feed URL: ' . $urlValidation->getMessage());
        }

        // Use the validated URL (may be normalized)
        $validatedUrl = $urlValidation->getValidatedUrl() ?: $url;

        $response = $this->httpClient->request('GET', $validatedUrl, [
            'max_duration' => 30,
            'max_size' => 10 * 1024 * 1024, // 10MB limit
        ]);

        $content = $response->getContent();
        $feed = Reader::importString($content);

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