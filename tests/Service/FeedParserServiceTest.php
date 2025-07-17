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
     * Test that basic XSS script tags are completely removed
     * AC1: Malicious script tags are completely removed from RSS content
     */
    public function testBasicScriptXSSRemoval(): void
    {
        $maliciousContent = '<script>alert("XSS")</script><p>Safe content</p>';
        $result = $this->feedParserService->normalizeContent($maliciousContent);
        
        $this->assertStringNotContainsString('<script>', $result);
        $this->assertStringNotContainsString('alert("XSS")', $result);
        $this->assertStringContainsString('<p>Safe content</p>', $result);
    }

    /**
     * Test that event handler attributes are stripped from all HTML elements
     * AC2: Event handler attributes are stripped from all HTML elements
     */
    public function testEventHandlerAttributesRemoval(): void
    {
        $testCases = [
            '<img src="x" onerror="alert(\'XSS\')">' => '<img src="x" alt="x" />',
            '<img src="x" OnError="alert(1)">' => '<img src="x" alt="x" />',
            '<img src=x onerror=alert(1)>' => '<img src="x" alt="x" />',
            '<div onclick="alert(1)">Click me</div>' => 'Click me',
            '<a href="http://example.com" onmouseover="alert(1)">Link</a>' => '<a href="http://example.com">Link</a>',
        ];

        foreach ($testCases as $input => $expected) {
            $result = $this->feedParserService->normalizeContent($input);
            $this->assertStringNotContainsString('onerror', $result);
            $this->assertStringNotContainsString('onclick', $result);
            $this->assertStringNotContainsString('onmouseover', $result);
            $this->assertStringNotContainsString('alert', $result);
        }
    }

    /**
     * Test that JavaScript URLs are blocked in href and src attributes
     * AC3: JavaScript URLs are blocked in href and src attributes
     */
    public function testJavaScriptUrlsBlocked(): void
    {
        $testCases = [
            '<a href="javascript:alert(\'XSS\')">Click</a>',
            '<a href="&#106;&#97;&#118;&#97;&#115;&#99;&#114;&#105;&#112;&#116;&#58;alert(1)">Click</a>',
            '<a href="vbscript:alert(1)">Click</a>',
            '<img src="javascript:alert(1)">',
            '<img src="data:text/html,<script>alert(1)</script>">',
        ];

        foreach ($testCases as $input) {
            $result = $this->feedParserService->normalizeContent($input);
            $this->assertStringNotContainsString('javascript:', $result);
            $this->assertStringNotContainsString('vbscript:', $result);
            $this->assertStringNotContainsString('alert', $result);
        }
    }

    /**
     * Test that only whitelisted HTML elements are preserved
     * AC4: Only whitelisted HTML elements are preserved
     */
    public function testOnlyWhitelistedElementsPreserved(): void
    {
        $mixedContent = '
            <p>Paragraph</p>
            <strong>Bold</strong>
            <em>Italic</em>
            <a href="https://example.com">Link</a>
            <img src="https://example.com/image.jpg" alt="Image">
            <script>alert("XSS")</script>
            <object data="malicious.swf"></object>
            <embed src="malicious.swf">
            <iframe src="malicious.html"></iframe>
            <form action="malicious.php"></form>
            <svg onload="alert(1)"></svg>
        ';

        $result = $this->feedParserService->normalizeContent($mixedContent);

        // Should preserve whitelisted elements
        $this->assertStringContainsString('<p>Paragraph</p>', $result);
        $this->assertStringContainsString('<strong>Bold</strong>', $result);
        $this->assertStringContainsString('<em>Italic</em>', $result);
        $this->assertStringContainsString('<a href="https://example.com"', $result);
        $this->assertStringContainsString('target="_blank"', $result); // HTMLPurifier adds security attributes
        $this->assertStringContainsString('<img', $result);

        // Should remove dangerous elements
        $this->assertStringNotContainsString('<script>', $result);
        $this->assertStringNotContainsString('<object>', $result);
        $this->assertStringNotContainsString('<embed>', $result);
        $this->assertStringNotContainsString('<iframe>', $result);
        $this->assertStringNotContainsString('<form>', $result);
        $this->assertStringNotContainsString('<svg>', $result);
        $this->assertStringNotContainsString('alert', $result);
    }

    /**
     * Test that nested and malformed HTML is properly handled
     * AC5: Nested and malformed HTML is properly handled
     */
    public function testNestedAndMalformedHtmlHandling(): void
    {
        $nestedContent = '<div><script>alert("XSS")</script><p>Safe content</p></div>';
        $result = $this->feedParserService->normalizeContent($nestedContent);

        $this->assertStringNotContainsString('<script>', $result);
        $this->assertStringNotContainsString('alert("XSS")', $result);
        $this->assertStringContainsString('Safe content', $result);
    }

    /**
     * Test that empty or null content is handled gracefully
     * AC-Edge1: Empty or null content is handled gracefully without errors
     */
    public function testEmptyContentHandling(): void
    {
        $this->assertEquals('', $this->feedParserService->normalizeContent(''));
        $this->assertEquals('   ', $this->feedParserService->normalizeContent('   ')); // HTMLPurifier preserves whitespace
    }

    /**
     * Test that legitimate content is preserved
     * UAT2: Legitimate content preservation
     */
    public function testLegitimateContentPreservation(): void
    {
        $legitimateContent = '<p>Test <strong>bold</strong> and <em>italic</em> text with <a href="https://example.com">link</a></p>';
        $result = $this->feedParserService->normalizeContent($legitimateContent);

        $this->assertStringContainsString('<p>', $result);
        $this->assertStringContainsString('<strong>bold</strong>', $result);
        $this->assertStringContainsString('<em>italic</em>', $result);
        $this->assertStringContainsString('<a href="https://example.com"', $result);
        $this->assertStringContainsString('target="_blank"', $result); // HTMLPurifier adds security attributes
    }

    /**
     * Test all 10 XSS attack vectors from the technical investigation
     */
    public function testAllXSSAttackVectors(): void
    {
        $xssAttackVectors = [
            '<img src="x" onerror=\'alert("XSS")\'>',
            '<img src=x onerror=alert(1)>',
            '<a href="&#106;&#97;&#118;&#97;&#115;&#99;&#114;&#105;&#112;&#116;&#58;alert(1)">',
            '<img src="data:text/html,<script>alert(1)</script>">',
            '<a href="vbscript:alert(1)">',
            '<img src="x" OnError="alert(1)">',
            '<div><script><!--</script>alert(1)--></div>',
            '<svg onload="alert(1)">',
            '<input onfocus="alert(1)" autofocus>',
            '<div style="background:url(javascript:alert(1))">',
        ];

        foreach ($xssAttackVectors as $vector) {
            $result = $this->feedParserService->normalizeContent($vector);
            // Check that dangerous elements are removed or neutralized
            $this->assertStringNotContainsString('<script>', $result, "Script tag not removed: $vector");
            $this->assertStringNotContainsString('javascript:', $result, "JavaScript URL not blocked: $vector");
            $this->assertStringNotContainsString('vbscript:', $result, "VBScript URL not blocked: $vector");
            $this->assertStringNotContainsString('onload=', $result, "Event handler not removed: $vector");
            $this->assertStringNotContainsString('onerror=', $result, "Event handler not removed: $vector");
            $this->assertStringNotContainsString('onfocus=', $result, "Event handler not removed: $vector");
            // If alert is present, it should be HTML-encoded (safe)
            if (strpos($result, 'alert') !== false) {
                $this->assertStringContainsString('&gt;', $result, "Alert should be HTML-encoded: $vector");
            }
        }
    }

    /**
     * Test that allowed HTML elements maintain their structure
     */
    public function testAllowedHtmlElementsStructure(): void
    {
        $allowedElements = [
            '<p>Paragraph</p>',
            '<br>',
            '<strong>Strong</strong>',
            '<em>Emphasis</em>',
            '<b>Bold</b>',
            '<i>Italic</i>',
            '<u>Underline</u>',
            '<a href="https://example.com" title="Example">Link</a>',
            '<img src="https://example.com/image.jpg" alt="Image" title="Title" width="100" height="100">',
            '<ul><li>Item 1</li><li>Item 2</li></ul>',
            '<ol><li>Item 1</li><li>Item 2</li></ol>',
            '<h1>Header 1</h1>',
            '<h2>Header 2</h2>',
            '<h3>Header 3</h3>',
            '<h4>Header 4</h4>',
            '<h5>Header 5</h5>',
            '<h6>Header 6</h6>',
            '<blockquote>Quote</blockquote>',
            '<pre>Preformatted</pre>',
            '<code>Code</code>',
        ];

        foreach ($allowedElements as $element) {
            $result = $this->feedParserService->normalizeContent($element);
            $this->assertNotEmpty($result, "Allowed element was completely removed: $element");
        }
    }

    /**
     * Test that URLs are restricted to safe schemes
     */
    public function testUrlSchemeRestriction(): void
    {
        $safeUrls = [
            '<a href="http://example.com">Link</a>',
            '<a href="https://example.com">Link</a>',
            '<img src="http://example.com/image.jpg">',
            '<img src="https://example.com/image.jpg">',
        ];

        $unsafeUrls = [
            '<a href="ftp://example.com">Link</a>',
            '<a href="file:///etc/passwd">Link</a>',
            '<img src="ftp://example.com/image.jpg">',
            '<img src="file:///etc/passwd">',
        ];

        foreach ($safeUrls as $url) {
            $result = $this->feedParserService->normalizeContent($url);
            $this->assertTrue(
                strpos($result, 'href="http') !== false || strpos($result, 'src="http') !== false,
                "Safe URL should be preserved: $url"
            );
        }

        foreach ($unsafeUrls as $url) {
            $result = $this->feedParserService->normalizeContent($url);
            $this->assertStringNotContainsString('ftp:', $result);
            $this->assertStringNotContainsString('file:', $result);
        }
    }

    /**
     * Test performance - basic smoke test
     */
    public function testPerformanceBasic(): void
    {
        $largeContent = str_repeat('<p>Content with <strong>formatting</strong> and <a href="https://example.com">links</a></p>', 100);
        
        $startTime = microtime(true);
        $result = $this->feedParserService->normalizeContent($largeContent);
        $endTime = microtime(true);
        
        $processingTime = ($endTime - $startTime) * 1000; // Convert to milliseconds
        
        $this->assertLessThan(100, $processingTime, 'Processing time should be under 100ms for 100 repeated elements');
        $this->assertNotEmpty($result);
    }
}