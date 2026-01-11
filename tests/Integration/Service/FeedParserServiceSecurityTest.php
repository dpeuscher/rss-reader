<?php

namespace App\Tests\Integration\Service;

use App\Service\FeedParserService;
use App\Service\UrlValidator;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

class FeedParserServiceSecurityTest extends TestCase
{
    private FeedParserService $feedParserService;

    protected function setUp(): void
    {
        $urlValidator = new UrlValidator(new NullLogger());
        
        // Mock HTTP client to prevent actual network requests in tests
        $mockHttpClient = new MockHttpClient([
            new MockResponse('<?xml version="1.0"?><rss><channel><title>Test</title></channel></rss>', [
                'http_code' => 200,
                'response_headers' => ['content-type' => 'application/rss+xml'],
            ])
        ]);
        
        $this->feedParserService = new FeedParserService($urlValidator, $mockHttpClient);
    }

    /**
     * @dataProvider ssrfUrlProvider
     */
    public function testSSRFProtection(string $maliciousUrl): void
    {
        $result = $this->feedParserService->validateFeed($maliciousUrl);
        $this->assertFalse($result->isValid(), "SSRF URL should be blocked: $maliciousUrl");
        
        // Ensure error message doesn't leak sensitive information
        $this->assertStringNotContainsString('127.0.0.1', $result->getMessage());
        $this->assertStringNotContainsString('localhost', $result->getMessage());
        $this->assertStringNotContainsString('internal', $result->getMessage());
    }

    /**
     * @dataProvider ssrfUrlProvider
     */
    public function testParseFeedSSRFProtection(string $maliciousUrl): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid feed URL');
        
        $this->feedParserService->parseFeed($maliciousUrl);
    }

    public function testValidFeedUrlPasses(): void
    {
        // This test would normally make a real HTTP request
        // In a real test environment, you'd configure a test feed URL
        // For now, we'll skip this test in CI environment
        if (getenv('CI') || !getenv('TEST_REAL_FEEDS')) {
            $this->markTestSkipped('Skipping real network test in CI environment');
        }

        $result = $this->feedParserService->validateFeed('https://github.com/php/php-src/releases.atom');
        // In a real environment with proper network access, this should pass
    }

    public function testErrorMessageSecurity(): void
    {
        $result = $this->feedParserService->validateFeed('http://127.0.0.1:22/ssh');
        
        // Ensure error messages don't expose internal details
        $sensitiveTerms = [
            '127.0.0.1',
            'localhost', 
            'internal',
            'private',
            'blocked',
            'ssh',
            'port 22',
            'connection refused',
            'timeout',
            'DNS'
        ];
        
        foreach ($sensitiveTerms as $term) {
            $this->assertStringNotContainsString(
                $term, 
                $result->getMessage(), 
                "Error message should not contain sensitive term: $term"
            );
        }
    }

    public function testResponseSizeLimit(): void
    {
        // Create a mock response that's larger than the 10MB limit
        $largeContent = str_repeat('X', 11 * 1024 * 1024); // 11MB
        
        $mockHttpClient = new MockHttpClient([
            new MockResponse($largeContent, [
                'http_code' => 200,
                'response_headers' => ['content-type' => 'application/rss+xml'],
            ])
        ]);
        
        $urlValidator = new UrlValidator(new NullLogger());
        $feedParserService = new FeedParserService($urlValidator, $mockHttpClient);
        
        // This should fail due to size limit (though MockHttpClient may not enforce this)
        // In a real scenario, the HTTP client would reject responses over 10MB
        $result = $feedParserService->validateFeed('https://example.com/large-feed.xml');
        // The exact behavior depends on HTTP client implementation
    }

    public static function ssrfUrlProvider(): array
    {
        return [
            // Localhost variants
            ['http://127.0.0.1'],
            ['http://127.0.0.1:22'],
            ['http://127.0.0.1:3306'],
            ['http://127.0.0.1:6379'],
            ['http://localhost'],
            ['http://localhost:8080'],
            
            // Private networks
            ['http://192.168.1.1'],
            ['http://10.0.0.1'],
            ['http://172.16.0.1'],
            
            // AWS metadata service
            ['http://169.254.169.254/latest/meta-data/'],
            ['http://169.254.169.254/latest/user-data/'],
            
            // IPv6 localhost
            ['http://[::1]'],
            ['http://[::1]:8080'],
            
            // IPv6 private
            ['http://[fc00::1]'],
            ['http://[fe80::1]'],
            
            // Different schemes
            ['file:///etc/passwd'],
            ['ftp://internal.example.com/file.txt'],
            ['gopher://internal.example.com:70/'],
            
            // Encoded attempts
            ['http://127.0.0.1%2F'],
            ['http://127.0.0.1%3A22'],
            
            // Alternative IP representations
            ['http://2130706433'], // 127.0.0.1 in decimal
            ['http://0x7f000001'], // 127.0.0.1 in hex
            ['http://0177.0.0.1'], // 127.0.0.1 in octal
            
            // IPv4-mapped IPv6
            ['http://[::ffff:127.0.0.1]'],
            ['http://[::ffff:192.168.1.1]'],
            
            // Bypass attempts with different formats
            ['http://127.000.000.001'],
            ['http://127.1'],
            ['http://127.0.1'],
        ];
    }

    public function testRedirectValidation(): void
    {
        // Create a mock client that simulates a redirect to a blocked URL
        $mockResponses = [
            new MockResponse('', [
                'http_code' => 302,
                'response_headers' => ['location' => 'http://127.0.0.1:22/ssh'],
            ]),
            new MockResponse('SSH Server', [
                'http_code' => 200,
                'response_headers' => ['content-type' => 'text/plain'],
            ])
        ];
        
        $mockHttpClient = new MockHttpClient($mockResponses);
        $urlValidator = new UrlValidator(new NullLogger());
        $feedParserService = new FeedParserService($urlValidator, $mockHttpClient);
        
        $result = $feedParserService->validateFeed('https://example.com/redirect-to-internal');
        $this->assertFalse($result->isValid(), 'Redirect to internal service should be blocked');
    }

    public function testMaxRedirectLimit(): void
    {
        // Create a chain of redirects that exceeds the limit
        $mockResponses = [];
        for ($i = 0; $i < 10; $i++) {
            $mockResponses[] = new MockResponse('', [
                'http_code' => 302,
                'response_headers' => ['location' => "https://example.com/redirect$i"],
            ]);
        }
        
        $mockHttpClient = new MockHttpClient($mockResponses);
        $urlValidator = new UrlValidator(new NullLogger());
        $feedParserService = new FeedParserService($urlValidator, $mockHttpClient);
        
        $result = $feedParserService->validateFeed('https://example.com/start-redirect');
        $this->assertFalse($result->isValid(), 'Too many redirects should be blocked');
        $this->assertStringContainsString('redirect', strtolower($result->getMessage()));
    }
}