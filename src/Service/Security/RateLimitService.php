<?php

namespace App\Service\Security;

class RateLimitService
{
    private const MAX_REQUESTS = 10;
    private const TIME_WINDOW = 300; // 5 minutes in seconds
    
    private array $requests = [];

    public function isRateLimited(string $identifier): bool
    {
        $now = time();
        $windowStart = $now - self::TIME_WINDOW;
        
        // Initialize if not exists
        if (!isset($this->requests[$identifier])) {
            $this->requests[$identifier] = [];
        }
        
        // Clean old requests outside the time window
        $this->requests[$identifier] = array_filter(
            $this->requests[$identifier],
            fn($timestamp) => $timestamp > $windowStart
        );
        
        // Check if limit is exceeded
        return count($this->requests[$identifier]) >= self::MAX_REQUESTS;
    }
    
    public function recordRequest(string $identifier): void
    {
        $now = time();
        
        if (!isset($this->requests[$identifier])) {
            $this->requests[$identifier] = [];
        }
        
        $this->requests[$identifier][] = $now;
    }
    
    public function getRemainingRequests(string $identifier): int
    {
        $now = time();
        $windowStart = $now - self::TIME_WINDOW;
        
        if (!isset($this->requests[$identifier])) {
            return self::MAX_REQUESTS;
        }
        
        // Clean old requests outside the time window
        $this->requests[$identifier] = array_filter(
            $this->requests[$identifier],
            fn($timestamp) => $timestamp > $windowStart
        );
        
        return max(0, self::MAX_REQUESTS - count($this->requests[$identifier]));
    }
    
    public function getTimeUntilReset(string $identifier): int
    {
        if (!isset($this->requests[$identifier]) || empty($this->requests[$identifier])) {
            return 0;
        }
        
        $oldestRequest = min($this->requests[$identifier]);
        $resetTime = $oldestRequest + self::TIME_WINDOW;
        
        return max(0, $resetTime - time());
    }
}