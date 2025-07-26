<?php

namespace App\Tests\Service\Security;

use App\Service\Security\RateLimitService;
use PHPUnit\Framework\TestCase;

class RateLimitServiceTest extends TestCase
{
    private RateLimitService $rateLimitService;

    protected function setUp(): void
    {
        $this->rateLimitService = new RateLimitService();
    }

    public function testAllowsRequestsWithinLimit(): void
    {
        $identifier = 'test-user-1';
        
        // Should allow first 10 requests
        for ($i = 0; $i < 10; $i++) {
            $this->assertFalse($this->rateLimitService->isRateLimited($identifier));
            $this->rateLimitService->recordRequest($identifier);
        }
    }

    public function testBlocksRequestsExceedingLimit(): void
    {
        $identifier = 'test-user-2';
        
        // Make 10 requests (maximum allowed)
        for ($i = 0; $i < 10; $i++) {
            $this->rateLimitService->recordRequest($identifier);
        }
        
        // 11th request should be rate limited
        $this->assertTrue($this->rateLimitService->isRateLimited($identifier));
    }

    public function testGetRemainingRequests(): void
    {
        $identifier = 'test-user-3';
        
        $this->assertEquals(10, $this->rateLimitService->getRemainingRequests($identifier));
        
        $this->rateLimitService->recordRequest($identifier);
        $this->assertEquals(9, $this->rateLimitService->getRemainingRequests($identifier));
        
        $this->rateLimitService->recordRequest($identifier);
        $this->assertEquals(8, $this->rateLimitService->getRemainingRequests($identifier));
    }

    public function testTimeUntilReset(): void
    {
        $identifier = 'test-user-4';
        
        // No requests made yet
        $this->assertEquals(0, $this->rateLimitService->getTimeUntilReset($identifier));
        
        // Record a request
        $this->rateLimitService->recordRequest($identifier);
        
        // Should have time until reset (up to 300 seconds)
        $timeUntilReset = $this->rateLimitService->getTimeUntilReset($identifier);
        $this->assertGreaterThan(0, $timeUntilReset);
        $this->assertLessThanOrEqual(300, $timeUntilReset);
    }

    public function testDifferentIdentifiersAreIndependent(): void
    {
        $identifier1 = 'user-1';
        $identifier2 = 'user-2';
        
        // Make 10 requests for user 1
        for ($i = 0; $i < 10; $i++) {
            $this->rateLimitService->recordRequest($identifier1);
        }
        
        // User 1 should be rate limited
        $this->assertTrue($this->rateLimitService->isRateLimited($identifier1));
        
        // User 2 should not be rate limited
        $this->assertFalse($this->rateLimitService->isRateLimited($identifier2));
        $this->assertEquals(10, $this->rateLimitService->getRemainingRequests($identifier2));
    }
}