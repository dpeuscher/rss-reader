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
}