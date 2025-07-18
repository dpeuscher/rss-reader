<?php

namespace App\Tests\Service;

use App\Service\FeedParserService;
use PHPUnit\Framework\TestCase;

class FeedParserServicePerformanceTest extends TestCase
{
    private FeedParserService $feedParserService;

    protected function setUp(): void
    {
        $this->feedParserService = new FeedParserService();
    }

    /**
     * Test performance with realistic RSS content
     */
    public function testPerformanceWithRealisticContent(): void
    {
        // Create realistic RSS content with mixed legitimate and potentially malicious elements
        $testContent = $this->generateRealisticRssContent();
        
        // Warm up the HTMLPurifier instance
        $this->feedParserService->normalizeContent('<p>warmup</p>');
        
        // Measure processing time
        $iterations = 100;
        $startTime = microtime(true);
        
        for ($i = 0; $i < $iterations; $i++) {
            $sanitized = $this->feedParserService->normalizeContent($testContent);
        }
        
        $endTime = microtime(true);
        $totalTime = $endTime - $startTime;
        $avgTimePerIteration = $totalTime / $iterations;
        
        // Performance assertions
        $this->assertLessThan(0.01, $avgTimePerIteration, 'Average processing time should be under 10ms per article');
        $this->assertNotEmpty($sanitized, 'Content should be processed successfully');
        
        // Verify that legitimate content is preserved
        $this->assertStringContainsString('<p>', $sanitized);
        $this->assertStringContainsString('<strong>', $sanitized);
        $this->assertStringContainsString('<em>', $sanitized);
        $this->assertStringContainsString('<a href="https://example.com"', $sanitized);
        $this->assertStringContainsString('<img src="https://example.com/image.jpg"', $sanitized);
        
        // Verify that malicious content is removed
        $this->assertStringNotContainsString('<script>', $sanitized);
        $this->assertStringNotContainsString('onerror', $sanitized);
        $this->assertStringNotContainsString('javascript:', $sanitized);
        
        echo "\nPerformance Results:\n";
        echo "Total time for $iterations iterations: " . round($totalTime * 1000, 2) . "ms\n";
        echo "Average time per iteration: " . round($avgTimePerIteration * 1000, 2) . "ms\n";
        echo "Throughput: " . round(1 / $avgTimePerIteration, 0) . " articles/second\n";
    }

    /**
     * Test performance with large content
     */
    public function testPerformanceWithLargeContent(): void
    {
        // Create large content block
        $largeContent = str_repeat($this->generateRealisticRssContent(), 10);
        
        // Warm up
        $this->feedParserService->normalizeContent('<p>warmup</p>');
        
        $startTime = microtime(true);
        $sanitized = $this->feedParserService->normalizeContent($largeContent);
        $endTime = microtime(true);
        
        $processingTime = $endTime - $startTime;
        
        $this->assertLessThan(0.1, $processingTime, 'Large content should be processed in under 100ms');
        $this->assertNotEmpty($sanitized, 'Large content should be processed successfully');
        
        echo "\nLarge Content Performance:\n";
        echo "Processing time: " . round($processingTime * 1000, 2) . "ms\n";
        echo "Content size: " . strlen($largeContent) . " bytes\n";
        echo "Throughput: " . round(strlen($largeContent) / $processingTime / 1024, 2) . " KB/s\n";
    }

    /**
     * Generate realistic RSS content for testing
     */
    private function generateRealisticRssContent(): string
    {
        return '
            <p>This is a typical RSS article with <strong>bold text</strong> and <em>italic text</em>.</p>
            <p>It might contain <a href="https://example.com" title="Example Link">legitimate links</a> and 
            <img src="https://example.com/image.jpg" alt="Article Image" width="300" height="200" />.</p>
            <h2>Article Subheading</h2>
            <p>Some articles might have lists:</p>
            <ul>
                <li>First item</li>
                <li>Second item with <strong>formatting</strong></li>
                <li>Third item</li>
            </ul>
            <blockquote>
                <p>This is a quote from the article with <em>emphasis</em>.</p>
            </blockquote>
            <p>And sometimes code blocks:</p>
            <pre><code>function example() {
    return "This is safe code";
}</code></pre>
            <p>But we also need to test against malicious content mixed in:</p>
            <script>alert("This should be removed")</script>
            <img src="x" onerror="alert(\'XSS\')">
            <a href="javascript:alert(1)">Malicious link</a>
            <div onclick="alert(\'click\')">Unsafe div</div>
            <p>The legitimate content should remain while malicious content is removed.</p>
        ';
    }

    /**
     * Test memory usage doesn't grow excessively
     */
    public function testMemoryUsage(): void
    {
        $startMemory = memory_get_usage();
        
        $content = $this->generateRealisticRssContent();
        
        // Process content multiple times to test for memory leaks
        for ($i = 0; $i < 50; $i++) {
            $sanitized = $this->feedParserService->normalizeContent($content);
        }
        
        $endMemory = memory_get_usage();
        $memoryIncrease = $endMemory - $startMemory;
        
        // Memory increase should be reasonable (under 1MB for 50 iterations)
        $this->assertLessThan(1024 * 1024, $memoryIncrease, 'Memory usage should not grow excessively');
        
        echo "\nMemory Usage:\n";
        echo "Start memory: " . round($startMemory / 1024, 2) . " KB\n";
        echo "End memory: " . round($endMemory / 1024, 2) . " KB\n";
        echo "Memory increase: " . round($memoryIncrease / 1024, 2) . " KB\n";
    }
}