<?php

namespace App\Tests\Entity;

use App\Entity\Feed;
use App\Entity\FeedHealthLog;
use PHPUnit\Framework\TestCase;

class FeedTest extends TestCase
{
    public function testFeedHealthStatusMethods(): void
    {
        $feed = new Feed();
        
        // Test healthy status
        $feed->setHealthStatus('healthy');
        $this->assertTrue($feed->isHealthy());
        $this->assertFalse($feed->hasWarning());
        $this->assertFalse($feed->isUnhealthy());
        
        // Test warning status
        $feed->setHealthStatus('warning');
        $this->assertFalse($feed->isHealthy());
        $this->assertTrue($feed->hasWarning());
        $this->assertFalse($feed->isUnhealthy());
        
        // Test unhealthy status
        $feed->setHealthStatus('unhealthy');
        $this->assertFalse($feed->isHealthy());
        $this->assertFalse($feed->hasWarning());
        $this->assertTrue($feed->isUnhealthy());
    }

    public function testFeedHealthDefaults(): void
    {
        $feed = new Feed();
        
        $this->assertEquals('healthy', $feed->getHealthStatus());
        $this->assertEquals(0, $feed->getConsecutiveFailures());
        $this->assertNull($feed->getLastHealthCheck());
        $this->assertInstanceOf(\DateTimeInterface::class, $feed->getLastUpdated());
    }

    public function testFeedHealthLogRelationship(): void
    {
        $feed = new Feed();
        $healthLog = new FeedHealthLog();
        $healthLog->setStatus(FeedHealthLog::STATUS_HEALTHY);
        
        $feed->addHealthLog($healthLog);
        
        $this->assertTrue($feed->getHealthLogs()->contains($healthLog));
        $this->assertEquals($feed, $healthLog->getFeed());
        
        $feed->removeHealthLog($healthLog);
        $this->assertFalse($feed->getHealthLogs()->contains($healthLog));
    }

    public function testConsecutiveFailuresManagement(): void
    {
        $feed = new Feed();
        
        $this->assertEquals(0, $feed->getConsecutiveFailures());
        
        $feed->setConsecutiveFailures(3);
        $this->assertEquals(3, $feed->getConsecutiveFailures());
        
        // Reset failures
        $feed->setConsecutiveFailures(0);
        $this->assertEquals(0, $feed->getConsecutiveFailures());
    }

    public function testLastHealthCheckTracking(): void
    {
        $feed = new Feed();
        $checkTime = new \DateTime('2025-01-01 12:00:00');
        
        $feed->setLastHealthCheck($checkTime);
        $this->assertEquals($checkTime, $feed->getLastHealthCheck());
        
        // Test setting to null
        $feed->setLastHealthCheck(null);
        $this->assertNull($feed->getLastHealthCheck());
    }
}