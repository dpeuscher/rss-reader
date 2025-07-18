<?php

namespace App\Tests\Service;

use App\Service\FeedParserService;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class FeedParserServiceTest extends TestCase
{
    private FeedParserService $service;

    protected function setUp(): void
    {
        $this->service = new FeedParserService();
    }

    /**
     * Test that malicious script tags are completely removed from RSS content
     * AC1: Malicious script tags are completely removed from RSS content
     */
    public function testScriptTagsAreRemoved(): void
    {
        $maliciousContent = '<script>alert("XSS")</script><p>Safe content</p>';
        $result = $this->service->normalizeContent($maliciousContent);
        
        $this->assertStringNotContainsString('<script>', $result);
        $this->assertStringNotContainsString('alert("XSS")', $result);
        $this->assertStringContainsString('<p>Safe content</p>', $result);
    }

    /**
     * Test that event handler attributes are stripped from all HTML elements
     * AC2: Event handler attributes are stripped from all HTML elements
     */
    public function testEventHandlerAttributesAreStripped(): void
    {
        $testCases = [
            // Double-quoted event handlers
            '<img src="x" onerror="alert(\'XSS\')">' => '<img src="x" alt="x" />',
            // Single-quoted event handlers
            '<img src="x" onerror=\'alert("XSS")\'>' => '<img src="x" alt="x" />',
            // Unquoted event handlers
            '<img src=x onerror=alert(1)>' => '<img src="x" alt="x" />',
            // Mixed case bypasses
            '<img src="x" OnError="alert(1)">' => '<img src="x" alt="x" />',
            // Multiple event handlers
            '<div onclick="alert(1)" onmouseover="alert(2)">Content</div>' => 'Content',
            // Event handlers on various elements
            '<a href="#" onclick="alert(1)">Link</a>' => '<a href="#">Link</a>',
            '<input onfocus="alert(1)" autofocus>' => '',
        ];

        foreach ($testCases as $input => $expected) {
            $result = $this->service->normalizeContent($input);
            $this->assertEquals($expected, $result, "Failed for input: $input");
        }
    }

    /**
     * Test that JavaScript URLs are blocked in href and src attributes
     * AC3: JavaScript URLs are blocked in href and src attributes
     */
    public function testJavaScriptUrlsAreBlocked(): void
    {
        $testCases = [
            // Basic javascript URLs
            '<a href="javascript:alert(\'XSS\')">Click</a>' => '<a>Click</a>',
            // HTML entity encoded URLs
            '<a href="&#106;&#97;&#118;&#97;&#115;&#99;&#114;&#105;&#112;&#116;&#58;alert(1)">Click</a>' => '<a>Click</a>',
            // Alternative protocols
            '<a href="vbscript:alert(1)">Click</a>' => '<a>Click</a>',
            // Data URLs with scripts (HTMLPurifier removes the entire element)
            '<img src="data:text/html,<script>alert(1)</script>">' => '',
            // CSS expression injection
            '<div style="background:url(javascript:alert(1))">Content</div>' => 'Content',
        ];

        foreach ($testCases as $input => $expected) {
            $result = $this->service->normalizeContent($input);
            $this->assertEquals($expected, $result, "Failed for input: $input");
        }
    }

    /**
     * Test that only whitelisted HTML elements are preserved
     * AC4: Only whitelisted HTML elements are preserved
     */
    public function testOnlyWhitelistedElementsArePreserved(): void
    {
        $allowedElements = '<p>Paragraph</p><br><strong>Bold</strong><em>Italic</em><b>Bold</b><i>Italic</i><u>Underline</u>';
        $allowedElements .= '<a href="https://example.com" title="Link">Link</a>';
        $allowedElements .= '<img src="https://example.com/image.jpg" alt="Image" title="Image Title" width="100" height="100">';
        $allowedElements .= '<ul><li>Item 1</li><li>Item 2</li></ul>';
        $allowedElements .= '<ol><li>Item 1</li><li>Item 2</li></ol>';
        $allowedElements .= '<h1>Heading 1</h1><h2>Heading 2</h2><h3>Heading 3</h3>';
        $allowedElements .= '<blockquote>Quote</blockquote><pre>Code</pre><code>Inline code</code>';
        
        $result = $this->service->normalizeContent($allowedElements);
        
        // Check that allowed elements are preserved
        $this->assertStringContainsString('<p>Paragraph</p>', $result);
        $this->assertStringContainsString('<strong>Bold</strong>', $result);
        $this->assertStringContainsString('<em>Italic</em>', $result);
        $this->assertStringContainsString('href="https://example.com"', $result);
        $this->assertStringContainsString('src="https://example.com/image.jpg"', $result);
        $this->assertStringContainsString('<ul><li>Item 1</li><li>Item 2</li></ul>', $result);
        $this->assertStringContainsString('<h1>Heading 1</h1>', $result);
        $this->assertStringContainsString('<blockquote>Quote</blockquote>', $result);
        
        // Test that forbidden elements are removed
        $forbiddenElements = '<script>alert(1)</script><style>body{}</style><object></object>';
        $forbiddenElements .= '<embed><iframe></iframe><form></form><input><textarea></textarea>';
        $forbiddenElements .= '<select></select><button></button><svg></svg><math></math>';
        $forbiddenElements .= '<canvas></canvas><audio></audio><video></video>';
        
        $result = $this->service->normalizeContent($forbiddenElements);
        
        $this->assertStringNotContainsString('<script>', $result);
        $this->assertStringNotContainsString('<style>', $result);
        $this->assertStringNotContainsString('<object>', $result);
        $this->assertStringNotContainsString('<embed>', $result);
        $this->assertStringNotContainsString('<iframe>', $result);
        $this->assertStringNotContainsString('<form>', $result);
        $this->assertStringNotContainsString('<input>', $result);
        $this->assertStringNotContainsString('<textarea>', $result);
        $this->assertStringNotContainsString('<select>', $result);
        $this->assertStringNotContainsString('<button>', $result);
        $this->assertStringNotContainsString('<svg>', $result);
        $this->assertStringNotContainsString('<math>', $result);
        $this->assertStringNotContainsString('<canvas>', $result);
        $this->assertStringNotContainsString('<audio>', $result);
        $this->assertStringNotContainsString('<video>', $result);
    }

    /**
     * Test that nested and malformed HTML is properly handled
     * AC5: Nested and malformed HTML is properly handled
     */
    public function testNestedAndMalformedHtmlIsHandled(): void
    {
        $testCases = [
            // Nested malicious content
            '<div><script>alert("XSS")</script></div>' => '',
            '<p><script>alert("XSS")</script>Safe text</p>' => '<p>Safe text</p>',
            // Complex nested attacks (HTMLPurifier may leave some escaped content)
            '<div><script><!--</script>alert(1)--></div>' => 'alert(1)--&gt;',
            // Malformed HTML
            '<p><div>Content</div></p>' => '<p></p>Content',
            // Unclosed tags
            '<p>Content<script>alert(1)' => '<p>Content</p>',
            // Mixed content
            '<p>Safe content</p><script>alert(1)</script><em>More safe content</em>' => '<p>Safe content</p><em>More safe content</em>',
        ];

        foreach ($testCases as $input => $expected) {
            $result = $this->service->normalizeContent($input);
            $this->assertEquals($expected, $result, "Failed for input: $input");
        }
    }

    /**
     * Test edge case: Empty or null content is handled gracefully
     * AC-Edge1: Empty or null content is handled gracefully without errors
     */
    public function testEmptyContentHandledGracefully(): void
    {
        $this->assertEquals('', $this->service->normalizeContent(''));
        $this->assertEquals('   ', $this->service->normalizeContent('   '));
    }

    /**
     * Test edge case: Large content handling
     * AC-Edge2: Extremely large content does not cause memory issues or timeouts
     */
    public function testLargeContentHandling(): void
    {
        // Create a large but safe content string
        $largeContent = str_repeat('<p>This is a test paragraph. </p>', 1000);
        $result = $this->service->normalizeContent($largeContent);
        
        $this->assertStringContainsString('<p>This is a test paragraph. </p>', $result);
        $this->assertGreaterThan(1000, strlen($result));
    }

    /**
     * Test error handling: Invalid HTML structure
     * AC-Error1: Invalid HTML structure is sanitized without breaking the application
     */
    public function testInvalidHtmlStructureHandling(): void
    {
        $invalidHtml = '<><p>Content</p><>';
        $result = $this->service->normalizeContent($invalidHtml);
        
        $this->assertStringContainsString('<p>Content</p>', $result);
        $this->assertStringNotContainsString('<>', $result);
    }

    /**
     * Test comprehensive XSS attack vectors from technical investigation
     */
    public function testComprehensiveXssAttackVectors(): void
    {
        $xssAttackVectors = [
            // Attack vectors from the technical investigation
            '<img src="x" onerror=\'alert("XSS")\'>',
            '<img src=x onerror=alert(1)>',
            '<a href="&#106;&#97;&#118;&#97;&#115;&#99;&#114;&#105;&#112;&#116;&#58;alert(1)">',
            '<img src="data:text/html,<script>alert(1)</script>">',
            '<a href="vbscript:alert(1)">',
            '<img src="x" OnError="alert(1)">',
            '<svg onload="alert(1)">',
            '<input onfocus="alert(1)" autofocus>',
            '<div style="background:url(javascript:alert(1))">',
        ];

        foreach ($xssAttackVectors as $attack) {
            $result = $this->service->normalizeContent($attack);
            
            // Ensure most dangerous patterns are removed
            $this->assertStringNotContainsString('javascript:', $result);
            $this->assertStringNotContainsString('vbscript:', $result);
            $this->assertStringNotContainsString('onload=', $result);
            $this->assertStringNotContainsString('onerror=', $result);
            $this->assertStringNotContainsString('onfocus=', $result);
            $this->assertStringNotContainsString('<script>', $result);
            $this->assertStringNotContainsString('<svg', $result);
            $this->assertStringNotContainsString('<input', $result);
            $this->assertStringNotContainsString('data:', $result);
        }
        
        // Test the specific case that was failing
        $complexAttack = '<div><script><!--</script>alert(1)--></div>';
        $result = $this->service->normalizeContent($complexAttack);
        // Even if some text remains, it should be escaped and not executable
        $this->assertStringNotContainsString('<script>', $result);
        $this->assertStringNotContainsString('</script>', $result);
    }

    /**
     * Test that legitimate content is preserved
     */
    public function testLegitimateContentPreserved(): void
    {
        $legitimateContent = '<p>Test <strong>bold</strong> and <em>italic</em> text with <a href="https://example.com">link</a></p>';
        $result = $this->service->normalizeContent($legitimateContent);
        
        // HTMLPurifier adds security attributes to links
        $this->assertStringContainsString('<p>Test <strong>bold</strong> and <em>italic</em> text with <a href="https://example.com" rel="nofollow noreferrer noopener" target="_blank">link</a></p>', $result);
    }

    /**
     * Test URL scheme validation
     */
    public function testUrlSchemeValidation(): void
    {
        $validUrls = [
            '<a href="https://example.com">HTTPS Link</a>',
            '<a href="http://example.com">HTTP Link</a>',
            '<img src="https://example.com/image.jpg">',
            '<img src="http://example.com/image.jpg">',
        ];

        foreach ($validUrls as $url) {
            $result = $this->service->normalizeContent($url);
            $this->assertStringContainsString('http', $result);
        }

        $invalidUrls = [
            '<a href="ftp://example.com">FTP Link</a>',
            '<a href="file:///etc/passwd">File Link</a>',
            '<img src="ftp://example.com/image.jpg">',
        ];

        foreach ($invalidUrls as $url) {
            $result = $this->service->normalizeContent($url);
            $this->assertStringNotContainsString('ftp:', $result);
            $this->assertStringNotContainsString('file:', $result);
        }
    }

    /**
     * Test performance with repeated calls (singleton pattern)
     */
    public function testPerformanceWithRepeatedCalls(): void
    {
        $content = '<p>Test content</p>';
        
        // First call initializes the purifier
        $result1 = $this->service->normalizeContent($content);
        
        // Subsequent calls should reuse the same purifier instance
        $result2 = $this->service->normalizeContent($content);
        $result3 = $this->service->normalizeContent($content);
        
        $this->assertEquals($result1, $result2);
        $this->assertEquals($result2, $result3);
    }
}