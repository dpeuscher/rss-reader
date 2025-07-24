<?php

namespace App\Tests\Service;

use App\Service\UrlValidationService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpClient\Exception\TransportException;

class UrlValidationServiceTest extends TestCase
{
    private UrlValidationService $urlValidator;
    private MockHttpClient $mockHttpClient;

    protected function setUp(): void
    {
        $this->mockHttpClient = new MockHttpClient();
        $this->urlValidator = new UrlValidationService($this->mockHttpClient);
    }

    /**
     * @dataProvider validUrlProvider
     */
    public function testValidUrls(string $url): void
    {
        // Mock successful HEAD request with no redirects
        $this->mockHttpClient->setResponseFactory([
            new MockResponse('', ['http_code' => 200])
        ]);

        $result = $this->urlValidator->validateUrl($url);
        $this->assertTrue($result->isValid(), "URL should be valid: $url");
    }

    public function validUrlProvider(): array
    {
        return [
            ['https://example.com/rss.xml'],
            ['http://example.com/feed'],
            ['https://www.example.com/feed.rss'],
            ['http://subdomain.example.com/rss'],
            ['https://example.com:8080/feed'],
        ];
    }

    /**
     * @dataProvider invalidProtocolProvider
     */
    public function testInvalidProtocols(string $url): void
    {
        $result = $this->urlValidator->validateUrl($url);
        $this->assertFalse($result->isValid());
        $this->assertEquals('Invalid URL format', $result->getMessage());
    }

    public function invalidProtocolProvider(): array
    {
        return [
            ['file:///etc/passwd'],
            ['ftp://example.com/file'],
            ['gopher://example.com'],
            ['ldap://example.com'],
            ['javascript:alert(1)'],
            ['data:text/plain,hello'],
        ];
    }

    /**
     * @dataProvider privateIpProvider
     */
    public function testPrivateIpBlocking(string $url): void
    {
        $result = $this->urlValidator->validateUrl($url);
        $this->assertFalse($result->isValid());
        $this->assertEquals('Access to private networks not allowed', $result->getMessage());
    }

    public function privateIpProvider(): array
    {
        return [
            // IPv4 loopback
            ['http://127.0.0.1/'],
            ['http://127.0.0.1:8080/admin'],
            ['http://127.1.1.1/'],
            
            // IPv4 private ranges
            ['http://10.0.0.1/'],
            ['http://10.255.255.255/'],
            ['http://172.16.0.1/'],
            ['http://172.31.255.255/'],
            ['http://192.168.1.1/'],
            ['http://192.168.255.255/'],
            
            // Link-local
            ['http://169.254.169.254/'], // AWS metadata service
            ['http://169.254.1.1/'],
            
            // IPv6 loopback
            ['http://[::1]/'],
            
            // IPv6 unique local
            ['http://[fc00::1]/'],
            ['http://[fd00::1]/'],
            
            // IPv6 link-local
            ['http://[fe80::1]/'],
        ];
    }

    /**
     * @dataProvider malformedUrlProvider
     */
    public function testMalformedUrls(string $url): void
    {
        $result = $this->urlValidator->validateUrl($url);
        $this->assertFalse($result->isValid());
        $this->assertEquals('Invalid URL format', $result->getMessage());
    }

    public function malformedUrlProvider(): array
    {
        return [
            ['not-a-url'],
            ['http://'],
            ['https://'],
            [''],
            ['http://user:pass@example.com/'], // URLs with credentials
            ['://example.com'],
            ['http:/example.com'], // Missing slash
        ];
    }

    public function testRedirectToPrivateIp(): void
    {
        // Mock redirect response
        $this->mockHttpClient->setResponseFactory([
            new MockResponse('', [
                'http_code' => 302,
                'response_headers' => ['Location' => 'http://127.0.0.1:8080/admin']
            ])
        ]);

        $result = $this->urlValidator->validateUrl('http://example.com/redirect');
        $this->assertFalse($result->isValid());
        $this->assertEquals('Access to private networks not allowed', $result->getMessage());
    }

    public function testValidRedirect(): void
    {
        // Mock redirect to valid external URL
        $this->mockHttpClient->setResponseFactory([
            new MockResponse('', [
                'http_code' => 302,
                'response_headers' => ['Location' => 'https://other-example.com/feed']
            ])
        ]);

        $result = $this->urlValidator->validateUrl('http://example.com/redirect');
        $this->assertTrue($result->isValid());
    }

    public function testNoRedirect(): void
    {
        // Mock no redirect response
        $this->mockHttpClient->setResponseFactory([
            new MockResponse('', ['http_code' => 200])
        ]);

        $result = $this->urlValidator->validateUrl('http://example.com/feed');
        $this->assertTrue($result->isValid());
    }

    public function testHttpClientError(): void
    {
        // Mock HTTP client error (for redirect validation)
        $this->mockHttpClient->setResponseFactory([
            new MockResponse('', ['http_code' => 500])
        ]);

        // Should still pass validation as redirect validation errors are gracefully handled
        $result = $this->urlValidator->validateUrl('http://example.com/feed');
        $this->assertTrue($result->isValid());
    }

    public function testIpRangeValidation(): void
    {
        // Test IPv4 range validation
        $privateIps = [
            '127.0.0.1',
            '10.0.0.1',
            '172.16.0.1',
            '192.168.1.1',
            '169.254.169.254',
        ];

        foreach ($privateIps as $ip) {
            $result = $this->urlValidator->validateUrl("http://$ip/");
            $this->assertFalse($result->isValid(), "IP $ip should be blocked");
            $this->assertEquals('Access to private networks not allowed', $result->getMessage());
        }

        // Test public IPs (should pass initial IP validation)
        $this->mockHttpClient->setResponseFactory([
            new MockResponse('', ['http_code' => 200])
        ]);

        $publicIps = [
            '8.8.8.8',
            '1.1.1.1',
            '208.67.222.222',
        ];

        foreach ($publicIps as $ip) {
            $result = $this->urlValidator->validateUrl("http://$ip/");
            $this->assertTrue($result->isValid(), "Public IP $ip should be allowed");
        }
    }

    public function testIpv6RangeValidation(): void
    {
        // Test IPv6 private ranges
        $privateIpv6s = [
            '::1',
            'fc00::1',
            'fd00::1',
            'fe80::1',
        ];

        foreach ($privateIpv6s as $ip) {
            $result = $this->urlValidator->validateUrl("http://[$ip]/");
            $this->assertFalse($result->isValid(), "IPv6 $ip should be blocked");
            $this->assertEquals('Access to private networks not allowed', $result->getMessage());
        }
    }

    public function testEdgeCases(): void
    {
        // Test URL with port but valid public domain
        $this->mockHttpClient->setResponseFactory([
            new MockResponse('', ['http_code' => 200])
        ]);

        $result = $this->urlValidator->validateUrl('http://example.com:3000/feed');
        $this->assertTrue($result->isValid());

        // Test very long URL (should still validate)
        $longPath = str_repeat('a', 1000);
        $result = $this->urlValidator->validateUrl("http://example.com/$longPath");
        $this->assertTrue($result->isValid());
    }

    public function testDnsResolutionFailures(): void
    {
        // Test with non-existent domain (should fail hostname resolution)
        $result = $this->urlValidator->validateUrl('http://this-domain-does-not-exist-12345.com/feed');
        $this->assertFalse($result->isValid());
        $this->assertEquals('Unable to resolve hostname', $result->getMessage());
    }

    public function testIpv6RedirectValidation(): void
    {
        // Mock redirect to IPv6 private address
        $this->mockHttpClient->setResponseFactory([
            new MockResponse('', [
                'http_code' => 302,
                'response_headers' => ['Location' => 'http://[::1]/admin']
            ])
        ]);

        $result = $this->urlValidator->validateUrl('http://example.com/redirect');
        $this->assertFalse($result->isValid());
        $this->assertEquals('Access to private networks not allowed', $result->getMessage());
        
        // Mock redirect to IPv6 unique local address
        $this->mockHttpClient->setResponseFactory([
            new MockResponse('', [
                'http_code' => 302,
                'response_headers' => ['Location' => 'http://[fc00::1]/admin']
            ])
        ]);

        $result = $this->urlValidator->validateUrl('http://example.com/redirect');
        $this->assertFalse($result->isValid());
        $this->assertEquals('Access to private networks not allowed', $result->getMessage());
        
        // Mock redirect to IPv6 link-local address
        $this->mockHttpClient->setResponseFactory([
            new MockResponse('', [
                'http_code' => 302,
                'response_headers' => ['Location' => 'http://[fe80::1]/admin']
            ])
        ]);

        $result = $this->urlValidator->validateUrl('http://example.com/redirect');
        $this->assertFalse($result->isValid());
        $this->assertEquals('Access to private networks not allowed', $result->getMessage());
    }

    public function testIpv4ValidationInIpRange(): void
    {
        // Test that invalid IPv4 strings are properly handled in ipInRange method
        // This is tested indirectly through the URL validation
        $result = $this->urlValidator->validateUrl('http://999.999.999.999/');
        $this->assertFalse($result->isValid());
        $this->assertEquals('Invalid URL format', $result->getMessage());
    }

    public function testDnsCachePerformance(): void
    {
        // Mock successful response
        $this->mockHttpClient->setResponseFactory([
            new MockResponse('', ['http_code' => 200])
        ]);
        
        // Test that DNS resolution is cached (multiple calls to same domain)
        $url = 'http://example.com/feed';
        $result1 = $this->urlValidator->validateUrl($url);
        $result2 = $this->urlValidator->validateUrl($url);
        
        $this->assertTrue($result1->isValid());
        $this->assertTrue($result2->isValid());
        
        // Both calls should succeed, demonstrating caching doesn't break functionality
    }

    public function testIpv4ValidationBeforeConversion(): void
    {
        // Test that malformed IPv4 addresses are properly rejected
        $malformedIps = [
            'http://256.256.256.256/',  // Invalid IPv4 (out of range)
            'http://192.168.1/',        // Incomplete IPv4
            'http://192.168.1.1.1/',    // Too many octets
        ];
        
        foreach ($malformedIps as $url) {
            $result = $this->urlValidator->validateUrl($url);
            $this->assertFalse($result->isValid(), "Malformed IP in URL should be rejected: $url");
        }
    }
}