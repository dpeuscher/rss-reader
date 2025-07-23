<?php

namespace App\Tests\Integration;

use App\Service\FeedParserService;
use App\Service\UrlSecurityValidator;
use App\Service\SecureHttpClientFactory;
use PHPUnit\Framework\TestCase;

class SsrfPreventionTest extends TestCase
{
    private FeedParserService $feedParser;
    private UrlSecurityValidator $urlValidator;

    protected function setUp(): void
    {
        $this->urlValidator = new UrlSecurityValidator();
        $httpClientFactory = new SecureHttpClientFactory($this->urlValidator);
        $this->feedParser = new FeedParserService($httpClientFactory, $this->urlValidator);
    }

    /**
     * Test that SSRF attacks against localhost are blocked
     */
    public function testLocalhostSsrfBlocked(): void
    {
        $ssrfUrls = [
            'http://127.0.0.1:6379/',
            'http://127.0.0.1:22/',
            'http://127.0.0.1:3306/',
            'http://localhost:8080/',
            'https://127.1.1.1/',
        ];

        foreach ($ssrfUrls as $url) {
            $result = $this->feedParser->validateFeed($url);
            $this->assertFalse($result->isValid(), "SSRF URL should be blocked: $url");
            $this->assertEquals('Access to private IPs not allowed', $result->getMessage());
        }
    }

    /**
     * Test that cloud metadata endpoints are blocked
     */
    public function testCloudMetadataSsrfBlocked(): void
    {
        $metadataUrls = [
            'http://169.254.169.254/latest/meta-data/',
            'http://169.254.169.254/latest/user-data/',
            'http://metadata.google.internal/computeMetadata/v1/',
            'http://100.100.100.200/latest/meta-data/', // Alibaba Cloud
        ];

        foreach ($metadataUrls as $url) {
            $result = $this->feedParser->validateFeed($url);
            $this->assertFalse($result->isValid(), "Metadata URL should be blocked: $url");
            $this->assertEquals('Access to private IPs not allowed', $result->getMessage());
        }
    }

    /**
     * Test that private network ranges are blocked
     */
    public function testPrivateNetworkSsrfBlocked(): void
    {
        $privateUrls = [
            'http://10.0.0.1/',
            'http://172.16.1.1/',
            'http://192.168.1.1/',
            'http://192.168.255.254/',
            'http://172.31.255.255/',
        ];

        foreach ($privateUrls as $url) {
            $result = $this->feedParser->validateFeed($url);
            $this->assertFalse($result->isValid(), "Private network URL should be blocked: $url");
            $this->assertEquals('Access to private IPs not allowed', $result->getMessage());
        }
    }

    /**
     * Test that IPv6 private addresses are blocked
     */
    public function testIpv6SsrfBlocked(): void
    {
        $ipv6Urls = [
            'http://[::1]/',
            'http://[fe80::1]/',
            'http://[fc00::1]/',
            'http://[::ffff:192.168.1.1]/',
            'http://[::ffff:127.0.0.1]/',
        ];

        foreach ($ipv6Urls as $url) {
            $result = $this->feedParser->validateFeed($url);
            $this->assertFalse($result->isValid(), "IPv6 private URL should be blocked: $url");
            $this->assertEquals('Access to private IPs not allowed', $result->getMessage());
        }
    }

    /**
     * Test that URL encoding bypass attempts are blocked
     */
    public function testUrlEncodingBypassBlocked(): void
    {
        $bypassUrls = [
            'http://127%2E0%2E0%2E1/',
            'http://0x7f000001/',
            'http://2130706433/',
            'http://127.0.0.1%2Eexample.com/',
        ];

        foreach ($bypassUrls as $url) {
            $result = $this->feedParser->validateFeed($url);
            $this->assertFalse($result->isValid(), "Bypass URL should be blocked: $url");
            $this->assertContains($result->getMessage(), [
                'Invalid URL format',
                'Access to private IPs not allowed'
            ]);
        }
    }

    /**
     * Test that invalid protocols are blocked
     */
    public function testInvalidProtocolsBlocked(): void
    {
        $invalidProtocols = [
            'ftp://example.com/',
            'file:///etc/passwd',
            'gopher://example.com/',
            'javascript:alert(1)',
            'data:text/html,<script>alert(1)</script>',
        ];

        foreach ($invalidProtocols as $url) {
            $result = $this->feedParser->validateFeed($url);
            $this->assertFalse($result->isValid(), "Invalid protocol should be blocked: $url");
            $this->assertEquals('Invalid URL format', $result->getMessage());
        }
    }

    /**
     * Test that the parseFeed method also validates URLs
     */
    public function testParseFeedValidatesUrls(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Access to private IPs not allowed');
        
        $this->feedParser->parseFeed('http://127.0.0.1/');
    }

    /**
     * Test that parseFeed blocks invalid URL formats
     */
    public function testParseFeedBlocksInvalidFormats(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid URL format');
        
        $this->feedParser->parseFeed('ftp://example.com/');
    }

    /**
     * Test that legitimate external URLs are allowed
     * Note: This test won't make actual HTTP requests in CI environment
     */
    public function testLegitimateUrlsAllowed(): void
    {
        $legitimateUrls = [
            'https://httpbin.org/xml',
            'http://example.com/feed.xml',
            'https://feeds.feedburner.com/example',
        ];

        foreach ($legitimateUrls as $url) {
            $result = $this->feedParser->validateFeed($url);
            // We expect these to pass URL validation, but they may fail
            // on actual HTTP request due to network issues in test environment
            if (!$result->isValid()) {
                // If it fails, ensure it's not due to our security blocks
                $this->assertNotEquals('Access to private IPs not allowed', $result->getMessage());
                $this->assertNotEquals('Invalid URL format', $result->getMessage());
            }
        }
    }

    /**
     * Test secure HTTP client configuration
     */
    public function testSecureHttpClientConfiguration(): void
    {
        $options = $this->urlValidator->getSecureHttpClientOptions();
        
        // Verify timeout configurations
        $this->assertEquals(15, $options['timeout']);
        $this->assertEquals(15, $options['max_duration']);
        $this->assertEquals(3, $options['max_redirects']);
        
        // Verify security headers
        $this->assertArrayHasKey('User-Agent', $options['headers']);
        $this->assertStringContains('Security-Enhanced', $options['headers']['User-Agent']);
        
        // Verify progress callback exists for size limiting
        $this->assertArrayHasKey('on_progress', $options);
        $this->assertTrue(is_callable($options['on_progress']));
    }

    /**
     * Test response size limit enforcement
     */
    public function testResponseSizeLimitEnforcement(): void
    {
        $options = $this->urlValidator->getSecureHttpClientOptions();
        $progressCallback = $options['on_progress'];
        
        $maxSize = 5 * 1024 * 1024; // 5MB
        
        // Test that large response sizes are rejected
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Response size limit exceeded');
        
        $progressCallback($maxSize + 1, $maxSize + 1, 0, 0);
    }

    /**
     * Test DNS resolution security
     * Note: This would ideally mock DNS responses in a real test environment
     */
    public function testDnsResolutionSecurity(): void
    {
        // Test that localhost resolves to private IP and gets blocked
        $result = $this->feedParser->validateFeed('http://localhost/');
        $this->assertFalse($result->isValid());
        $this->assertEquals('Access to private IPs not allowed', $result->getMessage());
    }

    /**
     * Test error message sanitization
     */
    public function testErrorMessageSanitization(): void
    {
        // Test that error messages don't leak internal information
        $result = $this->feedParser->validateFeed('http://127.0.0.1:8080/admin');
        $this->assertFalse($result->isValid());
        
        // Error message should be generic security message
        $this->assertEquals('Access to private IPs not allowed', $result->getMessage());
        
        // Should not contain specific port or path information
        $this->assertStringNotContains('8080', $result->getMessage());
        $this->assertStringNotContains('admin', $result->getMessage());
    }

    /**
     * Test edge cases and potential bypasses
     */
    public function testEdgeCasesAndBypasses(): void
    {
        $edgeCases = [
            'http://0.0.0.0:80/',          // All zeros
            'http://255.255.255.255/',     // Broadcast
            'http://[::]:80/',             // IPv6 any address
            'http://127.000.000.001/',     // Leading zeros
        ];

        foreach ($edgeCases as $url) {
            $result = $this->feedParser->validateFeed($url);
            $this->assertFalse($result->isValid(), "Edge case URL should be blocked: $url");
        }
    }
}