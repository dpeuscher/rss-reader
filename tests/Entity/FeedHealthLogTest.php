<?php

namespace App\Tests\Entity;

use App\Entity\Feed;
use App\Entity\FeedHealthLog;
use PHPUnit\Framework\TestCase;

class FeedHealthLogTest extends TestCase
{
    public function testFeedHealthLogCreation(): void
    {
        $feed = new Feed();
        $feed->setUrl('https://example.com/feed.rss');
        $feed->setTitle('Test Feed');

        $healthLog = new FeedHealthLog();
        $healthLog->setFeed($feed);
        $healthLog->setStatus(FeedHealthLog::STATUS_HEALTHY);
        $healthLog->setResponseTime(250);
        $healthLog->setHttpStatusCode(200);
        $healthLog->setConsecutiveFailures(0);

        $this->assertEquals($feed, $healthLog->getFeed());
        $this->assertEquals(FeedHealthLog::STATUS_HEALTHY, $healthLog->getStatus());
        $this->assertEquals(250, $healthLog->getResponseTime());
        $this->assertEquals(200, $healthLog->getHttpStatusCode());
        $this->assertEquals(0, $healthLog->getConsecutiveFailures());
        $this->assertInstanceOf(\DateTimeInterface::class, $healthLog->getCheckedAt());
        $this->assertTrue($healthLog->isHealthy());
        $this->assertFalse($healthLog->isWarning());
        $this->assertFalse($healthLog->isUnhealthy());
    }

    public function testFeedHealthLogWithWarningStatus(): void
    {
        $healthLog = new FeedHealthLog();
        $healthLog->setStatus(FeedHealthLog::STATUS_WARNING);
        $healthLog->setResponseTime(6000);
        $healthLog->setHttpStatusCode(200);

        $this->assertEquals(FeedHealthLog::STATUS_WARNING, $healthLog->getStatus());
        $this->assertFalse($healthLog->isHealthy());
        $this->assertTrue($healthLog->isWarning());
        $this->assertFalse($healthLog->isUnhealthy());
    }

    public function testFeedHealthLogWithUnhealthyStatus(): void
    {
        $healthLog = new FeedHealthLog();
        $healthLog->setStatus(FeedHealthLog::STATUS_UNHEALTHY);
        $healthLog->setHttpStatusCode(500);
        $healthLog->setErrorMessage('Internal Server Error');
        $healthLog->setConsecutiveFailures(3);

        $this->assertEquals(FeedHealthLog::STATUS_UNHEALTHY, $healthLog->getStatus());
        $this->assertEquals(500, $healthLog->getHttpStatusCode());
        $this->assertEquals('Internal Server Error', $healthLog->getErrorMessage());
        $this->assertEquals(3, $healthLog->getConsecutiveFailures());
        $this->assertFalse($healthLog->isHealthy());
        $this->assertFalse($healthLog->isWarning());
        $this->assertTrue($healthLog->isUnhealthy());
    }

    public function testFeedHealthLogStatusConstants(): void
    {
        $this->assertEquals('healthy', FeedHealthLog::STATUS_HEALTHY);
        $this->assertEquals('warning', FeedHealthLog::STATUS_WARNING);
        $this->assertEquals('unhealthy', FeedHealthLog::STATUS_UNHEALTHY);
    }
}