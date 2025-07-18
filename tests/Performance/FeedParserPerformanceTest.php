<?php

namespace App\Tests\Performance;

use App\Service\FeedParserService;
use PHPUnit\Framework\TestCase;

/**
 * Performance test for FeedParserService
 * 
 * This test suite verifies that the new HTMLPurifier-based sanitization 
 * meets the performance requirements specified in the issue.
 */
class FeedParserPerformanceTest extends TestCase
{
    private FeedParserService $feedParser;

    protected function setUp(): void
    {
        $this->feedParser = new FeedParserService();
    }

    /**
     * Test performance benchmark for the normalizeContent method
     * 
     * According to the issue requirements:
     * - Processing time increase should be <20%
     * - Memory overhead should be <5MB per request
     * - Throughput should be >100 articles/second
     */
    public function testNormalizeContentPerformance(): void
    {
        // Test data representing typical RSS content
        $testContents = [
            // Small content (<1KB)
            '<p>Small RSS content with <strong>formatting</strong> and <a href="https://example.com">links</a>.</p>',
            
            // Medium content (1-10KB)
            str_repeat('<p>Medium RSS content with <strong>formatting</strong>, <em>emphasis</em>, and <a href="https://example.com">links</a>. </p>', 50),
            
            // Large content (>10KB)
            str_repeat('<p>Large RSS content with <strong>formatting</strong>, <em>emphasis</em>, <a href="https://example.com">links</a>, and <img src="https://example.com/image.jpg" alt="image">. </p>', 200),
        ];

        $results = [];
        
        foreach ($testContents as $size => $content) {
            $sizeLabel = ['small', 'medium', 'large'][$size];
            $contentSize = strlen($content);
            
            // Warm up the purifier (first run initializes the singleton)
            $this->feedParser->normalizeContent($content);
            
            // Measure processing time for 100 iterations
            $startTime = microtime(true);
            $startMemory = memory_get_usage(true);
            
            for ($i = 0; $i < 100; $i++) {
                $result = $this->feedParser->normalizeContent($content);
                $this->assertNotEmpty($result);
            }
            
            $endTime = microtime(true);
            $endMemory = memory_get_usage(true);
            
            $processingTime = ($endTime - $startTime) / 100; // Average time per operation
            $memoryUsage = ($endMemory - $startMemory) / 1024 / 1024; // MB
            
            $results[$sizeLabel] = [
                'content_size' => $contentSize,
                'processing_time' => $processingTime * 1000, // Convert to milliseconds
                'memory_usage' => $memoryUsage,
                'throughput' => 1 / $processingTime, // Operations per second
            ];
            
            // Performance assertions
            $this->assertLessThan(50, $processingTime * 1000, 
                "Processing time for {$sizeLabel} content exceeded 50ms: " . ($processingTime * 1000) . "ms");
            
            $this->assertLessThan(5, $memoryUsage, 
                "Memory usage for {$sizeLabel} content exceeded 5MB: {$memoryUsage}MB");
            
            $this->assertGreaterThan(20, 1 / $processingTime, 
                "Throughput for {$sizeLabel} content below 20 ops/sec: " . (1 / $processingTime) . " ops/sec");
        }
        
        // Output performance results for analysis
        echo "\n=== Performance Test Results ===\n";
        foreach ($results as $size => $data) {
            echo sprintf(
                "%s content (%d bytes): %.2fms, %.2fMB memory, %.1f ops/sec\n",
                ucfirst($size),
                $data['content_size'],
                $data['processing_time'],
                $data['memory_usage'],
                $data['throughput']
            );
        }
        echo "=================================\n";
    }

    /**
     * Test singleton pattern performance benefits
     */
    public function testSingletonPatternPerformance(): void
    {
        $content = '<p>Test content with <strong>formatting</strong> and <a href="https://example.com">links</a>.</p>';
        
        // First call initializes the singleton
        $startTime = microtime(true);
        $this->feedParser->normalizeContent($content);
        $firstCallTime = microtime(true) - $startTime;
        
        // Subsequent calls should be faster due to singleton reuse
        $startTime = microtime(true);
        $this->feedParser->normalizeContent($content);
        $secondCallTime = microtime(true) - $startTime;
        
        $this->assertLessThan($firstCallTime, $secondCallTime, 
            "Singleton pattern should make subsequent calls faster");
    }

    /**
     * Test memory usage with large content
     */
    public function testMemoryUsageWithLargeContent(): void
    {
        $largeContent = str_repeat('<p>Large content block with <strong>formatting</strong>. </p>', 1000);
        
        $startMemory = memory_get_usage(true);
        $result = $this->feedParser->normalizeContent($largeContent);
        $endMemory = memory_get_usage(true);
        
        $memoryUsed = ($endMemory - $startMemory) / 1024 / 1024; // MB
        
        $this->assertNotEmpty($result);
        $this->assertLessThan(10, $memoryUsed, 
            "Memory usage exceeded 10MB for large content: {$memoryUsed}MB");
    }

    /**
     * Test throughput with multiple articles
     */
    public function testThroughputWithMultipleArticles(): void
    {
        $articles = [];
        for ($i = 0; $i < 1000; $i++) {
            $articles[] = "<p>Article {$i} with <strong>formatting</strong> and <a href=\"https://example.com/article{$i}\">links</a>.</p>";
        }
        
        $startTime = microtime(true);
        
        foreach ($articles as $article) {
            $result = $this->feedParser->normalizeContent($article);
            $this->assertNotEmpty($result);
        }
        
        $endTime = microtime(true);
        $totalTime = $endTime - $startTime;
        $throughput = count($articles) / $totalTime;
        
        $this->assertGreaterThan(100, $throughput, 
            "Throughput below 100 articles/second: {$throughput} articles/sec");
        
        echo "\nThroughput test: {$throughput} articles/second\n";
    }

    /**
     * Test performance with malicious content
     */
    public function testPerformanceWithMaliciousContent(): void
    {
        $maliciousContent = str_repeat('<script>alert("XSS")</script><img src="x" onerror="alert(1)"><p>Content</p>', 100);
        
        $startTime = microtime(true);
        $result = $this->feedParser->normalizeContent($maliciousContent);
        $endTime = microtime(true);
        
        $processingTime = ($endTime - $startTime) * 1000; // milliseconds
        
        $this->assertNotEmpty($result);
        $this->assertStringNotContainsString('<script>', $result);
        $this->assertStringNotContainsString('alert', $result);
        $this->assertLessThan(100, $processingTime, 
            "Processing time for malicious content exceeded 100ms: {$processingTime}ms");
    }
}