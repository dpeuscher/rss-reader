<?php

namespace App\Tests\Service\Security;

use App\Service\Security\RateLimitService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

class RateLimitServiceTest extends TestCase
{
    private RateLimitService $rateLimitService;
    private CacheInterface|MockObject $cache;

    protected function setUp(): void
    {
        $this->cache = $this->createMock(CacheInterface::class);
        $this->rateLimitService = new RateLimitService($this->cache);
    }

    public function testAllowsRequestsWithinLimit(): void
    {
        $identifier = 'test-user-1';
        
        // Mock cache to return array with 5 recent requests (within limit)
        $this->cache->expects($this->any())
            ->method('get')
            ->willReturn([time(), time(), time(), time(), time()]);
        
        $this->assertFalse($this->rateLimitService->isRateLimited($identifier));
    }

    public function testBlocksRequestsExceedingLimit(): void
    {
        $identifier = 'test-user-2';
        
        // Mock cache to return array with 10 requests (at limit)
        $currentTime = time();
        $requests = array_fill(0, 10, $currentTime);
        
        $this->cache->expects($this->any())
            ->method('get')
            ->willReturn($requests);
        
        $this->assertTrue($this->rateLimitService->isRateLimited($identifier));
    }

    public function testGetRemainingRequests(): void
    {
        $identifier = 'test-user-3';
        
        // Mock cache to return array with 3 requests
        $this->cache->expects($this->any())
            ->method('get')
            ->willReturn([time(), time(), time()]);
        
        $this->assertEquals(7, $this->rateLimitService->getRemainingRequests($identifier));
    }

    public function testTimeUntilReset(): void
    {
        $identifier = 'test-user-4';
        
        // Mock cache to return array with one request from 100 seconds ago
        $oldTimestamp = time() - 100;
        $this->cache->expects($this->any())
            ->method('get')
            ->willReturn([$oldTimestamp]);
        
        $timeUntilReset = $this->rateLimitService->getTimeUntilReset($identifier);
        $this->assertGreaterThanOrEqual(190, $timeUntilReset); // 300 - 100 = 200, allowing some variance
        $this->assertLessThanOrEqual(210, $timeUntilReset);
    }

    public function testDifferentIdentifiersAreIndependent(): void
    {
        $identifier1 = 'user-1';
        $identifier2 = 'user-2';
        
        // Mock cache to return different results for different identifiers
        $this->cache->expects($this->any())
            ->method('get')
            ->willReturnCallback(function($key) {
                if (str_contains($key, hash('sha256', 'user-1'))) {
                    return array_fill(0, 10, time()); // user-1 at limit
                } else {
                    return []; // user-2 has no requests
                }
            });
        
        $this->assertTrue($this->rateLimitService->isRateLimited($identifier1));
        $this->assertFalse($this->rateLimitService->isRateLimited($identifier2));
    }

    public function testConcurrentRateLimiting(): void
    {
        $identifier = 'concurrent-user';
        
        // Mock cache to simulate high concurrent load
        $this->cache->expects($this->any())
            ->method('get')
            ->willReturn(array_fill(0, 10, time()));
        
        $this->assertTrue($this->rateLimitService->isRateLimited($identifier));
    }

    public function testRecordRequest(): void
    {
        $identifier = 'test-user-record';
        
        // Mock cache methods for recording request
        $this->cache->expects($this->once())
            ->method('get')
            ->willReturn([]);
        
        $this->cache->expects($this->once())
            ->method('delete')
            ->willReturn(true);
        
        $this->cache->expects($this->once())
            ->method('get')
            ->willReturnCallback(function($key, $callback) {
                $item = $this->createMock(ItemInterface::class);
                $item->expects($this->once())
                    ->method('expiresAfter')
                    ->with(300);
                return $callback($item);
            });
        
        $this->rateLimitService->recordRequest($identifier);
    }
}