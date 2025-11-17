<?php

namespace App\Service;

use App\Entity\Article;
use App\Entity\Feed;
use Laminas\Feed\Reader\Reader;
use Laminas\Feed\Reader\Entry\EntryInterface;
use Laminas\Feed\Reader\FeedInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class FeedParserService
{
    private HttpClientInterface $httpClient;
    private LoggerInterface $logger;

    public function __construct(HttpClientInterface $httpClient = null, LoggerInterface $logger = null)
    {
        $this->httpClient = $httpClient ?: HttpClient::create();
        $this->logger = $logger;
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

    public function extractArticles(ParsedFeed $parsedFeed, ?int $limit = null): array
    {
        $articles = [];
        $feed = $parsedFeed->getFeed();
        $count = 0;

        foreach ($feed as $entry) {
            $articles[] = $this->createArticleFromEntry($entry);
            $count++;
            
            if ($limit !== null && $count >= $limit) {
                break;
            }
        }

        // Sort by publication date (newest first) in case feed entries aren't in order
        // First check if articles are already sorted to avoid unnecessary sorting
        $needsSorting = false;
        if (count($articles) > 1) {
            for ($i = 0; $i < count($articles) - 1; $i++) {
                if ($articles[$i]->getPublishedAt() < $articles[$i + 1]->getPublishedAt()) {
                    $needsSorting = true;
                    break;
                }
            }
        }
        
        if ($needsSorting) {
            usort($articles, function(Article $a, Article $b) {
                return $b->getPublishedAt() <=> $a->getPublishedAt();
            });
        }

        return $articles;
    }

    private function createArticleFromEntry(EntryInterface $entry): Article
    {
        $article = new Article();
        
        $article->setGuid($entry->getId() ?: $entry->getLink());
        $article->setTitle($entry->getTitle());
        $article->setUrl($entry->getLink());
        $publishedAt = $entry->getDateCreated();
        if (!$publishedAt) {
            $publishedAt = new \DateTime();
            if ($this->logger) {
                $this->logger->warning('Feed entry missing published date, using current time as fallback', [
                    'entry_id' => $entry->getId(),
                    'entry_link' => $entry->getLink(),
                    'entry_title' => $entry->getTitle(),
                    'fallback_date' => $publishedAt->format('Y-m-d H:i:s')
                ]);
            }
        }
        $article->setPublishedAt($publishedAt);
        
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
        
        // Remove dangerous JavaScript event handlers and protocols
        $content = preg_replace('/on\w+\s*=\s*["\'][^"\']*["\']/i', '', $content);
        $content = preg_replace('/on\w+\s*=\s*[^"\'\s>]+/i', '', $content);
        $content = preg_replace('/javascript\s*:/i', '', $content);
        $content = preg_replace('/vbscript\s*:/i', '', $content);
        $content = preg_replace('/data\s*:/i', '', $content);
        
        // Remove dangerous attributes across all allowed tags
        $content = preg_replace('/\s+(style|class|id)\s*=\s*["\'][^"\']*["\']/i', '', $content);
        $content = preg_replace('/\s+(formaction|action|method)\s*=\s*["\'][^"\']*["\']/i', '', $content);
        
        // Ensure href attributes are safe for links
        $content = preg_replace('/href\s*=\s*["\']javascript[^"\']*["\']/i', '', $content);
        $content = preg_replace('/href\s*=\s*["\']vbscript[^"\']*["\']/i', '', $content);
        $content = preg_replace('/href\s*=\s*["\']data[^"\']*["\']/i', '', $content);
        
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