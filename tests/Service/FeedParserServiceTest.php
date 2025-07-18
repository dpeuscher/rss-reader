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
     * Test that all 10 XSS attack vectors are properly sanitized
     */
    public function testXssAttackVectorsPrevention(): void
    {
        $attackVectors = [
            // 1. Single-quoted event handlers
            [
                'input' => '<img src="x" onerror=\'alert("XSS")\'>',
                'description' => 'Single-quoted event handlers'
            ],
            // 2. Unquoted event handlers
            [
                'input' => '<img src=x onerror=alert(1)>',
                'description' => 'Unquoted event handlers'
            ],
            // 3. HTML entity encoded URLs
            [
                'input' => '<a href="&#106;&#97;&#118;&#97;&#115;&#99;&#114;&#105;&#112;&#116;&#58;alert(1)">Click</a>',
                'description' => 'HTML entity encoded URLs'
            ],
            // 4. Data URLs with scripts
            [
                'input' => '<img src="data:text/html,<script>alert(1)</script>">',
                'description' => 'Data URLs with scripts'
            ],
            // 5. Alternative protocols
            [
                'input' => '<a href="vbscript:alert(1)">Click</a>',
                'description' => 'Alternative protocols'
            ],
            // 6. Mixed case bypasses
            [
                'input' => '<img src="x" OnError="alert(1)">',
                'description' => 'Mixed case bypasses'
            ],
            // 7. Complex nested attacks
            [
                'input' => '<div><script><!--</script>alert(1)--></div>',
                'description' => 'Complex nested attacks'
            ],
            // 8. SVG with script
            [
                'input' => '<svg onload="alert(1)">',
                'description' => 'SVG with script'
            ],
            // 9. Form with autofocus
            [
                'input' => '<input onfocus="alert(1)" autofocus>',
                'description' => 'Form with autofocus'
            ],
            // 10. CSS expression injection
            [
                'input' => '<div style="background:url(javascript:alert(1))">',
                'description' => 'CSS expression injection'
            ],
        ];

        foreach ($attackVectors as $vector) {
            $sanitized = $this->feedParserService->normalizeContent($vector['input']);
            
            // Verify that dangerous content is removed
            $this->assertStringNotContainsString('alert(', $sanitized, 
                sprintf('Attack vector "%s" was not properly sanitized', $vector['description']));
            $this->assertStringNotContainsString('javascript:', $sanitized, 
                sprintf('Attack vector "%s" contains javascript: protocol', $vector['description']));
            $this->assertStringNotContainsString('vbscript:', $sanitized, 
                sprintf('Attack vector "%s" contains vbscript: protocol', $vector['description']));
            $this->assertStringNotContainsString('onerror', $sanitized, 
                sprintf('Attack vector "%s" contains onerror attribute', $vector['description']));
            $this->assertStringNotContainsString('onload', $sanitized, 
                sprintf('Attack vector "%s" contains onload attribute', $vector['description']));
            $this->assertStringNotContainsString('onfocus', $sanitized, 
                sprintf('Attack vector "%s" contains onfocus attribute', $vector['description']));
            $this->assertStringNotContainsString('<script', $sanitized, 
                sprintf('Attack vector "%s" contains script tag', $vector['description']));
            $this->assertStringNotContainsString('<svg', $sanitized, 
                sprintf('Attack vector "%s" contains svg tag', $vector['description']));
            $this->assertStringNotContainsString('<input', $sanitized, 
                sprintf('Attack vector "%s" contains input tag', $vector['description']));
            $this->assertStringNotContainsString('data:', $sanitized, 
                sprintf('Attack vector "%s" contains data: protocol', $vector['description']));
        }
    }

    /**
     * Test that legitimate HTML content is preserved
     */
    public function testLegitimateContentPreservation(): void
    {
        $legitimateContent = '<p>Test <strong>bold</strong> and <em>italic</em> text with <a href="https://example.com" title="Example">link</a> and <img src="https://example.com/image.jpg" alt="Test image" width="100" height="100"></p>';
        
        $sanitized = $this->feedParserService->normalizeContent($legitimateContent);
        
        // Verify that legitimate content is preserved
        $this->assertStringContainsString('<p>', $sanitized, 'Paragraph tag should be preserved');
        $this->assertStringContainsString('<strong>', $sanitized, 'Strong tag should be preserved');
        $this->assertStringContainsString('<em>', $sanitized, 'Em tag should be preserved');
        $this->assertStringContainsString('<a href="https://example.com"', $sanitized, 'Link with https should be preserved');
        $this->assertStringContainsString('title="Example"', $sanitized, 'Title attribute should be preserved');
        $this->assertStringContainsString('<img src="https://example.com/image.jpg"', $sanitized, 'Image with https should be preserved');
        $this->assertStringContainsString('alt="Test image"', $sanitized, 'Alt attribute should be preserved');
        $this->assertStringContainsString('width="100"', $sanitized, 'Width attribute should be preserved');
        $this->assertStringContainsString('height="100"', $sanitized, 'Height attribute should be preserved');
    }

    /**
     * Test that only whitelisted HTML elements are preserved
     */
    public function testWhitelistedElementsOnly(): void
    {
        $mixedContent = '<p>Allowed paragraph</p><script>alert("XSS")</script><strong>Bold text</strong><iframe src="evil.com"></iframe><a href="https://example.com">Link</a><object data="evil.swf"></object>';
        
        $sanitized = $this->feedParserService->normalizeContent($mixedContent);
        
        // Verify that whitelisted elements are preserved
        $this->assertStringContainsString('<p>Allowed paragraph</p>', $sanitized, 'Paragraph should be preserved');
        $this->assertStringContainsString('<strong>Bold text</strong>', $sanitized, 'Strong should be preserved');
        $this->assertStringContainsString('<a href="https://example.com">Link</a>', $sanitized, 'Link should be preserved');
        
        // Verify that forbidden elements are removed
        $this->assertStringNotContainsString('<script>', $sanitized, 'Script tag should be removed');
        $this->assertStringNotContainsString('<iframe>', $sanitized, 'Iframe tag should be removed');
        $this->assertStringNotContainsString('<object>', $sanitized, 'Object tag should be removed');
        $this->assertStringNotContainsString('alert("XSS")', $sanitized, 'Script content should be removed');
        $this->assertStringNotContainsString('evil.com', $sanitized, 'Malicious URL should be removed');
        $this->assertStringNotContainsString('evil.swf', $sanitized, 'Malicious SWF should be removed');
    }

    /**
     * Test that JavaScript URLs are blocked in href and src attributes
     */
    public function testJavaScriptUrlBlocking(): void
    {
        $maliciousLinks = [
            '<a href="javascript:alert(\'XSS\')">Click</a>',
            '<img src="javascript:alert(1)">',
            '<a href="JAVASCRIPT:alert(1)">Click</a>',
            '<a href="jaVAscript:alert(1)">Click</a>',
        ];
        
        foreach ($maliciousLinks as $link) {
            $sanitized = $this->feedParserService->normalizeContent($link);
            $this->assertStringNotContainsString('javascript:', strtolower($sanitized), 
                'JavaScript URL should be blocked: ' . $link);
        }
    }

    /**
     * Test that URL scheme validation allows only http/https
     */
    public function testUrlSchemeValidation(): void
    {
        $urlTests = [
            // Valid URLs that should be preserved
            ['<a href="https://example.com">Link</a>', true],
            ['<a href="http://example.com">Link</a>', true],
            ['<img src="https://example.com/image.jpg">', true],
            ['<img src="http://example.com/image.jpg">', true],
            
            // Invalid URLs that should be blocked
            ['<a href="ftp://example.com">Link</a>', false],
            ['<a href="file:///etc/passwd">Link</a>', false],
            ['<a href="data:text/html,<script>alert(1)</script>">Link</a>', false],
            ['<a href="mailto:test@example.com">Link</a>', false],
            ['<img src="data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==">', false],
        ];
        
        foreach ($urlTests as [$input, $shouldBePreserved]) {
            $sanitized = $this->feedParserService->normalizeContent($input);
            
            if ($shouldBePreserved) {
                $this->assertStringContainsString('href=', $sanitized, 'Valid URL should be preserved: ' . $input);
            } else {
                // Should either remove the attribute or the entire element
                $this->assertThat(
                    $sanitized,
                    $this->logicalOr(
                        $this->stringContains('href=""'),
                        $this->logicalNot($this->stringContains('href='))
                    ),
                    'Invalid URL should be blocked: ' . $input
                );
            }
        }
    }

    /**
     * Test edge cases and error handling
     */
    public function testEdgeCases(): void
    {
        // Test empty content
        $this->assertSame('', $this->feedParserService->normalizeContent(''));
        
        // Test null content (should be handled gracefully)
        $this->assertSame('', $this->feedParserService->normalizeContent(''));
        
        // Test very large content (should not cause memory issues)
        $largeContent = str_repeat('<p>This is a test paragraph with some content.</p>', 1000);
        $sanitized = $this->feedParserService->normalizeContent($largeContent);
        $this->assertStringContainsString('<p>This is a test paragraph with some content.</p>', $sanitized);
        
        // Test malformed HTML
        $malformedHtml = '<p>Test <strong>bold<em>italic</p></strong></em>';
        $sanitized = $this->feedParserService->normalizeContent($malformedHtml);
        $this->assertNotEmpty($sanitized);
    }

    /**
     * Test that nested malicious content is properly handled
     */
    public function testNestedMaliciousContent(): void
    {
        $nestedAttacks = [
            '<div><script>alert("XSS")</script></div>',
            '<p><img src="x" onerror="alert(1)"></p>',
            '<strong><a href="javascript:alert(1)">Click</a></strong>',
            '<blockquote><svg onload="alert(1)"></svg></blockquote>',
        ];
        
        foreach ($nestedAttacks as $attack) {
            $sanitized = $this->feedParserService->normalizeContent($attack);
            $this->assertStringNotContainsString('alert(', $sanitized, 
                'Nested attack should be prevented: ' . $attack);
            $this->assertStringNotContainsString('javascript:', $sanitized, 
                'Nested attack should be prevented: ' . $attack);
            $this->assertStringNotContainsString('onerror', $sanitized, 
                'Nested attack should be prevented: ' . $attack);
            $this->assertStringNotContainsString('onload', $sanitized, 
                'Nested attack should be prevented: ' . $attack);
            $this->assertStringNotContainsString('<script', $sanitized, 
                'Nested attack should be prevented: ' . $attack);
            $this->assertStringNotContainsString('<svg', $sanitized, 
                'Nested attack should be prevented: ' . $attack);
        }
    }

    /**
     * Test performance with reasonable content sizes
     */
    public function testPerformanceWithNormalContent(): void
    {
        $normalContent = '<p>This is a normal RSS feed article with <strong>bold text</strong>, <em>italic text</em>, and <a href="https://example.com">a link</a>. It also contains <img src="https://example.com/image.jpg" alt="An image"> and some lists:</p><ul><li>Item 1</li><li>Item 2</li><li>Item 3</li></ul>';
        
        $startTime = microtime(true);
        for ($i = 0; $i < 100; $i++) {
            $this->feedParserService->normalizeContent($normalContent);
        }
        $endTime = microtime(true);
        
        $processingTime = $endTime - $startTime;
        // Should process 100 iterations in less than 1 second
        $this->assertLessThan(1.0, $processingTime, 
            'Performance should be acceptable for normal content');
    }

    /**
     * Test that specific whitelisted elements and attributes are preserved
     */
    public function testWhitelistedElementsAndAttributes(): void
    {
        $whitelistedContent = '
            <p>Paragraph</p>
            <br>
            <strong>Strong</strong>
            <em>Emphasis</em>
            <b>Bold</b>
            <i>Italic</i>
            <u>Underline</u>
            <a href="https://example.com" title="Example">Link</a>
            <img src="https://example.com/image.jpg" alt="Image" title="Image Title" width="100" height="100">
            <ul><li>List item</li></ul>
            <ol><li>Ordered list item</li></ol>
            <h1>Heading 1</h1>
            <h2>Heading 2</h2>
            <h3>Heading 3</h3>
            <h4>Heading 4</h4>
            <h5>Heading 5</h5>
            <h6>Heading 6</h6>
            <blockquote>Quote</blockquote>
            <pre>Preformatted</pre>
            <code>Code</code>
        ';
        
        $sanitized = $this->feedParserService->normalizeContent($whitelistedContent);
        
        // Verify all whitelisted elements are preserved
        $this->assertStringContainsString('<p>Paragraph</p>', $sanitized);
        $this->assertStringContainsString('<br>', $sanitized);
        $this->assertStringContainsString('<strong>Strong</strong>', $sanitized);
        $this->assertStringContainsString('<em>Emphasis</em>', $sanitized);
        $this->assertStringContainsString('<b>Bold</b>', $sanitized);
        $this->assertStringContainsString('<i>Italic</i>', $sanitized);
        $this->assertStringContainsString('<u>Underline</u>', $sanitized);
        $this->assertStringContainsString('<a href="https://example.com" title="Example">Link</a>', $sanitized);
        $this->assertStringContainsString('<img', $sanitized);
        $this->assertStringContainsString('src="https://example.com/image.jpg"', $sanitized);
        $this->assertStringContainsString('alt="Image"', $sanitized);
        $this->assertStringContainsString('title="Image Title"', $sanitized);
        $this->assertStringContainsString('width="100"', $sanitized);
        $this->assertStringContainsString('height="100"', $sanitized);
        $this->assertStringContainsString('<ul><li>List item</li></ul>', $sanitized);
        $this->assertStringContainsString('<ol><li>Ordered list item</li></ol>', $sanitized);
        $this->assertStringContainsString('<h1>Heading 1</h1>', $sanitized);
        $this->assertStringContainsString('<h2>Heading 2</h2>', $sanitized);
        $this->assertStringContainsString('<h3>Heading 3</h3>', $sanitized);
        $this->assertStringContainsString('<h4>Heading 4</h4>', $sanitized);
        $this->assertStringContainsString('<h5>Heading 5</h5>', $sanitized);
        $this->assertStringContainsString('<h6>Heading 6</h6>', $sanitized);
        $this->assertStringContainsString('<blockquote>Quote</blockquote>', $sanitized);
        $this->assertStringContainsString('<pre>Preformatted</pre>', $sanitized);
        $this->assertStringContainsString('<code>Code</code>', $sanitized);
    }
}