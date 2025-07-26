<?php

namespace App\Tests\Service\Security;

use App\Service\Security\UrlSecurityService;
use App\Service\Security\UrlValidationResult;
use PHPUnit\Framework\TestCase;

class UrlSecurityServiceTest extends TestCase
{
    private UrlSecurityService $urlSecurityService;

    protected function setUp(): void
    {
        $this->urlSecurityService = new UrlSecurityService();
    }

    public function testValidHttpsUrl(): void
    {
        $result = $this->urlSecurityService->validateUrl('https://example.com/feed.xml');
        $this->assertTrue($result->isValid());
        $this->assertEquals('URL is valid', $result->getMessage());
    }

    public function testValidHttpUrl(): void
    {
        $result = $this->urlSecurityService->validateUrl('http://example.com/feed.xml');
        $this->assertTrue($result->isValid());
    }

    public function testBlocksFileScheme(): void
    {
        $result = $this->urlSecurityService->validateUrl('file:///etc/passwd');
        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('Only HTTP and HTTPS schemes are allowed', $result->getMessage());
        $this->assertContains('invalid_scheme', $result->getViolations());
    }

    public function testBlocksFtpScheme(): void
    {
        $result = $this->urlSecurityService->validateUrl('ftp://example.com/file.txt');
        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('Only HTTP and HTTPS schemes are allowed', $result->getMessage());
    }

    public function testBlocksLocalhostIpv4(): void
    {
        $result = $this->urlSecurityService->validateUrl('http://127.0.0.1:8080/test');
        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('private/internal IP addresses', $result->getMessage());
        $this->assertContains('private_ip', $result->getViolations());
    }

    public function testBlocksLocalhostIpv6(): void
    {
        $result = $this->urlSecurityService->validateUrl('http://[::1]/test');
        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('private/internal IPv6 addresses', $result->getMessage());
        $this->assertContains('private_ipv6', $result->getViolations());
    }

    public function testBlocksPrivateIpRange10(): void
    {
        $result = $this->urlSecurityService->validateUrl('http://10.0.0.1/test');
        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('private/internal IP addresses', $result->getMessage());
    }

    public function testBlocksPrivateIpRange172(): void
    {
        $result = $this->urlSecurityService->validateUrl('http://172.16.0.1/test');
        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('private/internal IP addresses', $result->getMessage());
    }

    public function testBlocksPrivateIpRange192(): void
    {
        $result = $this->urlSecurityService->validateUrl('http://192.168.1.1/test');
        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('private/internal IP addresses', $result->getMessage());
    }

    public function testBlocksLinkLocalIp(): void
    {
        $result = $this->urlSecurityService->validateUrl('http://169.254.1.1/test');
        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('private/internal IP addresses', $result->getMessage());
    }

    public function testBlocksExcessivelyLongUrl(): void
    {
        $longUrl = 'https://example.com/' . str_repeat('a', 2048);
        $result = $this->urlSecurityService->validateUrl($longUrl);
        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('URL length exceeds maximum', $result->getMessage());
        $this->assertContains('length_violation', $result->getViolations());
    }

    public function testBlocksInvalidUrlFormat(): void
    {
        $result = $this->urlSecurityService->validateUrl('not-a-valid-url');
        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('Invalid URL format', $result->getMessage());
        $this->assertContains('invalid_format', $result->getViolations());
    }

    public function testNormalizesUrlEncoding(): void
    {
        // URL-encoded localhost (127.0.0.1)
        $result = $this->urlSecurityService->validateUrl('http://%31%32%37%2E%30%2E%30%2E%31/test');
        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('private/internal IP addresses', $result->getMessage());
    }

    public function testIsUrlSafeConvenienceMethod(): void
    {
        $this->assertTrue($this->urlSecurityService->isUrlSafe('https://example.com/feed.xml'));
        $this->assertFalse($this->urlSecurityService->isUrlSafe('http://127.0.0.1/test'));
        $this->assertFalse($this->urlSecurityService->isUrlSafe('file:///etc/passwd'));
    }

    public function testHandlesUrlWithoutScheme(): void
    {
        $result = $this->urlSecurityService->validateUrl('example.com/feed.xml');
        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('Invalid URL format', $result->getMessage());
    }

    public function testHandlesEmptyUrl(): void
    {
        $result = $this->urlSecurityService->validateUrl('');
        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('Invalid URL format', $result->getMessage());
    }

    /**
     * Test DNS resolution failure scenarios
     */
    public function testDnsResolutionFailure(): void
    {
        // Test with a domain that should not exist
        $result = $this->urlSecurityService->validateUrl('http://this-domain-should-never-exist-12345.com/feed');
        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('DNS resolution failed', $result->getMessage());
        $this->assertContains('dns_resolution_failed', $result->getViolations());
    }

    /**
     * Test IPv6 edge cases
     */
    public function testBlocksIPv6Loopback(): void
    {
        $result = $this->urlSecurityService->validateUrl('http://[::1]/test');
        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('private/internal IPv6 addresses', $result->getMessage());
        $this->assertContains('private_ipv6', $result->getViolations());
    }

    public function testBlocksIPv6LinkLocal(): void
    {
        $result = $this->urlSecurityService->validateUrl('http://[fe80::1]/test');
        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('private/internal IPv6 addresses', $result->getMessage());
    }

    public function testBlocksIPv6UniqueLocal(): void
    {
        $result = $this->urlSecurityService->validateUrl('http://[fc00::1]/test');
        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('private/internal IPv6 addresses', $result->getMessage());
    }

    public function testBlocksIPv4MappedIPv6(): void
    {
        $result = $this->urlSecurityService->validateUrl('http://[::ffff:127.0.0.1]/test');
        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('private/internal IPv6 addresses', $result->getMessage());
    }

    /**
     * Test comprehensive URL normalization including IDN
     */
    public function testNormalizesInternationalDomainNames(): void
    {
        // Test with a domain that uses international characters
        // This should be converted to ASCII using IDN
        $result = $this->urlSecurityService->validateUrl('http://münchen.de/feed');
        
        // The result depends on whether the domain resolves, but normalization should occur
        // We're mainly testing that IDN processing doesn't crash the application
        $this->assertInstanceOf(UrlValidationResult::class, $result);
    }

    public function testNormalizesUrlCasing(): void
    {
        // Test that scheme and domain are normalized to lowercase
        $result = $this->urlSecurityService->validateUrl('HTTP://EXAMPLE.COM/Feed.XML');
        // The exact result depends on DNS resolution, but should not crash
        $this->assertInstanceOf(UrlValidationResult::class, $result);
    }

    public function testHandlesUrlWithCredentials(): void
    {
        $result = $this->urlSecurityService->validateUrl('http://user:pass@example.com/feed');
        // Should handle URLs with credentials without crashing
        $this->assertInstanceOf(UrlValidationResult::class, $result);
    }

    public function testHandlesUrlWithPort(): void
    {
        $result = $this->urlSecurityService->validateUrl('http://example.com:8080/feed');
        // Should handle URLs with custom ports
        $this->assertInstanceOf(UrlValidationResult::class, $result);
    }

    public function testHandlesUrlWithQuery(): void
    {
        $result = $this->urlSecurityService->validateUrl('http://example.com/feed?format=rss');
        // Should handle URLs with query parameters
        $this->assertInstanceOf(UrlValidationResult::class, $result);
    }

    public function testHandlesUrlWithFragment(): void
    {
        $result = $this->urlSecurityService->validateUrl('http://example.com/feed#section1');
        // Should handle URLs with fragments
        $this->assertInstanceOf(UrlValidationResult::class, $result);
    }

    /**
     * Test URL length validation
     */
    public function testBlocksExcessivelyLongUrls(): void
    {
        $longUrl = 'http://example.com/' . str_repeat('a', 2050);
        $result = $this->urlSecurityService->validateUrl($longUrl);
        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('URL length exceeds maximum', $result->getMessage());
        $this->assertContains('length_violation', $result->getViolations());
    }

    /**
     * Test IPv6 range validation edge cases
     */
    public function testIPv6RangeValidationEdgeCases(): void
    {
        // Test various IPv6 formats and edge cases
        $testCases = [
            'http://[::]/test',                    // All zeros
            'http://[2001:db8::1]/test',          // Should be valid (documentation range)
            'http://[::ffff:192.168.1.1]/test',   // IPv4-mapped private IP
        ];

        foreach ($testCases as $url) {
            $result = $this->urlSecurityService->validateUrl($url);
            $this->assertInstanceOf(UrlValidationResult::class, $result);
        }
    }

    /**
     * Test DNS timeout handling (this test may be slow by design)
     */
    public function testDnsTimeoutHandling(): void
    {
        // Test with a domain that should timeout (using a non-routable IP)
        $startTime = microtime(true);
        $result = $this->urlSecurityService->validateUrl('http://192.0.2.1/feed'); // TEST-NET-1 (RFC 5737)
        $endTime = microtime(true);
        
        // Should complete within reasonable time (our timeout is 5 seconds)
        $this->assertLessThan(10, $endTime - $startTime);
        $this->assertInstanceOf(UrlValidationResult::class, $result);
    }

    /**
     * Test multiple URL encoding bypass attempts
     */
    public function testMultipleEncodingBypassAttempts(): void
    {
        $bypassAttempts = [
            'http://%31%32%37%2E%30%2E%30%2E%31/test',           // Single encoding
            'http://%25%33%31%25%33%32%25%33%37/test',          // Double encoding attempt
            'http://127.0.0.1%2F..%2F..%2Fetc%2Fpasswd',        // Path traversal attempt
        ];

        foreach ($bypassAttempts as $url) {
            $result = $this->urlSecurityService->validateUrl($url);
            // All should be blocked for various reasons
            $this->assertFalse($result->isValid(), "URL should be blocked: $url");
        }
    }
}