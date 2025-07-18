<?php

namespace App\Tests\Service;

use App\Service\FeedParserService;
use PHPUnit\Framework\TestCase;

class FeedParserServiceTest extends TestCase
{
    private FeedParserService $feedParserService;

    protected function setUp(): void
    {
        $this->feedParserService = new FeedParserService();
    }

    /**
     * Test that malicious script tags are completely removed
     * AC1: Script tags and content should be completely removed
     */
    public function testMaliciousScriptTagsRemoved(): void
    {
        $maliciousContent = '<script>alert("XSS")</script><p>Safe content</p>';
        $result = $this->feedParserService->normalizeContent($maliciousContent);
        
        $this->assertStringNotContainsString('<script>', $result);
        $this->assertStringNotContainsString('alert("XSS")', $result);
        $this->assertStringNotContainsString('</script>', $result);
        $this->assertStringContainsString('<p>Safe content</p>', $result);
    }

    /**
     * Test that event handler attributes are stripped
     * AC2: Event handler attributes should be removed
     */
    public function testEventHandlerAttributesStripped(): void
    {
        $testCases = [
            // Double-quoted event handlers
            '<img src="test.jpg" onerror="alert(\'XSS\')">' => '<img src="test.jpg" alt="" />',
            '<div onclick="alert(\'XSS\')">Content</div>' => 'Content',
            // Single-quoted event handlers
            '<img src="test.jpg" onerror=\'alert("XSS")\'>' => '<img src="test.jpg" alt="" />',
            // Unquoted event handlers
            '<img src=test.jpg onerror=alert(1)>' => '<img src="test.jpg" alt="" />',
            // Mixed case
            '<img src="test.jpg" OnError="alert(1)">' => '<img src="test.jpg" alt="" />',
        ];

        foreach ($testCases as $input => $expected) {
            $result = $this->feedParserService->normalizeContent($input);
            $this->assertStringNotContainsString('onerror', strtolower($result));
            $this->assertStringNotContainsString('onclick', strtolower($result));
            $this->assertStringNotContainsString('alert', $result);
        }
    }

    /**
     * Test that JavaScript URLs are blocked
     * AC3: JavaScript URLs should be blocked in href and src attributes
     */
    public function testJavaScriptUrlsBlocked(): void
    {
        $testCases = [
            '<a href="javascript:alert(\'XSS\')">Click</a>',
            '<a href="JAVASCRIPT:alert(\'XSS\')">Click</a>',
            '<a href="&#106;&#97;&#118;&#97;&#115;&#99;&#114;&#105;&#112;&#116;&#58;alert(1)">Click</a>',
            '<img src="javascript:alert(1)">',
            '<a href="vbscript:alert(1)">Click</a>',
        ];

        foreach ($testCases as $input) {
            $result = $this->feedParserService->normalizeContent($input);
            $this->assertStringNotContainsString('javascript:', strtolower($result));
            $this->assertStringNotContainsString('vbscript:', strtolower($result));
            $this->assertStringNotContainsString('alert', $result);
        }
    }

    /**
     * Test that only whitelisted HTML elements are preserved
     * AC4: Only whitelisted elements should remain
     */
    public function testOnlyWhitelistedElementsPreserved(): void
    {
        $input = '<p>Paragraph</p><strong>Bold</strong><em>Italic</em><a href="https://example.com">Link</a><img src="https://example.com/image.jpg" alt="Image"><script>alert("XSS")</script><object>Object</object><embed>Embed</embed><iframe>Frame</iframe>';
        
        $result = $this->feedParserService->normalizeContent($input);
        
        // Should preserve whitelisted elements
        $this->assertStringContainsString('<p>Paragraph</p>', $result);
        $this->assertStringContainsString('<strong>Bold</strong>', $result);
        $this->assertStringContainsString('<em>Italic</em>', $result);
        $this->assertStringContainsString('href="https://example.com"', $result);
        $this->assertStringContainsString('src="https://example.com/image.jpg"', $result);
        
        // Should remove forbidden elements
        $this->assertStringNotContainsString('<script>', $result);
        $this->assertStringNotContainsString('<object>', $result);
        $this->assertStringNotContainsString('<embed>', $result);
        $this->assertStringNotContainsString('<iframe>', $result);
    }

    /**
     * Test that nested and malformed HTML is properly handled
     * AC5: Nested malicious content should be removed
     */
    public function testNestedMaliciousContentHandled(): void
    {
        $testCases = [
            '<div><script>alert("XSS")</script></div>' => '',
            '<p><script><!--</script>alert(1)--></p>' => '<p>alert(1)--&gt;</p>',
            '<strong><script>alert("XSS")</script>Bold text</strong>' => '<strong>Bold text</strong>',
            '<img src="test.jpg"><script>alert("XSS")</script>' => '<img src="test.jpg" alt="" />',
        ];

        foreach ($testCases as $input => $expected) {
            $result = $this->feedParserService->normalizeContent($input);
            $this->assertStringNotContainsString('<script>', $result);
            $this->assertStringNotContainsString('</script>', $result);
            // Script tags should be removed, but non-script text content like "alert" is safe
            $this->assertStringNotContainsString('alert("XSS")', $result);
            $this->assertStringNotContainsString('alert(\'XSS\')', $result);
        }
    }

    /**
     * Test that dangerous protocols are blocked
     */
    public function testDangerousProtocolsBlocked(): void
    {
        $testCases = [
            '<a href="data:text/html,<script>alert(1)</script>">Click</a>',
            '<img src="data:text/html,<script>alert(1)</script>">',
            '<a href="ftp://example.com">FTP Link</a>',
            '<a href="file:///etc/passwd">File Link</a>',
        ];

        foreach ($testCases as $input) {
            $result = $this->feedParserService->normalizeContent($input);
            $this->assertStringNotContainsString('data:', $result);
            $this->assertStringNotContainsString('ftp:', $result);
            $this->assertStringNotContainsString('file:', $result);
            $this->assertStringNotContainsString('alert', $result);
        }
    }

    /**
     * Test that SVG with scripts is blocked
     */
    public function testSvgWithScriptsBlocked(): void
    {
        $input = '<svg onload="alert(1)"><script>alert("XSS")</script></svg>';
        $result = $this->feedParserService->normalizeContent($input);
        
        $this->assertStringNotContainsString('<svg>', $result);
        $this->assertStringNotContainsString('onload', $result);
        $this->assertStringNotContainsString('alert', $result);
    }

    /**
     * Test that CSS expressions are blocked
     */
    public function testCssExpressionsBlocked(): void
    {
        $input = '<div style="background:url(javascript:alert(1))">Content</div>';
        $result = $this->feedParserService->normalizeContent($input);
        
        $this->assertStringNotContainsString('javascript:', $result);
        $this->assertStringNotContainsString('alert', $result);
        $this->assertStringNotContainsString('style=', $result);
    }

    /**
     * Test that legitimate content is preserved
     */
    public function testLegitimateContentPreserved(): void
    {
        $input = '<p>This is <strong>bold</strong> and <em>italic</em> text with a <a href="https://example.com" title="Example">link</a> and an <img src="https://example.com/image.jpg" alt="Image" width="100" height="100">.</p>';
        
        $result = $this->feedParserService->normalizeContent($input);
        
        $this->assertStringContainsString('<p>This is <strong>bold</strong> and <em>italic</em> text', $result);
        $this->assertStringContainsString('href="https://example.com"', $result);
        $this->assertStringContainsString('title="Example"', $result);
        $this->assertStringContainsString('src="https://example.com/image.jpg"', $result);
        $this->assertStringContainsString('alt="Image"', $result);
        $this->assertStringContainsString('width="100"', $result);
        $this->assertStringContainsString('height="100"', $result);
    }

    /**
     * Test edge cases and error handling
     */
    public function testEdgeCasesAndErrorHandling(): void
    {
        // Empty content
        $result = $this->feedParserService->normalizeContent('');
        $this->assertSame('', $result);
        
        // Only whitespace
        $result = $this->feedParserService->normalizeContent('   ');
        $this->assertSame('   ', $result); // HTMLPurifier preserves whitespace
        
        // Malformed HTML
        $result = $this->feedParserService->normalizeContent('<p>Unclosed paragraph<script>alert(1)</script>');
        $this->assertStringNotContainsString('<script>', $result);
        $this->assertStringNotContainsString('alert(1)', $result);
        $this->assertStringContainsString('Unclosed paragraph', $result);
        
        // Very long content should not cause issues
        $longContent = str_repeat('<p>Safe content. </p>', 1000);
        $result = $this->feedParserService->normalizeContent($longContent);
        $this->assertStringContainsString('<p>Safe content. </p>', $result);
    }

    /**
     * Test all forbidden elements are removed
     */
    public function testForbiddenElementsRemoved(): void
    {
        $forbiddenElements = [
            'script', 'style', 'object', 'embed', 'iframe', 'form', 
            'input', 'textarea', 'select', 'button', 'svg', 'math', 
            'canvas', 'audio', 'video', 'source', 'track'
        ];
        
        foreach ($forbiddenElements as $element) {
            $input = "<{$element}>Content</{$element}>";
            $result = $this->feedParserService->normalizeContent($input);
            $this->assertStringNotContainsString("<{$element}>", $result, "Element {$element} should be removed");
        }
    }

    /**
     * Test that safe URLs are preserved
     */
    public function testSafeUrlsPreserved(): void
    {
        $safeUrls = [
            'https://example.com',
            'http://example.com',
            'https://subdomain.example.com/path?query=value',
            'http://localhost:8080/test',
        ];
        
        foreach ($safeUrls as $url) {
            $input = "<a href=\"{$url}\">Link</a>";
            $result = $this->feedParserService->normalizeContent($input);
            $this->assertStringContainsString("href=\"{$url}\"", $result, "Safe URL {$url} should be preserved");
        }
    }
}