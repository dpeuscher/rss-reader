<?php

namespace App\Service;

use App\Entity\Article;
use App\Entity\ArticleAuthor;
use App\Entity\ArticleCategory;
use App\Entity\ArticleEnclosure;
use App\Entity\Feed;
use App\Exception\FeedParsingException;
use Laminas\Feed\Reader\Reader;
use Laminas\Feed\Reader\Entry\EntryInterface;
use Laminas\Feed\Reader\FeedInterface;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Component\HttpClient\Exception\TimeoutException;
use Symfony\Component\HttpClient\Exception\ClientException;

class FeedParserService
{
    private HttpClientInterface $httpClient;
    private FeedFormatDetector $formatDetector;
    private JsonFeedParser $jsonFeedParser;

    public function __construct(
        HttpClientInterface $httpClient = null,
        FeedFormatDetector $formatDetector = null,
        JsonFeedParser $jsonFeedParser = null
    ) {
        $this->httpClient = $httpClient ?: HttpClient::create();
        $this->formatDetector = $formatDetector ?: new FeedFormatDetector();
        $this->jsonFeedParser = $jsonFeedParser ?: new JsonFeedParser();
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

            $statusCode = $response->getStatusCode();
            if ($statusCode !== 200) {
                $exception = FeedParsingException::networkError($url, $statusCode);
                return new FeedValidationResult(false, $exception->getDetailedMessage());
            }

            $content = $response->getContent();
            $format = $this->formatDetector->detectFormat($content);
            
            if (!$this->formatDetector->isFormatSupported($format)) {
                $exception = FeedParsingException::unsupportedFormat($format);
                return new FeedValidationResult(false, $exception->getDetailedMessage(), null, $format);
            }

            // Validate based on format
            if ($format === FeedFormatDetector::FORMAT_JSON_FEED) {
                try {
                    $this->jsonFeedParser->parseJsonFeed($content);
                    return new FeedValidationResult(true, 'JSON Feed is valid', null, $format);
                } catch (\InvalidArgumentException $e) {
                    $exception = FeedParsingException::invalidJsonFeed($e->getMessage(), $e);
                    return new FeedValidationResult(false, $exception->getDetailedMessage(), null, $format);
                }
            } else {
                // RSS/Atom validation using Laminas
                try {
                    $feed = Reader::importString($content);
                    return new FeedValidationResult(true, 'Feed is valid', $feed, $format);
                } catch (\Exception $e) {
                    $exception = match ($format) {
                        FeedFormatDetector::FORMAT_RSS_2_0 => FeedParsingException::invalidRssFeed($e->getMessage(), $e),
                        FeedFormatDetector::FORMAT_ATOM_1_0 => FeedParsingException::invalidAtomFeed($e->getMessage(), $e),
                        default => FeedParsingException::invalidRssFeed($e->getMessage(), $e)
                    };
                    return new FeedValidationResult(false, $exception->getDetailedMessage(), null, $format);
                }
            }
        } catch (TimeoutException $e) {
            $exception = FeedParsingException::timeoutError($url);
            return new FeedValidationResult(false, $exception->getDetailedMessage());
        } catch (ClientException $e) {
            $statusCode = $e->getResponse() ? $e->getResponse()->getStatusCode() : 0;
            $exception = FeedParsingException::networkError($url, $statusCode);
            return new FeedValidationResult(false, $exception->getDetailedMessage());
        } catch (\Exception $e) {
            return new FeedValidationResult(false, 'Unexpected error: ' . $e->getMessage());
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
        $format = $this->formatDetector->detectFormat($content);

        if ($format === FeedFormatDetector::FORMAT_JSON_FEED) {
            $jsonData = $this->jsonFeedParser->parseJsonFeed($content);
            return new ParsedFeed(null, $jsonData, $format);
        } else {
            $feed = Reader::importString($content);
            return new ParsedFeed($feed, null, $format);
        }
    }

    public function extractArticles(ParsedFeed $parsedFeed): array
    {
        if ($parsedFeed->getFormat() === FeedFormatDetector::FORMAT_JSON_FEED) {
            $jsonData = $parsedFeed->getJsonData();
            return $jsonData['items'] ?? [];
        } else {
            $articles = [];
            $feed = $parsedFeed->getFeed();

            foreach ($feed as $entry) {
                $articles[] = $this->createArticleFromEntry($entry);
            }

            return $articles;
        }
    }

    private function createArticleFromEntry(EntryInterface $entry): Article
    {
        $article = new Article();
        
        $article->setGuid($entry->getId() ?: $entry->getLink());
        $article->setTitle($entry->getTitle());
        $article->setUrl($entry->getLink());
        $article->setPublishedAt($entry->getDateCreated() ?: new \DateTime());
        
        $content = $entry->getContent() ?: $entry->getDescription();
        $article->setContent($content ? $this->normalizeContent($content) : '');
        $article->setContentType('html');
        
        $description = $entry->getDescription();
        if ($description) {
            $article->setSummary($this->normalizeContent($description));
        }

        // Set updated date if available
        if ($entry->getDateModified()) {
            $article->setUpdatedAt($entry->getDateModified());
        }

        // Extract authors
        $this->extractAuthorsFromEntry($article, $entry);
        
        // Extract categories
        $this->extractCategoriesFromEntry($article, $entry);
        
        // Extract enclosures
        $this->extractEnclosuresFromEntry($article, $entry);

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

    private function extractAuthorsFromEntry(Article $article, EntryInterface $entry): void
    {
        $authors = [];
        
        // Try to get authors using various methods
        try {
            if (method_exists($entry, 'getAuthors')) {
                $authors = $entry->getAuthors();
            } elseif (method_exists($entry, 'getAuthor')) {
                $authorData = $entry->getAuthor();
                if ($authorData) {
                    $authors = [$authorData];
                }
            }
        } catch (\Exception $e) {
            // If authors extraction fails, continue without authors
        }

        // If no authors found through Laminas methods, try extracting from DOM
        if (empty($authors)) {
            $this->extractAuthorsFromDom($article, $entry);
            return;
        }

        if (!empty($authors)) {
            foreach ($authors as $authorData) {
                if (is_array($authorData)) {
                    $name = $authorData['name'] ?? $authorData['title'] ?? 'Unknown Author';
                    $email = $authorData['email'] ?? null;
                    $url = $authorData['uri'] ?? $authorData['url'] ?? null;
                } else {
                    $name = (string) $authorData;
                    $email = null;
                    $url = null;
                }

                if (trim($name)) {
                    $author = new ArticleAuthor();
                    $author->setArticle($article);
                    $author->setName(trim($name));
                    if ($email) $author->setEmail(trim($email));
                    if ($url) $author->setUrl(trim($url));
                    
                    $article->addAuthor($author);
                }
            }
        }
    }

    private function extractAuthorsFromDom(Article $article, EntryInterface $entry): void
    {
        try {
            // Get the DOM element for direct XML parsing
            $domElement = $entry->getElement();
            
            // Look for author elements in various namespaces
            $authorNodes = [];
            
            // RSS 2.0 author
            $authorNodes = array_merge($authorNodes, iterator_to_array($domElement->getElementsByTagName('author')));
            
            // Dublin Core creator
            $authorNodes = array_merge($authorNodes, iterator_to_array($domElement->getElementsByTagNameNS('http://purl.org/dc/elements/1.1/', 'creator')));
            
            // Atom author
            $authorNodes = array_merge($authorNodes, iterator_to_array($domElement->getElementsByTagNameNS('http://www.w3.org/2005/Atom', 'author')));

            foreach ($authorNodes as $authorNode) {
                $name = trim($authorNode->textContent);
                if ($name) {
                    $author = new ArticleAuthor();
                    $author->setArticle($article);
                    $author->setName($name);
                    
                    $article->addAuthor($author);
                }
            }
        } catch (\Exception $e) {
            // Silently fail if DOM extraction doesn't work
        }
    }

    private function extractCategoriesFromEntry(Article $article, EntryInterface $entry): void
    {
        try {
            $categories = [];
            
            if (method_exists($entry, 'getCategories')) {
                $categories = $entry->getCategories();
            }

            foreach ($categories as $categoryData) {
                $name = '';
                $scheme = null;
                
                if (is_array($categoryData)) {
                    $name = $categoryData['term'] ?? $categoryData['label'] ?? $categoryData['name'] ?? '';
                    $scheme = $categoryData['scheme'] ?? null;
                } else {
                    $name = (string) $categoryData;
                }

                if (trim($name)) {
                    $category = new ArticleCategory();
                    $category->setArticle($article);
                    $category->setName(trim($name));
                    if ($scheme) $category->setScheme(trim($scheme));
                    
                    $article->addCategory($category);
                }
            }
        } catch (\Exception $e) {
            // Try DOM-based extraction as fallback
            $this->extractCategoriesFromDom($article, $entry);
        }
    }

    private function extractCategoriesFromDom(Article $article, EntryInterface $entry): void
    {
        try {
            $domElement = $entry->getElement();
            
            // Look for category elements
            $categoryNodes = [];
            $categoryNodes = array_merge($categoryNodes, iterator_to_array($domElement->getElementsByTagName('category')));
            $categoryNodes = array_merge($categoryNodes, iterator_to_array($domElement->getElementsByTagNameNS('http://purl.org/dc/elements/1.1/', 'subject')));

            foreach ($categoryNodes as $categoryNode) {
                $name = $categoryNode->getAttribute('term') ?: $categoryNode->textContent;
                $scheme = $categoryNode->getAttribute('domain') ?: $categoryNode->getAttribute('scheme');
                
                if (trim($name)) {
                    $category = new ArticleCategory();
                    $category->setArticle($article);
                    $category->setName(trim($name));
                    if ($scheme) $category->setScheme(trim($scheme));
                    
                    $article->addCategory($category);
                }
            }
        } catch (\Exception $e) {
            // Silently fail if DOM extraction doesn't work
        }
    }

    private function extractEnclosuresFromEntry(Article $article, EntryInterface $entry): void
    {
        try {
            $enclosures = [];
            
            if (method_exists($entry, 'getEnclosure')) {
                $enclosure = $entry->getEnclosure();
                if ($enclosure) {
                    $enclosures = [$enclosure];
                }
            }

            foreach ($enclosures as $enclosureData) {
                $url = '';
                $type = null;
                $length = null;
                
                if (is_array($enclosureData)) {
                    $url = $enclosureData['url'] ?? '';
                    $type = $enclosureData['type'] ?? null;
                    $length = isset($enclosureData['length']) ? (int) $enclosureData['length'] : null;
                } elseif (is_object($enclosureData)) {
                    $url = method_exists($enclosureData, 'getUrl') ? $enclosureData->getUrl() : '';
                    $type = method_exists($enclosureData, 'getType') ? $enclosureData->getType() : null;
                    $length = method_exists($enclosureData, 'getLength') ? (int) $enclosureData->getLength() : null;
                }

                if (trim($url)) {
                    $enclosure = new ArticleEnclosure();
                    $enclosure->setArticle($article);
                    $enclosure->setUrl(trim($url));
                    if ($type) $enclosure->setType(trim($type));
                    if ($length && $length > 0) $enclosure->setLength($length);
                    
                    $article->addEnclosure($enclosure);
                }
            }
        } catch (\Exception $e) {
            // Try DOM-based extraction as fallback
            $this->extractEnclosuresFromDom($article, $entry);
        }
    }

    private function extractEnclosuresFromDom(Article $article, EntryInterface $entry): void
    {
        try {
            $domElement = $entry->getElement();
            
            // Look for enclosure elements (RSS 2.0)
            $enclosureNodes = iterator_to_array($domElement->getElementsByTagName('enclosure'));

            foreach ($enclosureNodes as $enclosureNode) {
                $url = $enclosureNode->getAttribute('url');
                $type = $enclosureNode->getAttribute('type');
                $length = $enclosureNode->getAttribute('length');
                
                if (trim($url)) {
                    $enclosure = new ArticleEnclosure();
                    $enclosure->setArticle($article);
                    $enclosure->setUrl(trim($url));
                    if ($type) $enclosure->setType(trim($type));
                    if ($length && is_numeric($length)) $enclosure->setLength((int) $length);
                    
                    $article->addEnclosure($enclosure);
                }
            }
        } catch (\Exception $e) {
            // Silently fail if DOM extraction doesn't work
        }
    }

    public function updateFeedFromParsed(Feed $feed, ParsedFeed $parsedFeed): Feed
    {
        $format = $parsedFeed->getFormat();
        $feed->setFeedFormat($format);
        $feed->setLastUpdated(new \DateTime());
        
        if ($format === FeedFormatDetector::FORMAT_JSON_FEED) {
            $jsonData = $parsedFeed->getJsonData();
            $feed->setTitle($jsonData['title'] ?? 'Untitled Feed');
            $feed->setDescription($jsonData['description'] ?? null);
            $feed->setSiteUrl($jsonData['home_page_url'] ?? null);
            $feed->setLanguage($jsonData['language'] ?? null);
        } else {
            $laminasFeed = $parsedFeed->getFeed();
            $feed->setTitle($laminasFeed->getTitle());
            $feed->setDescription($laminasFeed->getDescription());
            $feed->setSiteUrl($laminasFeed->getLink());
            
            // Try to extract language from RSS/Atom feed
            try {
                if (method_exists($laminasFeed, 'getLanguage')) {
                    $feed->setLanguage($laminasFeed->getLanguage());
                }
            } catch (\Exception $e) {
                // Language extraction failed, continue without it
            }
        }
        
        return $feed;
    }
}

class FeedValidationResult
{
    private bool $valid;
    private string $message;
    private ?FeedInterface $feed;
    private string $format;

    public function __construct(bool $valid, string $message, ?FeedInterface $feed = null, string $format = FeedFormatDetector::FORMAT_UNKNOWN)
    {
        $this->valid = $valid;
        $this->message = $message;
        $this->feed = $feed;
        $this->format = $format;
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

    public function getFormat(): string
    {
        return $this->format;
    }
}

class ParsedFeed
{
    private ?FeedInterface $feed;
    private ?array $jsonData;
    private string $format;

    public function __construct(?FeedInterface $feed = null, ?array $jsonData = null, string $format = FeedFormatDetector::FORMAT_UNKNOWN)
    {
        $this->feed = $feed;
        $this->jsonData = $jsonData;
        $this->format = $format;
    }

    public function getFeed(): ?FeedInterface
    {
        return $this->feed;
    }

    public function getJsonData(): ?array
    {
        return $this->jsonData;
    }

    public function getFormat(): string
    {
        return $this->format;
    }
}