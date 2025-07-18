<?php

namespace App\Service;

use App\Entity\Article;
use App\Entity\Feed;
use App\Entity\User;
use App\Message\ProcessArticleAiMessage;
use App\Service\AiSummaryService;
use Laminas\Feed\Reader\Reader;
use Laminas\Feed\Reader\Entry\EntryInterface;
use Laminas\Feed\Reader\FeedInterface;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class FeedParserService
{
    private HttpClientInterface $httpClient;
    private MessageBusInterface $messageBus;
    private AiSummaryService $aiSummaryService;

    public function __construct(
        HttpClientInterface $httpClient = null,
        MessageBusInterface $messageBus = null,
        AiSummaryService $aiSummaryService = null
    ) {
        $this->httpClient = $httpClient ?: HttpClient::create();
        $this->messageBus = $messageBus;
        $this->aiSummaryService = $aiSummaryService;
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

    public function extractArticles(ParsedFeed $parsedFeed, User $user = null): array
    {
        $articles = [];
        $feed = $parsedFeed->getFeed();

        foreach ($feed as $entry) {
            $articles[] = $this->createArticleFromEntry($entry, $user);
        }

        return $articles;
    }

    private function createArticleFromEntry(EntryInterface $entry, User $user = null): Article
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

        // Schedule AI processing if user has consent and services are available
        if ($user && $this->aiSummaryService && $this->messageBus) {
            if ($this->aiSummaryService->hasUserConsent($user)) {
                $article->setAiProcessingStatus('pending');
                // Note: The actual message dispatch will happen after the article is persisted
                // This is handled by the controller/service that calls this method
            }
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

    public function scheduleAiProcessing(Article $article, User $user = null): void
    {
        if (!$this->messageBus || !$article->getId()) {
            return;
        }

        if ($article->getAiProcessingStatus() === 'pending') {
            $message = new ProcessArticleAiMessage($article->getId(), $user?->getId());
            $this->messageBus->dispatch($message);
        }
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