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
        
        // Sanitize GUID
        $guid = $entry->getId() ?: $entry->getLink();
        $article->setGuid(strip_tags($guid ?: ''));
        
        // Sanitize title (remove HTML tags but preserve text content)
        $title = $entry->getTitle() ?: '';
        $article->setTitle(strip_tags($title));
        
        // Sanitize URL
        $url = $entry->getLink() ?: '';
        $article->setUrl($this->sanitizeUrl($url));
        
        $article->setPublishedAt($entry->getDateCreated() ?: new \DateTime());
        
        // Sanitize content
        $content = $entry->getContent() ?: $entry->getDescription();
        $article->setContent($this->normalizeContent($content ?: ''));
        
        // Sanitize summary
        if ($entry->getDescription()) {
            $article->setSummary($this->normalizeContent($entry->getDescription()));
        }

        return $article;
    }

    public function normalizeContent(string $content): string
    {
        // First, remove any null bytes and normalize whitespace
        $content = str_replace("\0", '', $content);
        $content = preg_replace('/\s+/', ' ', $content);
        
        // Only allow safe HTML tags - removed <a> and <img> to prevent URL-based attacks
        $allowedTags = '<p><br><strong><em><b><i><ul><ol><li><h1><h2><h3><h4><h5><h6><blockquote><code><pre>';
        $content = strip_tags($content, $allowedTags);
        
        // Remove all event handlers (onclick, onload, etc.)
        $content = preg_replace('/\s*on\w+\s*=\s*["\'][^"\']*["\']?/i', '', $content);
        
        // Remove dangerous URL schemes
        $content = preg_replace('/\s*(javascript|vbscript|data|file|ftp):/i', '', $content);
        
        // Remove CSS expressions and imports
        $content = preg_replace('/expression\s*\(/i', '', $content);
        $content = preg_replace('/@import/i', '', $content);
        
        // Remove style attributes that could contain malicious CSS
        $content = preg_replace('/\s*style\s*=\s*["\'][^"\']*["\']?/i', '', $content);
        
        // Remove any remaining script tags and their content
        $content = preg_replace('/<script\b[^<]*(?:(?!<\/script>)<[^<]*)*<\/script>/mi', '', $content);
        
        // Remove object, embed, applet, iframe tags
        $content = preg_replace('/<(object|embed|applet|iframe|form)\b[^>]*>.*?<\/\1>/si', '', $content);
        
        // Remove link tags (could be used for CSS injection)
        $content = preg_replace('/<link\b[^>]*>/i', '', $content);
        
        // Remove meta tags
        $content = preg_replace('/<meta\b[^>]*>/i', '', $content);
        
        // Decode and re-encode to prevent double encoding attacks
        $content = html_entity_decode($content, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        
        return trim($content);
    }

    public function updateFeedFromParsed(Feed $feed, ParsedFeed $parsedFeed): Feed
    {
        $laminasFeed = $parsedFeed->getFeed();
        
        // Sanitize title (remove HTML tags but preserve text content)
        $title = $laminasFeed->getTitle() ?: '';
        $feed->setTitle(strip_tags($title));
        
        // Sanitize description using our enhanced sanitization
        $description = $laminasFeed->getDescription() ?: '';
        $feed->setDescription($this->normalizeContent($description));
        
        // Validate and sanitize URL
        $siteUrl = $laminasFeed->getLink() ?: '';
        $feed->setSiteUrl($this->sanitizeUrl($siteUrl));
        
        $feed->setLastUpdated(new \DateTime());
        
        return $feed;
    }

    private function sanitizeUrl(string $url): string
    {
        // Remove any null bytes and trim
        $url = str_replace("\0", '', trim($url));
        
        // Only allow http and https schemes
        if (!preg_match('/^https?:\/\//i', $url)) {
            return '';
        }
        
        // Remove dangerous characters that could break HTML
        $url = preg_replace('/[<>"\'\\\\]/', '', $url);
        
        // Basic URL validation
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return '';
        }
        
        return $url;
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