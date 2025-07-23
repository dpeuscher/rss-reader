<?php

namespace App\Tests\Unit\Service;

use App\Service\UrlSecurityValidator;
use PHPUnit\Framework\TestCase;

class UrlSecurityValidatorTest extends TestCase
{
    private UrlSecurityValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new UrlSecurityValidator();
    }

    public function testValidExternalHttpUrl(): void
    {
        $result = $this->validator->validateUrl('http://example.com/feed.xml');
        $this->assertTrue($result->isValid());
        $this->assertEquals('URL is valid', $result->getMessage());
    }

    public function testValidExternalHttpsUrl(): void
    {
        $result = $this->validator->validateUrl('https://example.com/feed.xml');
        $this->assertTrue($result->isValid());
        $this->assertEquals('URL is valid', $result->getMessage());
    }

    public function testInvalidUrlFormat(): void
    {
        $invalidUrls = [
            'not-a-url',
            'ftp://example.com',
            'file:///etc/passwd',
            'gopher://example.com',
            'javascript:alert(1)',
            'mailto:test@example.com',
            '',
            'http://',
            'https://',
        ];

        foreach ($invalidUrls as $url) {
            $result = $this->validator->validateUrl($url);
            $this->assertFalse($result->isValid(), "URL should be invalid: $url");
            $this->assertEquals('Invalid URL format', $result->getMessage());
        }
    }

    public function testPrivateIpv4Addresses(): void
    {
        $privateIps = [
            'http://127.0.0.1/',
            'http://127.0.0.1:8080/',
            'https://127.1.1.1/',
            'http://10.0.0.1/',
            'http://10.255.255.255/',
            'http://192.168.1.1/',
            'http://192.168.255.255/',
            'http://172.16.0.1/',
            'http://172.31.255.255/',
            'http://169.254.169.254/', // AWS metadata
            'http://0.0.0.0/',
            'http://224.0.0.1/', // Multicast
        ];

        foreach ($privateIps as $url) {
            $result = $this->validator->validateUrl($url);
            $this->assertFalse($result->isValid(), "URL should be blocked: $url");
            $this->assertEquals('Access to private IPs not allowed', $result->getMessage());
        }
    }

    public function testPrivateIpv6Addresses(): void
    {
        $privateIpv6s = [
            'http://[::1]/',
            'http://[fe80::1]/',
            'http://[fc00::1]/',
            'http://[fd00::1]/',
            'http://[ff00::1]/',
            'http://[::ffff:192.168.1.1]/', // IPv4-mapped IPv6
            'http://[::ffff:127.0.0.1]/',
        ];

        foreach ($privateIpv6s as $url) {
            $result = $this->validator->validateUrl($url);
            $this->assertFalse($result->isValid(), "URL should be blocked: $url");
            $this->assertEquals('Access to private IPs not allowed', $result->getMessage());
        }
    }

    public function testUrlEncodingBypassAttempts(): void
    {
        $bypassUrls = [
            'http://127.0.0.1%2Eexample.com/',
            'http://127%2E0%2E0%2E1/',
            'http://0x7f000001/', // Hex encoding
            'http://0177.0.0.1/', // Octal encoding
            'http://2130706433/', // Decimal encoding
        ];

        foreach ($bypassUrls as $url) {
            $result = $this->validator->validateUrl($url);
            // These should either be blocked as invalid format or private IP
            $this->assertFalse($result->isValid(), "Bypass attempt should be blocked: $url");
            $this->assertContains($result->getMessage(), [
                'Invalid URL format',
                'Access to private IPs not allowed'
            ]);
        }
    }

    public function testHttpClientSecurityOptions(): void
    {
        $options = $this->validator->getSecureHttpClientOptions();
        
        $this->assertEquals(15, $options['timeout']);
        $this->assertEquals(15, $options['max_duration']);
        $this->assertEquals(3, $options['max_redirects']);
        $this->assertArrayHasKey('User-Agent', $options['headers']);
        $this->assertArrayHasKey('on_progress', $options);
        $this->assertTrue(is_callable($options['on_progress']));
    }

    public function testProgressCallbackResponseSizeLimit(): void
    {
        $options = $this->validator->getSecureHttpClientOptions();
        $progressCallback = $options['on_progress'];
        
        $maxSize = 5 * 1024 * 1024; // 5MB
        
        // Should not throw for sizes under limit
        $progressCallback($maxSize - 1, $maxSize - 1, 0, 0);
        
        // Should throw for sizes over limit
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Response size limit exceeded');
        $progressCallback($maxSize + 1, $maxSize + 1, 0, 0);
    }

    public function testUrlNormalization(): void
    {
        // Test that multiple URL encoding layers are handled
        $encodedUrl = 'http%3A%2F%2Fexample.com%2Ffeed.xml';
        $result = $this->validator->validateUrl($encodedUrl);
        $this->assertTrue($result->isValid());
        $this->assertEquals('http://example.com/feed.xml', $result->getValidatedUrl());
    }

    public function testWhitespaceHandling(): void
    {
        $urlWithWhitespace = '  https://example.com/feed.xml  ';
        $result = $this->validator->validateUrl($urlWithWhitespace);
        $this->assertTrue($result->isValid());
        $this->assertEquals('https://example.com/feed.xml', $result->getValidatedUrl());
    }

    public function testCloudMetadataEndpoints(): void
    {
        $metadataUrls = [
            'http://169.254.169.254/', // AWS
            'http://169.254.169.254/latest/meta-data/',
            'http://metadata.google.internal/', // GCP
            'http://100.100.100.200/latest/meta-data/', // Alibaba Cloud
        ];

        foreach ($metadataUrls as $url) {
            $result = $this->validator->validateUrl($url);
            $this->assertFalse($result->isValid(), "Metadata URL should be blocked: $url");
        }
    }

    /**
     * @dataProvider suspiciousUrlProvider
     */
    public function testSuspiciousUrlPatterns(string $url, bool $shouldBeValid): void
    {
        $result = $this->validator->validateUrl($url);
        
        if ($shouldBeValid) {
            $this->assertTrue($result->isValid(), "URL should be valid: $url");
        } else {
            $this->assertFalse($result->isValid(), "URL should be invalid: $url");
        }
    }

    public function suspiciousUrlProvider(): array
    {
        return [
            ['https://example.com/feed.xml', true],
            ['http://test-domain.com/', true],
            ['http://sub.domain.example.org/path', true],
            ['http://example.com:8080/feed', true],
            ['http://\x00example.com/', false], // Null byte
            ['http://example.com/\x1fpath', false], // Control character
            ['http://example.com\\/path', false], // Backslash
        ];
    }

    public function testDnsResolutionMocking(): void
    {
        // Note: This test would require mocking dns_get_record in a real implementation
        // For now, we test with actual domain resolution
        $result = $this->validator->validateUrl('https://httpbin.org/');
        $this->assertTrue($result->isValid());
    }

    public function testIpv6SubnetCalculation(): void
    {
        // Test the IPv6 subnet checking logic with known private ranges
        $validator = new UrlSecurityValidator();
        
        // These should be blocked
        $privateIpv6Tests = [
            'http://[fe80::1234]/', // Link-local
            'http://[fc00::abcd]/', // Unique local
            'http://[fd12:3456::]/', // Unique local
        ];

        foreach ($privateIpv6Tests as $url) {
            $result = $validator->validateUrl($url);
            $this->assertFalse($result->isValid(), "IPv6 private address should be blocked: $url");
        }
    }

    public function testEdgeCaseHandling(): void
    {
        $edgeCases = [
            'http://localhost/', // Should be blocked (resolves to 127.0.0.1)
            'HTTP://EXAMPLE.COM/', // Case insensitive scheme
            'https://example.com:443/', // Default HTTPS port
            'http://example.com:80/', // Default HTTP port
        ];

        // localhost should be blocked
        $result = $this->validator->validateUrl('http://localhost/');
        $this->assertFalse($result->isValid());

        // Case insensitive scheme should work
        $result = $this->validator->validateUrl('HTTP://example.com/');
        $this->assertTrue($result->isValid());
    }
}