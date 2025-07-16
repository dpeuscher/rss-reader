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
        try {
            $cacheKey = $this->generateCacheKey($url, $cacheDuration);
            $cacheItem = $this->cache->getItem($cacheKey);

            if ($cacheItem->isHit()) {
                $this->logger->debug('Cache hit for feed', ['url' => $url, 'cache_key' => $cacheKey]);
                return $cacheItem->get();
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
            $success = $this->cache->deleteItems($this->cache->getItem("feed_content_{$urlHash}_*"));
            
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
}