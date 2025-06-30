<?php

namespace App\Service;

use App\Entity\Article;
use App\Entity\Feed;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class FeedParser
{
    public function __construct(
        private HttpClientInterface $httpClient
    ) {}

    public function parseFeed(string $feedUrl): array
    {
        try {
            $response = $this->httpClient->request('GET', $feedUrl, [
                'timeout' => 30,
                'headers' => [
                    'User-Agent' => 'RSS Reader/1.0'
                ]
            ]);

            $content = $response->getContent();
            $xml = new \SimpleXMLElement($content);
            
            if (isset($xml->channel)) {
                return $this->parseRSS($xml);
            } elseif (isset($xml->entry)) {
                return $this->parseAtom($xml);
            } else {
                throw new \Exception('Unknown feed format');
            }
        } catch (\Exception $e) {
            throw new \Exception('Failed to parse feed: ' . $e->getMessage());
        }
    }

    private function parseRSS(\SimpleXMLElement $xml): array
    {
        $channel = $xml->channel;
        
        $feedData = [
            'title' => (string) $channel->title,
            'link' => (string) $channel->link,
            'description' => (string) $channel->description,
            'lastModified' => null,
            'articles' => []
        ];

        if (isset($channel->lastBuildDate)) {
            $feedData['lastModified'] = new \DateTimeImmutable((string) $channel->lastBuildDate);
        }

        foreach ($channel->item as $item) {
            $article = [
                'title' => (string) $item->title,
                'link' => (string) $item->link,
                'description' => (string) $item->description,
                'author' => (string) ($item->author ?? ''),
                'guid' => (string) ($item->guid ?? $item->link),
                'publishedAt' => null,
                'content' => null
            ];

            if (isset($item->pubDate)) {
                try {
                    $article['publishedAt'] = new \DateTimeImmutable((string) $item->pubDate);
                } catch (\Exception $e) {
                    $article['publishedAt'] = new \DateTimeImmutable();
                }
            }

            if (isset($item->children('http://purl.org/rss/1.0/modules/content/')->encoded)) {
                $article['content'] = (string) $item->children('http://purl.org/rss/1.0/modules/content/')->encoded;
            }

            $feedData['articles'][] = $article;
        }

        return $feedData;
    }

    private function parseAtom(\SimpleXMLElement $xml): array
    {
        $feedData = [
            'title' => (string) $xml->title,
            'link' => '',
            'description' => (string) $xml->subtitle,
            'lastModified' => null,
            'articles' => []
        ];

        foreach ($xml->link as $link) {
            if ((string) $link['rel'] === 'alternate') {
                $feedData['link'] = (string) $link['href'];
                break;
            }
        }

        if (isset($xml->updated)) {
            $feedData['lastModified'] = new \DateTimeImmutable((string) $xml->updated);
        }

        foreach ($xml->entry as $entry) {
            $article = [
                'title' => (string) $entry->title,
                'link' => '',
                'description' => (string) $entry->summary,
                'author' => (string) ($entry->author->name ?? ''),
                'guid' => (string) $entry->id,
                'publishedAt' => null,
                'content' => (string) ($entry->content ?? $entry->summary)
            ];

            foreach ($entry->link as $link) {
                if ((string) $link['rel'] === 'alternate') {
                    $article['link'] = (string) $link['href'];
                    break;
                }
            }

            if (isset($entry->published)) {
                $article['publishedAt'] = new \DateTimeImmutable((string) $entry->published);
            } elseif (isset($entry->updated)) {
                $article['publishedAt'] = new \DateTimeImmutable((string) $entry->updated);
            }

            $feedData['articles'][] = $article;
        }

        return $feedData;
    }

    public function updateFeedFromData(Feed $feed, array $feedData): void
    {
        if (!empty($feedData['title'])) {
            $feed->setTitle($feedData['title']);
        }
        
        if (!empty($feedData['link'])) {
            $feed->setLink($feedData['link']);
        }
        
        if (!empty($feedData['description'])) {
            $feed->setDescription($feedData['description']);
        }
        
        if ($feedData['lastModified']) {
            $feed->setLastModified($feedData['lastModified']);
        }
        
        $feed->setLastFetched(new \DateTimeImmutable());
    }

    public function createArticleFromData(array $articleData, Feed $feed): Article
    {
        $article = new Article();
        $article->setTitle($articleData['title'])
                ->setLink($articleData['link'])
                ->setDescription($articleData['description'])
                ->setContent($articleData['content'])
                ->setAuthor($articleData['author'])
                ->setGuid($articleData['guid'])
                ->setFeed($feed);

        if ($articleData['publishedAt']) {
            $article->setPublishedAt($articleData['publishedAt']);
        }

        return $article;
    }
}