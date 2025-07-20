<?php

namespace App\Service;

use App\Entity\Feed;
use App\Entity\FeedHealthLog;
use App\Repository\FeedHealthLogRepository;
use App\Repository\FeedRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class FeedHealthMonitor
{
    private const MAX_RESPONSE_TIME_WARNING = 5000; // 5 seconds
    private const MAX_RESPONSE_TIME_UNHEALTHY = 10000; // 10 seconds
    private const MAX_CONSECUTIVE_FAILURES = 3;
    private const MIN_CONTENT_LENGTH = 100;

    public function __construct(
        private HttpClientInterface $httpClient,
        private EntityManagerInterface $entityManager,
        private FeedRepository $feedRepository,
        private FeedHealthLogRepository $healthLogRepository,
        private LoggerInterface $logger
    ) {
    }

    public function checkAllFeeds(): array
    {
        $results = [];
        $feeds = $this->feedRepository->findBy(['status' => 'active']);

        foreach ($feeds as $feed) {
            try {
                $result = $this->checkFeedHealth($feed);
                $results[] = $result;
            } catch (\Exception $e) {
                $this->logger->error('Error checking feed health', [
                    'feed_id' => $feed->getId(),
                    'error' => $e->getMessage()
                ]);
            }
        }

        return $results;
    }

    public function checkFeedHealth(Feed $feed): FeedHealthLog
    {
        $startTime = microtime(true);
        $healthLog = new FeedHealthLog();
        $healthLog->setFeed($feed);

        try {
            $response = $this->httpClient->request('GET', $feed->getUrl(), [
                'timeout' => 15,
                'headers' => [
                    'User-Agent' => 'RSS Reader Health Monitor/1.0',
                ],
                'max_redirects' => 5,
            ]);

            $responseTime = (int) ((microtime(true) - $startTime) * 1000);
            $statusCode = $response->getStatusCode();
            
            $healthLog->setHttpStatusCode($statusCode);
            $healthLog->setResponseTime($responseTime);

            if ($statusCode >= 200 && $statusCode < 300) {
                $content = $response->getContent(false);
                $healthStatus = $this->evaluateHealthStatus($responseTime, $content);
                $healthLog->setStatus($healthStatus);
                
                if ($healthStatus === FeedHealthLog::STATUS_HEALTHY) {
                    $feed->setConsecutiveFailures(0);
                }
            } else {
                $healthLog->setStatus(FeedHealthLog::STATUS_UNHEALTHY);
                $healthLog->setErrorMessage("HTTP {$statusCode} response");
                $this->incrementFailureCount($feed);
            }

        } catch (\Exception $e) {
            $responseTime = (int) ((microtime(true) - $startTime) * 1000);
            $healthLog->setResponseTime($responseTime);
            $healthLog->setStatus(FeedHealthLog::STATUS_UNHEALTHY);
            $healthLog->setErrorMessage($e->getMessage());
            $this->incrementFailureCount($feed);
        }

        $healthLog->setConsecutiveFailures($feed->getConsecutiveFailures());
        $this->updateFeedHealthStatus($feed, $healthLog);
        
        $this->entityManager->persist($healthLog);
        $this->entityManager->persist($feed);
        $this->entityManager->flush();

        return $healthLog;
    }

    private function evaluateHealthStatus(int $responseTime, string $content): string
    {
        if (empty($content) || strlen($content) < self::MIN_CONTENT_LENGTH) {
            return FeedHealthLog::STATUS_UNHEALTHY;
        }

        if (!$this->isValidXMLContent($content)) {
            return FeedHealthLog::STATUS_UNHEALTHY;
        }

        if ($responseTime > self::MAX_RESPONSE_TIME_UNHEALTHY) {
            return FeedHealthLog::STATUS_UNHEALTHY;
        }

        if ($responseTime > self::MAX_RESPONSE_TIME_WARNING) {
            return FeedHealthLog::STATUS_WARNING;
        }

        return FeedHealthLog::STATUS_HEALTHY;
    }

    private function isValidXMLContent(string $content): bool
    {
        libxml_use_internal_errors(true);
        
        // Use secure XML parsing flags to prevent XXE attacks
        $oldValue = libxml_disable_entity_loader(true);
        $doc = simplexml_load_string(
            $content,
            'SimpleXMLElement',
            LIBXML_NOCDATA | LIBXML_NONET | LIBXML_NOENT | LIBXML_DTDLOAD | LIBXML_DTDATTR
        );
        libxml_disable_entity_loader($oldValue);
        
        $errors = libxml_get_errors();
        libxml_clear_errors();
        
        return $doc !== false && empty($errors);
    }

    private function incrementFailureCount(Feed $feed): void
    {
        $feed->setConsecutiveFailures($feed->getConsecutiveFailures() + 1);
    }

    private function updateFeedHealthStatus(Feed $feed, FeedHealthLog $healthLog): void
    {
        $feed->setLastHealthCheck(new \DateTime());

        if ($healthLog->getStatus() === FeedHealthLog::STATUS_HEALTHY) {
            $feed->setHealthStatus('healthy');
        } elseif ($feed->getConsecutiveFailures() >= self::MAX_CONSECUTIVE_FAILURES) {
            $feed->setHealthStatus('unhealthy');
        } else {
            $feed->setHealthStatus('warning');
        }
    }

    public function getFeedHealthSummary(): array
    {
        $feeds = $this->feedRepository->findBy(['status' => 'active']);
        $summary = [
            'total' => count($feeds),
            'healthy' => 0,
            'warning' => 0,
            'unhealthy' => 0,
        ];

        foreach ($feeds as $feed) {
            $summary[$feed->getHealthStatus()]++;
        }

        return $summary;
    }

    public function cleanupOldHealthLogs(int $daysToKeep = 30): int
    {
        $cutoffDate = new \DateTime("-{$daysToKeep} days");
        return $this->healthLogRepository->deleteOldLogs($cutoffDate);
    }
}