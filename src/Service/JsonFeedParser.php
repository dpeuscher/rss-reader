<?php

namespace App\Service;

use App\Entity\Article;
use App\Entity\ArticleAuthor;
use App\Entity\ArticleCategory;
use App\Entity\ArticleEnclosure;

class JsonFeedParser
{
    public function parseJsonFeed(string $content): array
    {
        // Parse JSON with error handling
        $data = json_decode($content, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \InvalidArgumentException('Invalid JSON content: ' . json_last_error_msg());
        }

        // Validate JSON Feed structure
        $this->validateJsonFeedStructure($data);

        return [
            'title' => $data['title'] ?? '',
            'description' => $data['description'] ?? null,
            'home_page_url' => $data['home_page_url'] ?? null,
            'feed_url' => $data['feed_url'] ?? null,
            'language' => $data['language'] ?? null,
            'items' => $this->parseItems($data['items'] ?? [])
        ];
    }

    private function validateJsonFeedStructure(array $data): void
    {
        // Check for required fields according to JSON Feed 1.1 spec
        if (!isset($data['version']) || 
            !is_string($data['version']) || 
            strpos($data['version'], 'https://jsonfeed.org/version/') !== 0) {
            throw new \InvalidArgumentException('Invalid or missing JSON Feed version');
        }

        if (!isset($data['title']) || !is_string($data['title']) || trim($data['title']) === '') {
            throw new \InvalidArgumentException('JSON Feed must have a title');
        }

        if (!isset($data['items']) || !is_array($data['items'])) {
            throw new \InvalidArgumentException('JSON Feed must have an items array');
        }
    }

    private function parseItems(array $items): array
    {
        $articles = [];
        $maxItems = 1000; // configurable item limit to prevent memory issues

        foreach (array_slice($items, 0, $maxItems) as $item) {
            if (!is_array($item)) {
                continue;
            }

            try {
                $article = $this->parseJsonFeedItem($item);
                if ($article) {
                    $articles[] = $article;
                }
            } catch (\Exception $e) {
                // Log the error but continue processing other items
                error_log("Error parsing JSON Feed item: " . $e->getMessage());
                continue;
            }
        }

        return $articles;
    }

    private function parseJsonFeedItem(array $item): ?Article
    {
        // A JSON Feed item must have either id or at least one of content_text, content_html, or summary
        if (!isset($item['id']) && 
            !isset($item['content_text']) && 
            !isset($item['content_html']) && 
            !isset($item['summary'])) {
            return null;
        }

        $article = new Article();

        // Set basic fields
        $article->setGuid($item['id'] ?? $this->generateGuidFromItem($item));
        $article->setTitle($this->sanitizeString($item['title'] ?? 'Untitled'));
        $article->setUrl($this->sanitizeUrl($item['url'] ?? '#'));

        // Set content (prefer content_html over content_text)
        $content = '';
        if (isset($item['content_html'])) {
            $content = $this->sanitizeHtml($item['content_html']);
            $article->setContentType('html');
        } elseif (isset($item['content_text'])) {
            $content = $this->sanitizeString($item['content_text']);
            $article->setContentType('text');
        }
        $article->setContent($content);

        // Set summary
        if (isset($item['summary'])) {
            $article->setSummary($this->sanitizeString($item['summary']));
        }

        // Set dates
        if (isset($item['date_published'])) {
            $publishedAt = $this->parseJsonFeedDate($item['date_published']);
            $article->setPublishedAt($publishedAt ?: new \DateTime());
        } else {
            $article->setPublishedAt(new \DateTime());
        }

        if (isset($item['date_modified'])) {
            $updatedAt = $this->parseJsonFeedDate($item['date_modified']);
            $article->setUpdatedAt($updatedAt);
        }

        // Handle authors
        if (isset($item['authors']) && is_array($item['authors'])) {
            $this->parseAuthors($article, $item['authors']);
        } elseif (isset($item['author'])) {
            // Handle single author (legacy format)
            $this->parseAuthors($article, [$item['author']]);
        }

        // Handle tags (categories)
        if (isset($item['tags']) && is_array($item['tags'])) {
            $this->parseCategories($article, $item['tags']);
        }

        // Handle attachments (enclosures)
        if (isset($item['attachments']) && is_array($item['attachments'])) {
            $this->parseEnclosures($article, $item['attachments']);
        }

        return $article;
    }

    private function generateGuidFromItem(array $item): string
    {
        // Generate a GUID from available data
        $parts = [];
        if (isset($item['url'])) $parts[] = $item['url'];
        if (isset($item['title'])) $parts[] = $item['title'];
        if (isset($item['date_published'])) $parts[] = $item['date_published'];
        
        return md5(implode('|', $parts) ?: uniqid());
    }

    private function parseJsonFeedDate(string $dateString): ?\DateTime
    {
        try {
            // JSON Feed uses RFC 3339 format
            return new \DateTime($dateString);
        } catch (\Exception $e) {
            error_log("Error parsing JSON Feed date '{$dateString}': " . $e->getMessage());
            return null;
        }
    }

    private function parseAuthors(Article $article, array $authors): void
    {
        foreach ($authors as $authorData) {
            if (!is_array($authorData)) {
                continue;
            }

            $author = new ArticleAuthor();
            $author->setArticle($article);
            $author->setName($this->sanitizeString($authorData['name'] ?? 'Unknown Author'));
            
            if (isset($authorData['url'])) {
                $author->setUrl($this->sanitizeUrl($authorData['url']));
            }
            
            // JSON Feed doesn't typically have email, but handle it if present
            if (isset($authorData['email'])) {
                $author->setEmail($this->sanitizeEmail($authorData['email']));
            }

            $article->addAuthor($author);
        }
    }

    private function parseCategories(Article $article, array $tags): void
    {
        foreach ($tags as $tag) {
            if (!is_string($tag) || trim($tag) === '') {
                continue;
            }

            $category = new ArticleCategory();
            $category->setArticle($article);
            $category->setName($this->sanitizeString($tag));
            
            $article->addCategory($category);
        }
    }

    private function parseEnclosures(Article $article, array $attachments): void
    {
        foreach ($attachments as $attachment) {
            if (!is_array($attachment) || !isset($attachment['url'])) {
                continue;
            }

            $enclosure = new ArticleEnclosure();
            $enclosure->setArticle($article);
            $enclosure->setUrl($this->sanitizeUrl($attachment['url']));
            
            if (isset($attachment['mime_type'])) {
                $enclosure->setType($this->sanitizeString($attachment['mime_type']));
            }
            
            if (isset($attachment['size_in_bytes']) && is_numeric($attachment['size_in_bytes'])) {
                $enclosure->setLength((int) $attachment['size_in_bytes']);
            }

            $article->addEnclosure($enclosure);
        }
    }

    private function sanitizeString(string $input): string
    {
        // Remove null bytes and other dangerous characters
        $cleaned = str_replace("\0", '', $input);
        return trim($cleaned);
    }

    private function sanitizeHtml(string $html): string
    {
        // Enhanced HTML sanitization - for production, use HTMLPurifier library
        $allowed_tags = '<p><br><strong><em><ul><ol><li><h1><h2><h3><h4><h5><h6><a><img><blockquote><code><pre>';
        $cleaned = strip_tags($html, $allowed_tags);
        
        // Remove dangerous attributes and protocols
        $cleaned = preg_replace('/on\w+="[^"]*"/i', '', $cleaned);
        $cleaned = preg_replace('/on\w+=\'[^\']*\'/i', '', $cleaned);
        $cleaned = preg_replace('/(javascript|vbscript|data|blob):/i', '', $cleaned);
        $cleaned = preg_replace('/style="[^"]*"/i', '', $cleaned);
        $cleaned = preg_replace('/style=\'[^\']*\'/i', '', $cleaned);
        
        return trim($cleaned);
    }

    private function sanitizeUrl(string $url): string
    {
        $cleaned = $this->sanitizeString($url);
        
        // Basic URL validation
        if (!filter_var($cleaned, FILTER_VALIDATE_URL) && $cleaned !== '#') {
            return '#';
        }
        
        return $cleaned;
    }

    private function sanitizeEmail(?string $email): ?string
    {
        if ($email === null) {
            return null;
        }
        
        $cleaned = $this->sanitizeString($email);
        
        if (!filter_var($cleaned, FILTER_VALIDATE_EMAIL)) {
            return null;
        }
        
        return $cleaned;
    }
}