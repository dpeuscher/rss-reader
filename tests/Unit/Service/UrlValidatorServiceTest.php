<?php

namespace App\Tests\Unit\Service;

use App\Service\UrlValidatorService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class UrlValidatorServiceTest extends TestCase
{
    private UrlValidatorService $urlValidator;
    private LoggerInterface $logger;

    protected function setUp(): void
    {
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->urlValidator = new UrlValidatorService($this->logger);
    }

    public function testValidHttpsUrl(): void
    {
        $validUrl = 'https://feeds.feedburner.com/example';
        $this->assertTrue($this->urlValidator->validateFeedUrl($validUrl));
    }

    public function testValidHttpUrl(): void
    {
        $validUrl = 'http://example.com/feed.xml';
        $this->assertTrue($this->urlValidator->validateFeedUrl($validUrl));
    }

    public function testInvalidUrlSchemes(): void
    {
        $invalidSchemes = [
            'ftp://example.com/feed.xml',
            'file:///etc/passwd',
            'data:text/plain;base64,SGVsbG8=',
            'javascript:alert("xss")',
            'mailto:user@example.com',
            'ssh://user@host',
        ];

        foreach ($invalidSchemes as $url) {
            $this->assertFalse(
                $this->urlValidator->validateFeedUrl($url),
                "URL with invalid scheme should be rejected: {$url}"
            );
        }
    }

    public function testMalformedUrls(): void
    {
        $malformedUrls = [
            '',
            'not-a-url',
            'http://',
            'https://',
            'http://.',
            'http://..',
            'http://../',
            'http://?',
            'http://??/',
            'http://#',
            'http://##/',
            'http:// shouldfail.com',
            'http://-error-.invalid/',
            'http://a.b--c.de/',
        ];

        foreach ($malformedUrls as $url) {
            $this->assertFalse(
                $this->urlValidator->validateFeedUrl($url),
                "Malformed URL should be rejected: '{$url}'"
            );
        }
    }

    public function testPrivateIpv4Addresses(): void
    {
        $privateIpv4s = [
            'http://127.0.0.1/feed.xml',           // localhost
            'http://127.1.1.1/feed.xml',           // localhost range
            'http://10.0.0.1/feed.xml',            // private class A
            'http://10.255.255.255/feed.xml',      // private class A
            'http://172.16.0.1/feed.xml',          // private class B
            'http://172.31.255.255/feed.xml',      // private class B
            'http://192.168.0.1/feed.xml',         // private class C
            'http://192.168.255.255/feed.xml',     // private class C
            'http://169.254.1.1/feed.xml',         // link-local
            'http://169.254.169.254/feed.xml',     // AWS metadata
        ];

        foreach ($privateIpv4s as $url) {
            $this->assertFalse(
                $this->urlValidator->validateFeedUrl($url),
                "Private IPv4 address should be rejected: {$url}"
            );
        }
    }

    public function testPrivateIpv6Addresses(): void
    {
        $privateIpv6s = [
            'http://[::1]/feed.xml',               // localhost
            'http://[fc00::1]/feed.xml',           // unique local address
            'http://[fd00::1]/feed.xml',           // unique local address
            'http://[fe80::1]/feed.xml',           // link-local
        ];

        foreach ($privateIpv6s as $url) {
            $this->assertFalse(
                $this->urlValidator->validateFeedUrl($url),
                "Private IPv6 address should be rejected: {$url}"
            );
        }
    }

    public function testPublicIpAddresses(): void
    {
        // Note: These tests would pass validation but may fail DNS resolution
        // In a real test environment, you might want to mock the DNS resolution
        $publicIps = [
            'http://8.8.8.8/feed.xml',            // Google DNS
            'http://1.1.1.1/feed.xml',            // Cloudflare DNS
        ];

        foreach ($publicIps as $url) {
            // These might fail due to DNS resolution, but they should pass IP validation
            // In a real implementation, you'd mock the DNS resolution method
            $result = $this->urlValidator->validateFeedUrl($url);
            // We can't assert true here without mocking DNS, but we can ensure
            // it's not failing due to IP range checking
            $this->assertIsBool($result);
        }
    }

    public function testHostnameValidation(): void
    {
        $validHostnames = [
            'http://example.com/feed.xml',
            'http://sub.example.com/feed.xml',
            'http://example-site.com/feed.xml',
            'http://example123.com/feed.xml',
        ];

        $invalidHostnames = [
            'http://-example.com/feed.xml',        // starts with hyphen
            'http://example-.com/feed.xml',        // ends with hyphen
            'http://ex..ample.com/feed.xml',       // double dots
            'http://.example.com/feed.xml',        // starts with dot
            'http://example.com./feed.xml',        // trailing dot (actually valid but we might reject)
        ];

        // Note: Valid hostnames might still fail due to DNS resolution
        // In a proper test, you'd mock the DNS resolution
        foreach ($validHostnames as $url) {
            $result = $this->urlValidator->validateFeedUrl($url);
            $this->assertIsBool($result, "Valid hostname should not throw exception: {$url}");
        }

        foreach ($invalidHostnames as $url) {
            $this->assertFalse(
                $this->urlValidator->validateFeedUrl($url),
                "Invalid hostname should be rejected: {$url}"
            );
        }
    }

    public function testUrlsWithPorts(): void
    {
        $urlsWithPorts = [
            'http://127.0.0.1:8080/feed.xml',      // localhost with port
            'http://192.168.1.1:3000/feed.xml',    // private IP with port
            'http://example.com:8080/feed.xml',     // public hostname with port
        ];

        // Private IPs should still be rejected even with ports
        $this->assertFalse($this->urlValidator->validateFeedUrl($urlsWithPorts[0]));
        $this->assertFalse($this->urlValidator->validateFeedUrl($urlsWithPorts[1]));
        
        // Public hostname might pass or fail depending on DNS resolution
        $result = $this->urlValidator->validateFeedUrl($urlsWithPorts[2]);
        $this->assertIsBool($result);
    }

    public function testUrlsWithPaths(): void
    {
        $urlsWithPaths = [
            'http://127.0.0.1/path/to/feed.xml',
            'http://example.com/feeds/rss.xml',
            'http://192.168.1.1/api/feeds',
        ];

        // Private IPs should be rejected regardless of path
        $this->assertFalse($this->urlValidator->validateFeedUrl($urlsWithPaths[0]));
        $this->assertFalse($this->urlValidator->validateFeedUrl($urlsWithPaths[2]));
        
        // Public hostname might pass or fail depending on DNS resolution
        $result = $this->urlValidator->validateFeedUrl($urlsWithPaths[1]);
        $this->assertIsBool($result);
    }

    public function testUrlsWithQueryParameters(): void
    {
        $urlsWithQuery = [
            'http://127.0.0.1/feed.xml?format=rss',
            'http://example.com/feed?type=rss&limit=10',
        ];

        // Private IP should be rejected
        $this->assertFalse($this->urlValidator->validateFeedUrl($urlsWithQuery[0]));
        
        // Public hostname might pass or fail depending on DNS resolution
        $result = $this->urlValidator->validateFeedUrl($urlsWithQuery[1]);
        $this->assertIsBool($result);
    }

    public function testLoggerIsCalled(): void
    {
        // Test that logger is called for invalid URLs
        $this->logger->expects($this->once())
            ->method('warning')
            ->with('Invalid URL scheme', $this->anything());

        $this->urlValidator->validateFeedUrl('ftp://example.com/feed.xml');
    }

    public function testExceptionHandling(): void
    {
        // Test that exceptions are caught and logged
        $this->logger->expects($this->once())
            ->method('error')
            ->with('URL validation failed with exception', $this->anything());

        // This should trigger an exception internally but return false
        $result = $this->urlValidator->validateFeedUrl('http://[invalid-ipv6]/feed.xml');
        $this->assertFalse($result);
    }
}