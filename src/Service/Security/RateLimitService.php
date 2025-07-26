<?php

namespace App\Service\Security;

use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

class RateLimitService
{
    private const MAX_REQUESTS = 10;
    private const TIME_WINDOW = 300; // 5 minutes in seconds
    private const CACHE_PREFIX = 'rate_limit_';
    
    public function __construct(
        private readonly CacheInterface $cache
    ) {}

    public function isRateLimited(string $identifier): bool
    {
        $cacheKey = $this->getCacheKey($identifier);
        $requests = $this->getRequestsFromCache($cacheKey);
        
        // Check if limit is exceeded
        return count($requests) >= self::MAX_REQUESTS;
    }
    
    public function recordRequest(string $identifier): void
    {
        $now = time();
        $cacheKey = $this->getCacheKey($identifier);
        
        // Get current requests and add new one
        $requests = $this->getRequestsFromCache($cacheKey);
        $requests[] = $now;
        
        // Store updated requests in cache
        $this->cache->delete($cacheKey);
        $this->cache->get($cacheKey, function (ItemInterface $item) use ($requests) {
            $item->expiresAfter(self::TIME_WINDOW);
            return $requests;
        });
    }
    
    public function getRemainingRequests(string $identifier): int
    {
        $cacheKey = $this->getCacheKey($identifier);
        $requests = $this->getRequestsFromCache($cacheKey);
        
        return max(0, self::MAX_REQUESTS - count($requests));
    }
    
    public function getTimeUntilReset(string $identifier): int
    {
        $cacheKey = $this->getCacheKey($identifier);
        $requests = $this->getRequestsFromCache($cacheKey);
        
        if (empty($requests)) {
            return 0;
        }
        
        $oldestRequest = min($requests);
        $resetTime = $oldestRequest + self::TIME_WINDOW;
        
        return max(0, $resetTime - time());
    }
    
    private function getCacheKey(string $identifier): string
    {
        return self::CACHE_PREFIX . hash('sha256', $identifier);
    }
    
    private function getRequestsFromCache(string $cacheKey): array
    {
        $requests = $this->cache->get($cacheKey, function (ItemInterface $item) {
            $item->expiresAfter(self::TIME_WINDOW);
            return [];
        });
        
        // Clean old requests outside the time window
        $now = time();
        $windowStart = $now - self::TIME_WINDOW;
        
        return array_filter(
            $requests,
            fn($timestamp) => $timestamp > $windowStart
        );
    }
}