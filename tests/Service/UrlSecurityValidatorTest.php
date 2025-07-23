<?php

namespace App\Tests\Service;

use App\Service\UrlSecurityValidator;
use PHPUnit\Framework\TestCase;

class UrlSecurityValidatorTest extends TestCase
{
    private UrlSecurityValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new UrlSecurityValidator();
    }

    /**
     * Test valid external URLs are accepted
     */
    public function testValidExternalUrls(): void
    {
        $validUrls = [
            'https://example.com/feed.xml',
            'http://feeds.feedburner.com/example',
            'https://blog.example.org/rss',
            'http://www.example.net/atom.xml',
        ];

        foreach ($validUrls as $url) {
            $result = $this->validator->validateUrl($url);
            $this->assertTrue($result->isValid(), "URL should be valid: $url");
        }
    }

    /**
     * Test invalid URL formats are rejected
     */
    public function testInvalidUrlFormats(): void
    {
        $invalidUrls = [
            'not-a-url',
            'ftp://example.com/feed.xml',
            'file:///etc/passwd',
            'javascript:alert(1)',
            'data:text/html,<script>alert(1)</script>',
            '', // empty string
            'gopher://example.com',
            'ldap://example.com',
        ];

        foreach ($invalidUrls as $url) {
            $result = $this->validator->validateUrl($url);
            $this->assertFalse($result->isValid(), "URL should be invalid: $url");
            $this->assertEquals('Invalid URL format', $result->getMessage());
        }
    }

    /**
     * Test private IPv4 addresses are blocked
     */
    public function testPrivateIpv4Blocked(): void
    {
        $privateIps = [
            'http://127.0.0.1/feed.xml',           // Loopback
            'https://127.0.0.1:8080/api',          // Loopback with port
            'http://127.255.255.255/test',         // Loopback range end
            'http://10.0.0.1/feed.xml',            // Class A private
            'http://10.255.255.255/feed.xml',      // Class A private range end
            'http://172.16.0.1/feed.xml',          // Class B private
            'http://172.31.255.255/feed.xml',      // Class B private range end
            'http://192.168.1.1/feed.xml',         // Class C private
            'http://192.168.255.255/feed.xml',     // Class C private range end
            'http://169.254.169.254/feed.xml',     // AWS metadata service
            'http://0.0.0.0/feed.xml',             // Reserved
        ];

        foreach ($privateIps as $url) {
            $result = $this->validator->validateUrl($url);
            $this->assertFalse($result->isValid(), "Private IP should be blocked: $url");
            $this->assertEquals('Access to private IPs not allowed', $result->getMessage());
        }
    }

    /**
     * Test private IPv6 addresses are blocked
     */
    public function testPrivateIpv6Blocked(): void
    {
        $privateIpv6s = [
            'http://[::1]/feed.xml',                    // IPv6 loopback
            'http://[fe80::1]/feed.xml',                // Link-local
            'http://[fc00::1]/feed.xml',                // Unique local
            'http://[ff00::1]/feed.xml',                // Multicast
            'http://[::ffff:192.168.1.1]/feed.xml',    // IPv4-mapped IPv6
        ];

        foreach ($privateIpv6s as $url) {
            $result = $this->validator->validateUrl($url);
            $this->assertFalse($result->isValid(), "Private IPv6 should be blocked: $url");
            $this->assertEquals('Access to private IPs not allowed', $result->getMessage());
        }
    }

    /**
     * Test localhost variants are blocked
     */
    public function testLocalhostVariantsBlocked(): void
    {
        $localhostUrls = [
            'http://localhost/feed.xml',
            'https://localhost.localdomain/feed.xml',
            'http://local/feed.xml',
            'http://broadcasthost/feed.xml',
        ];

        foreach ($localhostUrls as $url) {
            $result = $this->validator->validateUrl($url);
            $this->assertFalse($result->isValid(), "Localhost variant should be blocked: $url");
            $this->assertEquals('Access to private IPs not allowed', $result->getMessage());
        }
    }

    /**
     * Test URL encoding bypass attempts are prevented
     */
    public function testUrlEncodingBypassPrevented(): void
    {
        $encodedUrls = [
            'http://127.0.0.1%2Eexample.com/feed.xml',
            'http://0x7f000001/feed.xml',               // Hex encoding
            'http://2130706433/feed.xml',               // Decimal encoding
            'http://017700000001/feed.xml',             // Octal encoding
        ];

        foreach ($encodedUrls as $url) {
            $result = $this->validator->validateUrl($url);
            $this->assertFalse($result->isValid(), "URL encoding bypass should be prevented: $url");
        }
    }

    /**
     * Test extremely long URLs are rejected
     */
    public function testLongUrlsRejected(): void
    {
        $longUrl = 'https://example.com/' . str_repeat('a', 2050);
        $result = $this->validator->validateUrl($longUrl);
        $this->assertFalse($result->isValid());
        $this->assertEquals('Invalid URL format', $result->getMessage());
    }

    /**
     * Test secure HTTP client options
     */
    public function testSecureHttpClientOptions(): void
    {
        $options = $this->validator->getSecureHttpClientOptions();
        
        $this->assertArrayHasKey('timeout', $options);
        $this->assertArrayHasKey('max_duration', $options);
        $this->assertArrayHasKey('headers', $options);
        $this->assertArrayHasKey('max_redirects', $options);
        
        $this->assertEquals(15, $options['timeout']);
        $this->assertEquals(15, $options['max_duration']);
        $this->assertEquals(3, $options['max_redirects']);
        $this->assertArrayHasKey('User-Agent', $options['headers']);
    }

    /**
     * Test maximum response size configuration
     */
    public function testMaxResponseSize(): void
    {
        $maxSize = $this->validator->getMaxResponseSize();
        $this->assertEquals(5 * 1024 * 1024, $maxSize); // 5MB
    }

    /**
     * Test cloud metadata endpoints are blocked
     */
    public function testCloudMetadataEndpointsBlocked(): void
    {
        $cloudMetadataUrls = [
            'http://169.254.169.254/latest/meta-data/',     // AWS
            'http://metadata.google.internal/',             // GCP
            'http://169.254.169.254/metadata/instance',     // Azure
        ];

        foreach ($cloudMetadataUrls as $url) {
            $result = $this->validator->validateUrl($url);
            $this->assertFalse($result->isValid(), "Cloud metadata endpoint should be blocked: $url");
            $this->assertEquals('Access to private IPs not allowed', $result->getMessage());
        }
    }
}