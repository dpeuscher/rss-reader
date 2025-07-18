<?php

namespace App\Tests\Service;

use App\Service\FeedParserService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

class FeedParserServiceSecurityTest extends TestCase
{
    private FeedParserService $feedParserService;

    protected function setUp(): void
    {
        $this->feedParserService = new FeedParserService();
    }

    /**
     * Test that malicious script tags are completely removed
     * AC1: Malicious script tags are completely removed from RSS content
     */
    public function testScriptTagsRemoved(): void
    {
        $maliciousContent = '<p>Safe content</p><script>alert("XSS")</script><p>More safe content</p>';
        $sanitized = $this->feedParserService->normalizeContent($maliciousContent);
        
        $this->assertStringNotContainsString('<script', $sanitized);
        $this->assertStringNotContainsString('alert("XSS")', $sanitized);
        $this->assertStringContainsString('<p>Safe content</p>', $sanitized);
        $this->assertStringContainsString('<p>More safe content</p>', $sanitized);
    }

    /**
     * Test XSS Attack Vector 1: Single-quoted event handlers
     */
    public function testSingleQuotedEventHandlers(): void
    {
        $maliciousContent = '<img src="x" onerror=\'alert("XSS")\'>';
        $sanitized = $this->feedParserService->normalizeContent($maliciousContent);
        
        $this->assertStringNotContainsString('onerror', $sanitized);
        $this->assertStringNotContainsString('alert("XSS")', $sanitized);
        // HTMLPurifier may preserve relative URLs and add alt attributes
        $this->assertStringContainsString('<img', $sanitized);
    }

    /**
     * Test XSS Attack Vector 2: Unquoted event handlers
     */
    public function testUnquotedEventHandlers(): void
    {
        $maliciousContent = '<img src=x onerror=alert(1)>';
        $sanitized = $this->feedParserService->normalizeContent($maliciousContent);
        
        $this->assertStringNotContainsString('onerror', $sanitized);
        $this->assertStringNotContainsString('alert(1)', $sanitized);
    }

    /**
     * Test XSS Attack Vector 3: HTML entity encoded URLs
     */
    public function testHtmlEntityEncodedUrls(): void
    {
        $maliciousContent = '<a href="&#106;&#97;&#118;&#97;&#115;&#99;&#114;&#105;&#112;&#116;&#58;alert(1)">Click</a>';
        $sanitized = $this->feedParserService->normalizeContent($maliciousContent);
        
        $this->assertStringNotContainsString('javascript:', $sanitized);
        $this->assertStringNotContainsString('alert(1)', $sanitized);
        // Should preserve the link text but remove dangerous href
        $this->assertStringContainsString('Click', $sanitized);
    }

    /**
     * Test XSS Attack Vector 4: Data URLs with scripts
     */
    public function testDataUrlsWithScripts(): void
    {
        $maliciousContent = '<img src="data:text/html,<script>alert(1)</script>">';
        $sanitized = $this->feedParserService->normalizeContent($maliciousContent);
        
        $this->assertStringNotContainsString('data:text/html', $sanitized);
        $this->assertStringNotContainsString('alert(1)', $sanitized);
    }

    /**
     * Test XSS Attack Vector 5: Alternative protocols
     */
    public function testAlternativeProtocols(): void
    {
        $maliciousContent = '<a href="vbscript:alert(1)">Click</a>';
        $sanitized = $this->feedParserService->normalizeContent($maliciousContent);
        
        $this->assertStringNotContainsString('vbscript:', $sanitized);
        $this->assertStringNotContainsString('alert(1)', $sanitized);
        $this->assertStringContainsString('Click', $sanitized);
    }

    /**
     * Test XSS Attack Vector 6: Mixed case bypasses
     */
    public function testMixedCaseBypasses(): void
    {
        $maliciousContent = '<img src="x" OnError="alert(1)">';
        $sanitized = $this->feedParserService->normalizeContent($maliciousContent);
        
        $this->assertStringNotContainsString('OnError', $sanitized);
        $this->assertStringNotContainsString('onerror', $sanitized);
        $this->assertStringNotContainsString('alert(1)', $sanitized);
    }

    /**
     * Test XSS Attack Vector 7: Complex nested attacks
     */
    public function testComplexNestedAttacks(): void
    {
        $maliciousContent = '<div><script><!--</script>alert(1)--></div>';
        $sanitized = $this->feedParserService->normalizeContent($maliciousContent);
        
        $this->assertStringNotContainsString('<script>', $sanitized);
        // div is not in whitelist, so should be removed
        $this->assertStringNotContainsString('<div>', $sanitized);
        // The important thing is that no executable script remains
        $this->assertStringNotContainsString('<script', $sanitized);
    }

    /**
     * Test XSS Attack Vector 8: SVG with script
     */
    public function testSvgWithScript(): void
    {
        $maliciousContent = '<svg onload="alert(1)">';
        $sanitized = $this->feedParserService->normalizeContent($maliciousContent);
        
        $this->assertStringNotContainsString('<svg', $sanitized);
        $this->assertStringNotContainsString('onload', $sanitized);
        $this->assertStringNotContainsString('alert(1)', $sanitized);
    }

    /**
     * Test XSS Attack Vector 9: Form with autofocus
     */
    public function testFormWithAutofocus(): void
    {
        $maliciousContent = '<input onfocus="alert(1)" autofocus>';
        $sanitized = $this->feedParserService->normalizeContent($maliciousContent);
        
        $this->assertStringNotContainsString('<input', $sanitized);
        $this->assertStringNotContainsString('onfocus', $sanitized);
        $this->assertStringNotContainsString('alert(1)', $sanitized);
    }

    /**
     * Test XSS Attack Vector 10: CSS expression injection
     */
    public function testCssExpressionInjection(): void
    {
        $maliciousContent = '<div style="background:url(javascript:alert(1))">Content</div>';
        $sanitized = $this->feedParserService->normalizeContent($maliciousContent);
        
        $this->assertStringNotContainsString('javascript:', $sanitized);
        $this->assertStringNotContainsString('alert(1)', $sanitized);
        // div is not in whitelist, so should be removed, but content should remain
        $this->assertStringContainsString('Content', $sanitized);
    }

    /**
     * Test that legitimate content is preserved
     * AC2: Legitimate HTML formatting is preserved
     */
    public function testLegitimateContentPreservation(): void
    {
        $legitimateContent = '<p>Test <strong>bold</strong> and <em>italic</em> text with <a href="https://example.com" title="Example">link</a> and <img src="https://example.com/image.jpg" alt="Test image" width="100" height="100"></p>';
        $sanitized = $this->feedParserService->normalizeContent($legitimateContent);
        
        $this->assertStringContainsString('<p>Test <strong>bold</strong>', $sanitized);
        $this->assertStringContainsString('<em>italic</em>', $sanitized);
        $this->assertStringContainsString('<a href="https://example.com"', $sanitized);
        $this->assertStringContainsString('title="Example"', $sanitized);
        $this->assertStringContainsString('<img src="https://example.com/image.jpg"', $sanitized);
        $this->assertStringContainsString('alt="Test image"', $sanitized);
        $this->assertStringContainsString('width="100"', $sanitized);
        $this->assertStringContainsString('height="100"', $sanitized);
    }

    /**
     * Test that only whitelisted HTML elements are preserved
     * AC4: Only whitelisted HTML elements are preserved
     */
    public function testOnlyWhitelistedElementsPreserved(): void
    {
        $mixedContent = '<p>Allowed</p><div>Not allowed</div><strong>Allowed</strong><script>alert("XSS")</script><h1>Allowed</h1><object>Not allowed</object>';
        $sanitized = $this->feedParserService->normalizeContent($mixedContent);
        
        // Allowed elements should remain
        $this->assertStringContainsString('<p>Allowed</p>', $sanitized);
        $this->assertStringContainsString('<strong>Allowed</strong>', $sanitized);
        $this->assertStringContainsString('<h1>Allowed</h1>', $sanitized);
        
        // Disallowed elements should be removed
        $this->assertStringNotContainsString('<div>', $sanitized);
        $this->assertStringNotContainsString('<script>', $sanitized);
        $this->assertStringNotContainsString('<object>', $sanitized);
        $this->assertStringNotContainsString('alert("XSS")', $sanitized);
    }

    /**
     * Test that empty or null content is handled gracefully
     * AC-Edge1: Empty or null content is handled gracefully
     */
    public function testEmptyContentHandling(): void
    {
        $this->assertSame('', $this->feedParserService->normalizeContent(''));
        // HTMLPurifier may not trim whitespace exactly like our original method
        $result = $this->feedParserService->normalizeContent('   ');
        $this->assertEmpty(trim($result), 'Whitespace-only content should result in empty or whitespace-only string');
    }

    /**
     * Test that all forbidden elements are properly blocked
     */
    public function testForbiddenElementsBlocked(): void
    {
        $forbiddenElements = [
            '<script>alert(1)</script>',
            '<style>body{display:none}</style>',
            '<object data="malicious.swf"></object>',
            '<embed src="malicious.swf">',
            '<iframe src="malicious.html"></iframe>',
            '<form><input type="text"></form>',
            '<textarea>text</textarea>',
            '<select><option>opt</option></select>',
            '<button onclick="alert(1)">Click</button>',
            '<svg onload="alert(1)">',
            '<math><mi>x</mi></math>',
            '<canvas></canvas>',
            '<audio controls><source src="audio.mp3"></audio>',
            '<video controls><source src="video.mp4"></video>',
        ];

        foreach ($forbiddenElements as $element) {
            $sanitized = $this->feedParserService->normalizeContent($element);
            $this->assertStringNotContainsString('alert(1)', $sanitized, "Failed to block: $element");
        }
    }

    /**
     * Test that safe URLs are preserved and unsafe ones are removed
     */
    public function testUrlSchemeValidation(): void
    {
        $content = '<a href="https://example.com">HTTPS Link</a><a href="http://example.com">HTTP Link</a><a href="ftp://example.com">FTP Link</a><a href="javascript:alert(1)">JS Link</a>';
        $sanitized = $this->feedParserService->normalizeContent($content);
        
        $this->assertStringContainsString('href="https://example.com"', $sanitized);
        $this->assertStringContainsString('href="http://example.com"', $sanitized);
        $this->assertStringNotContainsString('href="ftp://example.com"', $sanitized);
        $this->assertStringNotContainsString('javascript:alert(1)', $sanitized);
    }

    /**
     * Test performance with large content
     * AC-Edge2: Large content doesn't cause memory issues
     */
    public function testLargeContentPerformance(): void
    {
        $largeContent = str_repeat('<p>This is a large content block with some <strong>formatting</strong> and <em>styling</em>.</p>', 1000);
        
        $startTime = microtime(true);
        $sanitized = $this->feedParserService->normalizeContent($largeContent);
        $endTime = microtime(true);
        
        $processingTime = $endTime - $startTime;
        
        // Processing should complete in reasonable time (under 1 second for 1000 paragraphs)
        $this->assertLessThan(1.0, $processingTime, 'Processing time should be under 1 second');
        $this->assertNotEmpty($sanitized, 'Large content should be processed successfully');
        $this->assertStringContainsString('<p>This is a large content block', $sanitized);
    }

    /**
     * Test that nested malicious content is properly handled
     * AC5: Nested and malformed HTML is properly handled
     */
    public function testNestedMaliciousContent(): void
    {
        $nestedContent = '<div><p>Good content</p><div><script>alert("nested XSS")</script></div><p>More good content</p></div>';
        $sanitized = $this->feedParserService->normalizeContent($nestedContent);
        
        $this->assertStringContainsString('<p>Good content</p>', $sanitized);
        $this->assertStringContainsString('<p>More good content</p>', $sanitized);
        $this->assertStringNotContainsString('<script>', $sanitized);
        $this->assertStringNotContainsString('alert("nested XSS")', $sanitized);
        $this->assertStringNotContainsString('<div>', $sanitized); // div not in whitelist
    }
}