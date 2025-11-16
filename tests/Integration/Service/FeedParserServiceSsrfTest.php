<?php

namespace App\Tests\Integration\Service;

use App\Service\FeedParserService;
use App\Service\UrlValidatorService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class FeedParserServiceSsrfTest extends TestCase
{
    private FeedParserService $feedParserService;
    private UrlValidatorService $urlValidator;
    private LoggerInterface $logger;
    private HttpClientInterface $httpClient;

    protected function setUp(): void
    {
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->httpClient = $this->createMock(HttpClientInterface::class);
        $this->urlValidator = new UrlValidatorService($this->logger);
        
        $this->feedParserService = new FeedParserService(
            $this->urlValidator,
            $this->logger,
            $this->httpClient
        );
    }

    public function testValidateFeedRejectsLocalhostUrls(): void
    {
        $localhostUrls = [
            'http://127.0.0.1/feed.xml',
            'http://localhost/feed.xml',
            'https://127.0.0.1:8080/api/feed',
        ];

        foreach ($localhostUrls as $url) {
            $result = $this->feedParserService->validateFeed($url);
            
            $this->assertFalse($result->isValid(), "Localhost URL should be rejected: {$url}");
            $this->assertStringContainsString('Invalid or unsafe URL', $result->getMessage());
        }
    }

    public function testValidateFeedRejectsPrivateNetworkUrls(): void
    {
        $privateUrls = [
            'http://192.168.1.1/feed.xml',
            'http://10.0.0.1/feed.xml',
            'http://172.16.0.1/feed.xml',
            'http://169.254.169.254/latest/meta-data/',  // AWS metadata
        ];

        foreach ($privateUrls as $url) {
            $result = $this->feedParserService->validateFeed($url);
            
            $this->assertFalse($result->isValid(), "Private network URL should be rejected: {$url}");
            $this->assertStringContainsString('Invalid or unsafe URL', $result->getMessage());
        }
    }

    public function testValidateFeedRejectsInvalidSchemes(): void
    {
        $invalidSchemeUrls = [
            'ftp://example.com/feed.xml',
            'file:///etc/passwd',
            'javascript:alert(1)',
            'data:text/plain;base64,SGVsbG8=',
        ];

        foreach ($invalidSchemeUrls as $url) {
            $result = $this->feedParserService->validateFeed($url);
            
            $this->assertFalse($result->isValid(), "Invalid scheme URL should be rejected: {$url}");
            $this->assertStringContainsString('Invalid or unsafe URL', $result->getMessage());
        }
    }

    public function testParseFeedThrowsExceptionForDangerousUrls(): void
    {
        $dangerousUrls = [
            'http://127.0.0.1/feed.xml',
            'http://192.168.1.1/feed.xml',
            'http://169.254.169.254/latest/meta-data/',
            'ftp://example.com/feed.xml',
        ];

        foreach ($dangerousUrls as $url) {
            $this->expectException(\InvalidArgumentException::class);
            $this->expectExceptionMessage('Invalid or unsafe URL provided');
            
            $this->feedParserService->parseFeed($url);
        }
    }

    public function testHttpClientConfigurationSecuritySettings(): void
    {
        // Mock a valid external URL that passes validation
        $validUrl = 'https://example.com/feed.xml';
        
        // Create a mock URL validator that allows this URL
        $mockValidator = $this->createMock(UrlValidatorService::class);
        $mockValidator->method('validateFeedUrl')->willReturn(true);
        
        // Create a mock HTTP client to verify security settings
        $mockHttpClient = $this->createMock(HttpClientInterface::class);
        
        // Expect the HTTP client to be called with security settings
        $mockHttpClient->expects($this->once())
            ->method('request')
            ->with(
                'GET',
                $validUrl,
                $this->callback(function ($options) {
                    // Verify security configurations
                    $this->assertArrayHasKey('max_redirects', $options);
                    $this->assertEquals(0, $options['max_redirects']);
                    
                    $this->assertArrayHasKey('max_duration', $options);
                    $this->assertEquals(30, $options['max_duration']);
                    
                    $this->assertArrayHasKey('timeout', $options);
                    $this->assertEquals(10, $options['timeout']);
                    
                    return true;
                })
            )
            ->willReturn($this->createMockResponse());

        $feedParser = new FeedParserService($mockValidator, $this->logger, $mockHttpClient);
        
        // This should call the HTTP client with security settings
        $feedParser->validateFeed($validUrl);
    }

    public function testLoggerIsCalledForSecurityEvents(): void
    {
        $dangerousUrl = 'http://127.0.0.1/feed.xml';
        
        // Expect warning to be logged for URL validation failure
        $this->logger->expects($this->atLeastOnce())
            ->method('warning')
            ->with('URL validation failed during feed validation', ['url' => $dangerousUrl]);

        $result = $this->feedParserService->validateFeed($dangerousUrl);
        $this->assertFalse($result->isValid());
    }

    public function testIpv6PrivateAddressesAreRejected(): void
    {
        $ipv6PrivateUrls = [
            'http://[::1]/feed.xml',           // localhost
            'http://[fc00::1]/feed.xml',       // unique local address
            'http://[fe80::1]/feed.xml',       // link-local
        ];

        foreach ($ipv6PrivateUrls as $url) {
            $result = $this->feedParserService->validateFeed($url);
            
            $this->assertFalse($result->isValid(), "IPv6 private address should be rejected: {$url}");
            $this->assertStringContainsString('Invalid or unsafe URL', $result->getMessage());
        }
    }

    public function testUrlsWithPortsAreStillValidated(): void
    {
        $urlsWithPorts = [
            'http://127.0.0.1:8080/feed.xml',      // localhost with port
            'http://192.168.1.1:3000/feed.xml',    // private with port
        ];

        foreach ($urlsWithPorts as $url) {
            $result = $this->feedParserService->validateFeed($url);
            
            $this->assertFalse($result->isValid(), "Private IP with port should be rejected: {$url}");
            $this->assertStringContainsString('Invalid or unsafe URL', $result->getMessage());
        }
    }

    public function testMalformedUrlsAreRejected(): void
    {
        $malformedUrls = [
            '',
            'not-a-url',
            'http://',
            'http://.',
            'http://..',
            'http://../',
        ];

        foreach ($malformedUrls as $url) {
            $result = $this->feedParserService->validateFeed($url);
            
            $this->assertFalse($result->isValid(), "Malformed URL should be rejected: '{$url}'");
            $this->assertStringContainsString('Invalid or unsafe URL', $result->getMessage());
        }
    }

    private function createMockResponse(): \Symfony\Contracts\HttpClient\ResponseInterface
    {
        $mockResponse = $this->createMock(\Symfony\Contracts\HttpClient\ResponseInterface::class);
        $mockResponse->method('getStatusCode')->willReturn(200);
        $mockResponse->method('getContent')->willReturn('<?xml version="1.0"?><rss><channel><title>Test</title></channel></rss>');
        
        return $mockResponse;
    }
}