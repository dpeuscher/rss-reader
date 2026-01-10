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
    private UrlSecurityValidator $urlValidator;

    public function __construct(HttpClientInterface $httpClient = null, UrlSecurityValidator $urlValidator = null)
    {
        $this->urlValidator = $urlValidator ?: new UrlSecurityValidator();
        $this->httpClient = $httpClient ?: $this->createSecureHttpClient();
    }

    private function createSecureHttpClient(): HttpClientInterface
    {
        return HttpClient::create($this->urlValidator->getSecureHttpClientOptions());
    }

    public function validateFeed(string $url): FeedValidationResult
    {
        try {
            $response = $this->httpClient->request('GET', $url, [
                'max_redirects' => 3,
                'stream' => false,
            ]);

            if ($response->getStatusCode() !== 200) {
                return new FeedValidationResult(false, 'Error parsing feed');
            }

            // Check response size before getting content
            $headers = $response->getHeaders(false);
            if (isset($headers['content-length'][0])) {
                $contentLength = (int) $headers['content-length'][0];
                if ($contentLength > $this->urlValidator->getMaxResponseSize()) {
                    return new FeedValidationResult(false, 'Error parsing feed');
                }
            }

            $content = $response->getContent();
            
            // Additional size check after content download
            if (strlen($content) > $this->urlValidator->getMaxResponseSize()) {
                return new FeedValidationResult(false, 'Error parsing feed');
            }

            $feed = Reader::importString($content);
            
            return new FeedValidationResult(true, 'Feed is valid', $feed);
        } catch (\Exception $e) {
            return new FeedValidationResult(false, 'Error parsing feed');
        }
    }

    public function parseFeed(string $url): ParsedFeed
    {
        $response = $this->httpClient->request('GET', $url, [
            'max_redirects' => 3,
            'stream' => false,
        ]);

        // Check response size before getting content
        $headers = $response->getHeaders(false);
        if (isset($headers['content-length'][0])) {
            $contentLength = (int) $headers['content-length'][0];
            if ($contentLength > $this->urlValidator->getMaxResponseSize()) {
                throw new \Exception('Response too large');
            }
        }

        $content = $response->getContent();
        
        // Additional size check after content download
        if (strlen($content) > $this->urlValidator->getMaxResponseSize()) {
            throw new \Exception('Response too large');
        }

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