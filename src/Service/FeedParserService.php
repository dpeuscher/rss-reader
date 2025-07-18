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

    public function __construct(HttpClientInterface $httpClient = null)
    {
        $this->httpClient = $httpClient ?: HttpClient::create();
    }

    public function validateFeed(string $url): FeedValidationResult
    {
        try {
            $response = $this->httpClient->request('GET', $url, [
                'timeout' => 10,
                'headers' => [
                    'User-Agent' => 'RSS Reader/1.0',
                ],
            ]);

            if ($response->getStatusCode() !== 200) {
                return new FeedValidationResult(false, 'HTTP error: ' . $response->getStatusCode());
            }

            $content = $response->getContent();
            $feed = Reader::importString($content);
            
            return new FeedValidationResult(true, 'Feed is valid', $feed);
        } catch (\Exception $e) {
            return new FeedValidationResult(false, 'Error parsing feed: ' . $e->getMessage());
        }
    }

    public function parseFeed(string $url): ParsedFeed
    {
        $response = $this->httpClient->request('GET', $url, [
            'timeout' => 10,
            'headers' => [
                'User-Agent' => 'RSS Reader/1.0',
            ],
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

    private static $purifier = null;
    
    public function normalizeContent(string $content): string
    {
        if (self::$purifier === null) {
            $config = \HTMLPurifier_Config::createDefault();
            
            // Whitelist approved elements and attributes according to security requirements
            $config->set('HTML.Allowed', implode(',', [
                'p', 'br', 'strong', 'em', 'b', 'i', 'u',
                'a[href|title]', 'img[src|alt|title|width|height]',
                'ul', 'ol', 'li', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
                'blockquote', 'pre', 'code'
            ]));
            
            // Restrict to safe URL schemes only (http/https)
            $config->set('URI.AllowedSchemes', ['http' => true, 'https' => true]);
            
            // Explicitly forbid dangerous elements and scripts
            $config->set('HTML.ForbiddenElements', ['script', 'style', 'object', 'embed', 'iframe', 'form', 'input', 'textarea', 'select', 'button', 'svg', 'math', 'canvas', 'audio', 'video', 'source', 'track']);
            $config->set('HTML.ForbiddenAttributes', ['on*', 'style', 'javascript:*', 'vbscript:*', 'data:*']);
            
            // Enhanced security settings
            $config->set('HTML.SafeObject', false);
            $config->set('HTML.SafeEmbed', false);
            $config->set('HTML.SafeIframe', false);
            $config->set('CSS.AllowedProperties', []);
            $config->set('URI.DisableExternalResources', false);
            $config->set('URI.DisableResources', false);
            
            // Performance optimization
            $config->set('Core.CollectErrors', false);
            $config->set('Cache.SerializerPath', sys_get_temp_dir() . '/htmlpurifier');
            
            self::$purifier = new \HTMLPurifier($config);
        }
        
        // Sanitize content using HTMLPurifier
        $sanitized = self::$purifier->purify($content);
        
        // Additional security check: remove any remaining dangerous patterns
        $sanitized = preg_replace('/alert\s*\(/i', '', $sanitized);
        $sanitized = preg_replace('/javascript\s*:/i', '', $sanitized);
        $sanitized = preg_replace('/vbscript\s*:/i', '', $sanitized);
        $sanitized = preg_replace('/on\w+\s*=/i', '', $sanitized);
        
        return $sanitized;
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