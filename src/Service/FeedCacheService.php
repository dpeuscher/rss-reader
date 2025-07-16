<?php

namespace App\Service;

use App\Entity\Feed;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;

class FeedCacheService
{
    private CacheItemPoolInterface $cache;
    private LoggerInterface $logger;

    public function __construct(CacheItemPoolInterface $feedCache, LoggerInterface $logger)
    {
        $this->cache = $feedCache;
        $this->logger = $logger;
    }

    public function getCachedFeed(string $url, int $cacheDuration): ?array
    {
        if (!$this->validateCacheDuration($cacheDuration)) {
            throw new \InvalidArgumentException('Cache duration must be between 60 seconds and 1 week (604800 seconds)');
        }
        
        try {
            $cacheKey = $this->generateCacheKey($url, $cacheDuration);
            $cacheItem = $this->cache->getItem($cacheKey);

            if ($cacheItem->isHit()) {
                $cachedData = $cacheItem->get();
                
                // Validate cached data structure consistency
                if (is_array($cachedData) && isset($cachedData['content'], $cachedData['cached_at'], $cachedData['url'])) {
                    $this->logger->debug('Cache hit for feed', ['url' => $url, 'cache_key' => $cacheKey]);
                    return $cachedData;
                } else {
                    $this->logger->warning('Invalid cached data structure, invalidating cache', [
                        'url' => $url,
                        'cache_key' => $cacheKey
                    ]);
                    $this->cache->deleteItem($cacheKey);
                    return null;
                }
            }

            $this->logger->debug('Cache miss for feed', ['url' => $url, 'cache_key' => $cacheKey]);
            return null;
        } catch (\Exception $e) {
            $this->logger->error('Error retrieving feed from cache', [
                'url' => $url,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    public function cacheFeed(string $url, int $cacheDuration, array $feedData): bool
    {
        if (!$this->validateCacheDuration($cacheDuration)) {
            throw new \InvalidArgumentException('Cache duration must be between 60 seconds and 1 week (604800 seconds)');
        }
        
        try {
            $cacheKey = $this->generateCacheKey($url, $cacheDuration);
            $cacheItem = $this->cache->getItem($cacheKey);
            
            $cacheItem->set($feedData);
            $cacheItem->expiresAfter($cacheDuration);
            
            $success = $this->cache->save($cacheItem);
            
            if ($success) {
                $this->logger->debug('Feed cached successfully', [
                    'url' => $url,
                    'cache_key' => $cacheKey,
                    'duration' => $cacheDuration
                ]);
            } else {
                $this->logger->warning('Failed to cache feed', [
                    'url' => $url,
                    'cache_key' => $cacheKey
                ]);
            }
            
            return $success;
        } catch (\Exception $e) {
            $this->logger->error('Error caching feed', [
                'url' => $url,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    public function invalidateFeed(string $url, int $cacheDuration): bool
    {
        if (!$this->validateCacheDuration($cacheDuration)) {
            throw new \InvalidArgumentException('Cache duration must be between 60 seconds and 1 week (604800 seconds)');
        }
        
        try {
            $cacheKey = $this->generateCacheKey($url, $cacheDuration);
            $success = $this->cache->deleteItem($cacheKey);
            
            if ($success) {
                $this->logger->debug('Feed cache invalidated', [
                    'url' => $url,
                    'cache_key' => $cacheKey
                ]);
            }
            
            return $success;
        } catch (\Exception $e) {
            $this->logger->error('Error invalidating feed cache', [
                'url' => $url,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    public function invalidateAllFeedCache(string $url): bool
    {
        try {
            // Clear all cache entries for a specific URL regardless of duration
            $urlHash = md5($url);
            
            // Since filesystem cache doesn't support wildcard deletion,
            // we'll use clear() to remove all cache entries
            // This is a more robust approach for filesystem cache adapter
            $success = $this->cache->clear();
            
            $this->logger->debug('All feed cache invalidated for URL', ['url' => $url]);
            return $success;
        } catch (\Exception $e) {
            $this->logger->error('Error invalidating all feed cache', [
                'url' => $url,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    private function generateCacheKey(string $url, int $cacheDuration): string
    {
        $urlHash = md5($url);
        return "feed_content_{$urlHash}_{$cacheDuration}";
    }
    
    private function validateCacheDuration(int $cacheDuration): bool
    {
        // Minimum 60 seconds, maximum 1 week (604800 seconds)
        return $cacheDuration >= 60 && $cacheDuration <= 604800;
    }
}