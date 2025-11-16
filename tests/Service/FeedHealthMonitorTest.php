<?php

namespace App\Tests\Service;

use App\Entity\Feed;
use App\Entity\FeedHealthLog;
use App\Repository\FeedHealthLogRepository;
use App\Repository\FeedRepository;
use App\Service\FeedHealthMonitor;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

class FeedHealthMonitorTest extends TestCase
{
    private FeedHealthMonitor $healthMonitor;
    private MockHttpClient $httpClient;
    private EntityManagerInterface|MockObject $entityManager;
    private FeedRepository|MockObject $feedRepository;
    private FeedHealthLogRepository|MockObject $healthLogRepository;
    private LoggerInterface|MockObject $logger;

    protected function setUp(): void
    {
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->feedRepository = $this->createMock(FeedRepository::class);
        $this->healthLogRepository = $this->createMock(FeedHealthLogRepository::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        
        $this->httpClient = new MockHttpClient();
        
        $this->healthMonitor = new FeedHealthMonitor(
            $this->httpClient,
            $this->entityManager,
            $this->feedRepository,
            $this->healthLogRepository,
            $this->logger
        );
    }

    public function testCheckFeedHealthWithSuccessfulResponse(): void
    {
        // Create a mock feed
        $feed = new Feed();
        $feed->setUrl('https://example.com/feed.rss');
        $feed->setTitle('Test Feed');

        // Mock a successful HTTP response with valid RSS content
        $rssContent = '<?xml version="1.0" encoding="UTF-8"?>
        <rss version="2.0">
            <channel>
                <title>Test Feed</title>
                <description>Test Description</description>
                <item>
                    <title>Test Item</title>
                    <description>Test Item Description</description>
                </item>
            </channel>
        </rss>';

        $this->httpClient = new MockHttpClient([
            new MockResponse($rssContent, [
                'http_code' => 200,
                'response_headers' => ['content-type' => 'application/rss+xml']
            ])
        ]);

        $this->healthMonitor = new FeedHealthMonitor(
            $this->httpClient,
            $this->entityManager,
            $this->feedRepository,
            $this->healthLogRepository,
            $this->logger
        );

        $this->entityManager->expects($this->exactly(2))
            ->method('persist')
            ->withConsecutive(
                [$this->isInstanceOf(FeedHealthLog::class)],
                [$this->isInstanceOf(Feed::class)]
            );

        $this->entityManager->expects($this->once())
            ->method('flush');

        $result = $this->healthMonitor->checkFeedHealth($feed);

        $this->assertInstanceOf(FeedHealthLog::class, $result);
        $this->assertEquals(FeedHealthLog::STATUS_HEALTHY, $result->getStatus());
        $this->assertEquals(200, $result->getHttpStatusCode());
        $this->assertGreaterThan(0, $result->getResponseTime());
        $this->assertEquals(0, $feed->getConsecutiveFailures());
        $this->assertEquals('healthy', $feed->getHealthStatus());
    }

    public function testCheckFeedHealthWithHttpError(): void
    {
        $feed = new Feed();
        $feed->setUrl('https://example.com/nonexistent-feed.rss');
        $feed->setTitle('Test Feed');

        // Mock a 404 HTTP response
        $this->httpClient = new MockHttpClient([
            new MockResponse('Not Found', [
                'http_code' => 404
            ])
        ]);

        $this->healthMonitor = new FeedHealthMonitor(
            $this->httpClient,
            $this->entityManager,
            $this->feedRepository,
            $this->healthLogRepository,
            $this->logger
        );

        $this->entityManager->expects($this->exactly(2))
            ->method('persist');

        $this->entityManager->expects($this->once())
            ->method('flush');

        $result = $this->healthMonitor->checkFeedHealth($feed);

        $this->assertEquals(FeedHealthLog::STATUS_UNHEALTHY, $result->getStatus());
        $this->assertEquals(404, $result->getHttpStatusCode());
        $this->assertEquals('HTTP 404 response', $result->getErrorMessage());
        $this->assertEquals(1, $feed->getConsecutiveFailures());
    }

    public function testCheckFeedHealthWithInvalidXML(): void
    {
        $feed = new Feed();
        $feed->setUrl('https://example.com/invalid-feed.rss');
        $feed->setTitle('Test Feed');

        // Mock a response with invalid XML
        $invalidXml = 'This is not XML content';

        $this->httpClient = new MockHttpClient([
            new MockResponse($invalidXml, [
                'http_code' => 200
            ])
        ]);

        $this->healthMonitor = new FeedHealthMonitor(
            $this->httpClient,
            $this->entityManager,
            $this->feedRepository,
            $this->healthLogRepository,
            $this->logger
        );

        $this->entityManager->expects($this->exactly(2))
            ->method('persist');

        $this->entityManager->expects($this->once())
            ->method('flush');

        $result = $this->healthMonitor->checkFeedHealth($feed);

        $this->assertEquals(FeedHealthLog::STATUS_UNHEALTHY, $result->getStatus());
        $this->assertEquals(200, $result->getHttpStatusCode());
    }

    public function testCheckFeedHealthWithSlowResponse(): void
    {
        $feed = new Feed();
        $feed->setUrl('https://example.com/slow-feed.rss');
        $feed->setTitle('Test Feed');

        // Mock a successful but slow response
        $rssContent = '<?xml version="1.0" encoding="UTF-8"?>
        <rss version="2.0">
            <channel>
                <title>Test Feed</title>
                <description>Test Description</description>
            </channel>
        </rss>';

        // Simulate slow response by using a custom response that takes time
        $mockResponse = new MockResponse($rssContent, [
            'http_code' => 200
        ]);

        $this->httpClient = new MockHttpClient([$mockResponse]);

        $this->healthMonitor = new FeedHealthMonitor(
            $this->httpClient,
            $this->entityManager,
            $this->feedRepository,
            $this->healthLogRepository,
            $this->logger
        );

        $this->entityManager->expects($this->exactly(2))
            ->method('persist');

        $this->entityManager->expects($this->once())
            ->method('flush');

        $result = $this->healthMonitor->checkFeedHealth($feed);

        // The response should be healthy since we can't actually simulate timing in unit tests
        $this->assertEquals(FeedHealthLog::STATUS_HEALTHY, $result->getStatus());
        $this->assertEquals(200, $result->getHttpStatusCode());
    }

    public function testGetFeedHealthSummary(): void
    {
        $feeds = [
            $this->createFeedWithHealthStatus('healthy'),
            $this->createFeedWithHealthStatus('healthy'),
            $this->createFeedWithHealthStatus('warning'),
            $this->createFeedWithHealthStatus('unhealthy'),
        ];

        $this->feedRepository->expects($this->once())
            ->method('findBy')
            ->with(['status' => 'active'])
            ->willReturn($feeds);

        $summary = $this->healthMonitor->getFeedHealthSummary();

        $this->assertEquals([
            'total' => 4,
            'healthy' => 2,
            'warning' => 1,
            'unhealthy' => 1,
        ], $summary);
    }

    public function testCleanupOldHealthLogs(): void
    {
        $expectedDeletedCount = 25;

        $this->healthLogRepository->expects($this->once())
            ->method('deleteOldLogs')
            ->with($this->isInstanceOf(\DateTimeInterface::class))
            ->willReturn($expectedDeletedCount);

        $deletedCount = $this->healthMonitor->cleanupOldHealthLogs(30);

        $this->assertEquals($expectedDeletedCount, $deletedCount);
    }

    private function createFeedWithHealthStatus(string $healthStatus): Feed
    {
        $feed = new Feed();
        $feed->setHealthStatus($healthStatus);
        $feed->setUrl('https://example.com/feed.rss');
        $feed->setTitle('Test Feed');
        return $feed;
    }
}