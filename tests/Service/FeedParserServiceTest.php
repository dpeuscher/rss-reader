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
     * Test that script tags are completely removed from RSS content
     */
    public function testMaliciousScriptTagsRemoval(): void
    {
        $maliciousContent = '<script>alert("XSS")</script>';
        $sanitized = $this->feedParserService->normalizeContent($maliciousContent);
        
        $this->assertStringNotContainsString('<script>', $sanitized);
        $this->assertStringNotContainsString('alert', $sanitized);
        $this->assertStringNotContainsString('XSS', $sanitized);
    }

    /**
     * Test that event handler attributes are stripped from all HTML elements
     */
    public function testEventHandlerAttributesStripped(): void
    {
        $testCases = [
            '<img src="x" onerror="alert(\'XSS\')">' => 'Double quoted event handler',
            '<img src="x" onerror=\'alert("XSS")\' />' => 'Single quoted event handler',
            '<img src=x onerror=alert(1)>' => 'Unquoted event handler',
            '<img src="x" OnError="alert(1)">' => 'Mixed case event handler',
            '<div onmouseover="alert(1)">content</div>' => 'Div with mouseover event',
        ];

        foreach ($testCases as $input => $description) {
            $sanitized = $this->feedParserService->normalizeContent($input);
            
            $this->assertStringNotContainsString('onerror', strtolower($sanitized), "Failed for: {$description}");
            $this->assertStringNotContainsString('onmouseover', strtolower($sanitized), "Failed for: {$description}");
            $this->assertStringNotContainsString('alert', $sanitized, "Failed for: {$description}");
        }
    }

    /**
     * Test that JavaScript URLs are blocked in href and src attributes
     */
    public function testJavaScriptUrlsBlocked(): void
    {
        $testCases = [
            '<a href="javascript:alert(\'XSS\')">Click</a>' => 'JavaScript URL in href',
            '<a href="&#106;&#97;&#118;&#97;&#115;&#99;&#114;&#105;&#112;&#116;&#58;alert(1)">Click</a>' => 'HTML entity encoded JavaScript URL',
            '<img src="javascript:alert(1)" />' => 'JavaScript URL in img src',
            '<a href="vbscript:alert(1)">Click</a>' => 'VBScript URL',
            '<img src="data:text/html,<script>alert(1)</script>" />' => 'Data URL with script',
        ];

        foreach ($testCases as $input => $description) {
            $sanitized = $this->feedParserService->normalizeContent($input);
            
            $this->assertStringNotContainsString('javascript:', strtolower($sanitized), "Failed for: {$description}");
            $this->assertStringNotContainsString('vbscript:', strtolower($sanitized), "Failed for: {$description}");
            $this->assertStringNotContainsString('alert', $sanitized, "Failed for: {$description}");
        }
    }

    /**
     * Test that only whitelisted HTML elements are preserved
     */
    public function testOnlyWhitelistedElementsPreserved(): void
    {
        $allowedContent = '<p>Test <strong>bold</strong> and <em>italic</em> text with <a href="https://example.com" title="Example">link</a></p>';
        $sanitized = $this->feedParserService->normalizeContent($allowedContent);
        
        // These should be preserved
        $this->assertStringContainsString('<p>', $sanitized);
        $this->assertStringContainsString('<strong>', $sanitized);
        $this->assertStringContainsString('<em>', $sanitized);
        $this->assertStringContainsString('<a href="https://example.com"', $sanitized);
        $this->assertStringContainsString('title="Example"', $sanitized);
        
        // Test forbidden elements are removed
        $forbiddenContent = '<script>alert(1)</script><object>test</object><embed>test</embed><iframe>test</iframe>';
        $sanitized = $this->feedParserService->normalizeContent($forbiddenContent);
        
        $this->assertStringNotContainsString('<script>', $sanitized);
        $this->assertStringNotContainsString('<object>', $sanitized);
        $this->assertStringNotContainsString('<embed>', $sanitized);
        $this->assertStringNotContainsString('<iframe>', $sanitized);
    }

    /**
     * Test that nested and malformed HTML is properly handled
     */
    public function testNestedAndMalformedHtml(): void
    {
        $nestedMalicious = '<div><script>alert("XSS")</script></div>';
        $sanitized = $this->feedParserService->normalizeContent($nestedMalicious);
        
        $this->assertStringNotContainsString('<script>', $sanitized);
        $this->assertStringNotContainsString('alert', $sanitized);
        $this->assertStringNotContainsString('XSS', $sanitized);
    }

    /**
     * Test comprehensive XSS attack vectors from the issue
     */
    public function testComprehensiveXssAttackVectors(): void
    {
        $attackVectors = [
            '<img src="x" onerror=\'alert("XSS")\' />' => 'Single-quoted event handlers',
            '<img src=x onerror=alert(1)>' => 'Unquoted event handlers',
            '<a href="&#106;&#97;&#118;&#97;&#115;&#99;&#114;&#105;&#112;&#116;&#58;alert(1)">Click</a>' => 'HTML entity encoded URLs',
            '<img src="data:text/html,<script>alert(1)</script>" />' => 'Data URLs with scripts',
            '<a href="vbscript:alert(1)">Click</a>' => 'Alternative protocols',
            '<img src="x" OnError="alert(1)" />' => 'Mixed case bypasses',
            '<div><script><!--</script>alert(1)--></div>' => 'Complex nested attacks',
            '<svg onload="alert(1)"></svg>' => 'SVG with script',
            '<input onfocus="alert(1)" autofocus />' => 'Form with autofocus',
            '<div style="background:url(javascript:alert(1))">test</div>' => 'CSS expression injection',
        ];

        foreach ($attackVectors as $attack => $description) {
            $sanitized = $this->feedParserService->normalizeContent($attack);
            
            $this->assertStringNotContainsString('alert', $sanitized, "XSS bypass detected for: {$description}");
            $this->assertStringNotContainsString('javascript:', strtolower($sanitized), "JavaScript URL not blocked for: {$description}");
            $this->assertStringNotContainsString('vbscript:', strtolower($sanitized), "VBScript URL not blocked for: {$description}");
            $this->assertStringNotContainsString('<script>', strtolower($sanitized), "Script tag not removed for: {$description}");
        }
    }

    /**
     * Test edge cases and error handling
     */
    public function testEdgeCasesAndErrorHandling(): void
    {
        // Empty content
        $this->assertSame('', $this->feedParserService->normalizeContent(''));
        
        // Empty string content
        $this->assertSame('', $this->feedParserService->normalizeContent(''));
        
        // Very large content should not cause issues
        $largeContent = str_repeat('<p>Test content with safe HTML</p>', 1000);
        $sanitized = $this->feedParserService->normalizeContent($largeContent);
        $this->assertStringContainsString('<p>Test content with safe HTML</p>', $sanitized);
        
        // Invalid HTML structure
        $invalidHtml = '<p><div><span>Nested invalid structure</div></span></p>';
        $sanitized = $this->feedParserService->normalizeContent($invalidHtml);
        $this->assertStringNotContainsString('<div>', $sanitized); // div not in whitelist
    }

    /**
     * Test that legitimate content is preserved correctly
     */
    public function testLegitimateContentPreservation(): void
    {
        $legitimateContent = '
            <h1>Article Title</h1>
            <p>This is a paragraph with <strong>bold</strong> and <em>italic</em> text.</p>
            <ul>
                <li>List item 1</li>
                <li>List item 2 with <a href="https://example.com" title="Example">link</a></li>
            </ul>
            <blockquote>This is a quote</blockquote>
            <pre><code>code block</code></pre>
            <img src="https://example.com/image.jpg" alt="Description" width="100" height="100" />
        ';
        
        $sanitized = $this->feedParserService->normalizeContent($legitimateContent);
        
        // All these should be preserved
        $this->assertStringContainsString('<h1>Article Title</h1>', $sanitized);
        $this->assertStringContainsString('<p>', $sanitized);
        $this->assertStringContainsString('<strong>bold</strong>', $sanitized);
        $this->assertStringContainsString('<em>italic</em>', $sanitized);
        $this->assertStringContainsString('<ul>', $sanitized);
        $this->assertStringContainsString('<li>', $sanitized);
        $this->assertStringContainsString('<a href="https://example.com"', $sanitized);
        $this->assertStringContainsString('title="Example"', $sanitized);
        $this->assertStringContainsString('<blockquote>', $sanitized);
        $this->assertStringContainsString('<pre><code>', $sanitized);
        $this->assertStringContainsString('<img src="https://example.com/image.jpg"', $sanitized);
        $this->assertStringContainsString('alt="Description"', $sanitized);
        $this->assertStringContainsString('width="100"', $sanitized);
        $this->assertStringContainsString('height="100"', $sanitized);
    }

    /**
     * Test URL scheme validation
     */
    public function testUrlSchemeValidation(): void
    {
        $testCases = [
            '<a href="https://example.com">Safe HTTPS link</a>' => true,
            '<a href="http://example.com">Safe HTTP link</a>' => true,
            '<img src="https://example.com/image.jpg" alt="Safe image" />' => true,
            '<a href="ftp://example.com">FTP link</a>' => false,
            '<a href="file:///etc/passwd">File link</a>' => false,
            '<img src="data:image/png;base64,..." alt="Data image" />' => false,
        ];

        foreach ($testCases as $input => $shouldPreserveUrl) {
            $sanitized = $this->feedParserService->normalizeContent($input);
            
            if ($shouldPreserveUrl) {
                $this->assertStringContainsString('href=', $sanitized, "Safe URL should be preserved: {$input}");
            } else {
                // HTMLPurifier should remove the dangerous href/src attributes
                $this->assertStringNotContainsString('ftp:', $sanitized, "Dangerous URL should be removed: {$input}");
                $this->assertStringNotContainsString('file:', $sanitized, "Dangerous URL should be removed: {$input}");
                $this->assertStringNotContainsString('data:', $sanitized, "Data URL should be removed: {$input}");
            }
        }
    }
}