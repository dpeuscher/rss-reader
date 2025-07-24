<?php

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

class FeedControllerSecurityTest extends WebTestCase
{
    private $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        
        // Note: These tests assume proper authentication is configured
        // In a real application, you would need to authenticate a test user
        // For now, these tests focus on the URL validation logic
    }

    /**
     * Test that file:// protocol is blocked in preview endpoint
     */
    public function testBlockFileProtocolInPreview(): void
    {
        $this->client->request('GET', '/feeds/file:///etc/passwd/preview');
        
        $response = $this->client->getResponse();
        $this->assertEquals(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
        
        $data = json_decode($response->getContent(), true);
        $this->assertEquals('Invalid URL format', $data['error']);
    }

    /**
     * Test that localhost access is blocked in preview endpoint
     */
    public function testBlockLocalhostInPreview(): void
    {
        $this->client->request('GET', '/feeds/http://127.0.0.1:8080/admin/preview');
        
        $response = $this->client->getResponse();
        $this->assertEquals(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
        
        $data = json_decode($response->getContent(), true);
        $this->assertEquals('Access to private networks not allowed', $data['error']);
    }

    /**
     * Test that private network access is blocked in preview endpoint
     */
    public function testBlockPrivateNetworkInPreview(): void
    {
        $this->client->request('GET', '/feeds/http://192.168.1.1/admin/preview');
        
        $response = $this->client->getResponse();
        $this->assertEquals(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
        
        $data = json_decode($response->getContent(), true);
        $this->assertEquals('Access to private networks not allowed', $data['error']);
    }

    /**
     * Test that cloud metadata service access is blocked
     */
    public function testBlockCloudMetadataInPreview(): void
    {
        $this->client->request('GET', '/feeds/http://169.254.169.254/latest/meta-data/preview');
        
        $response = $this->client->getResponse();
        $this->assertEquals(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
        
        $data = json_decode($response->getContent(), true);
        $this->assertEquals('Access to private networks not allowed', $data['error']);
    }

    /**
     * Test that IPv6 localhost is blocked
     */
    public function testBlockIpv6LocalhostInPreview(): void
    {
        // URL encode the IPv6 address properly
        $ipv6Url = urlencode('http://[::1]/admin');
        $this->client->request('GET', "/feeds/$ipv6Url/preview");
        
        $response = $this->client->getResponse();
        $this->assertEquals(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
        
        $data = json_decode($response->getContent(), true);
        $this->assertEquals('Access to private networks not allowed', $data['error']);
    }

    /**
     * Test that URLs with credentials are blocked
     */
    public function testBlockCredentialsInUrl(): void
    {
        $credentialUrl = urlencode('http://user:pass@example.com/feed');
        $this->client->request('GET', "/feeds/$credentialUrl/preview");
        
        $response = $this->client->getResponse();
        $this->assertEquals(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
        
        $data = json_decode($response->getContent(), true);
        $this->assertEquals('Invalid URL format', $data['error']);
    }

    /**
     * Test add feed endpoint with malicious URLs
     */
    public function testAddFeedWithMaliciousUrl(): void
    {
        $this->client->request('POST', '/feeds/add', [
            'url' => 'file:///etc/passwd'
        ]);
        
        $response = $this->client->getResponse();
        $this->assertEquals(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
        
        $data = json_decode($response->getContent(), true);
        $this->assertEquals('Invalid URL format', $data['error']);
    }

    /**
     * Test add feed endpoint with private network URL
     */
    public function testAddFeedWithPrivateNetworkUrl(): void
    {
        $this->client->request('POST', '/feeds/add', [
            'url' => 'http://10.0.0.1/admin'
        ]);
        
        $response = $this->client->getResponse();
        $this->assertEquals(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
        
        $data = json_decode($response->getContent(), true);
        $this->assertEquals('Access to private networks not allowed', $data['error']);
    }

    /**
     * Test that malformed URLs are rejected
     */
    public function testMalformedUrlRejection(): void
    {
        $malformedUrls = [
            'not-a-url',
            'http://',
            'https://',
            '://example.com',
            'http:/example.com',
        ];

        foreach ($malformedUrls as $url) {
            $encodedUrl = urlencode($url);
            $this->client->request('GET', "/feeds/$encodedUrl/preview");
            
            $response = $this->client->getResponse();
            $this->assertEquals(Response::HTTP_BAD_REQUEST, $response->getStatusCode(), 
                "URL should be rejected: $url");
            
            $data = json_decode($response->getContent(), true);
            $this->assertEquals('Invalid URL format', $data['error']);
        }
    }

    /**
     * Test various private IP ranges are blocked
     */
    public function testPrivateIpRangesBlocked(): void
    {
        $privateIps = [
            '127.0.0.1',
            '10.0.0.1',
            '172.16.0.1',
            '192.168.1.1',
            '169.254.169.254', // AWS metadata
        ];

        foreach ($privateIps as $ip) {
            $url = urlencode("http://$ip/");
            $this->client->request('GET', "/feeds/$url/preview");
            
            $response = $this->client->getResponse();
            $this->assertEquals(Response::HTTP_BAD_REQUEST, $response->getStatusCode(), 
                "Private IP should be blocked: $ip");
            
            $data = json_decode($response->getContent(), true);
            $this->assertEquals('Access to private networks not allowed', $data['error']);
        }
    }

    /**
     * Test that error messages don't reveal sensitive information
     */
    public function testErrorMessagesSecure(): void
    {
        $this->client->request('GET', '/feeds/http://127.0.0.1:22/preview');
        
        $response = $this->client->getResponse();
        $data = json_decode($response->getContent(), true);
        
        // Ensure error message doesn't contain sensitive system information
        $this->assertStringNotContainsString('ssh', strtolower($data['error']));
        $this->assertStringNotContainsString('connection', strtolower($data['error']));
        $this->assertStringNotContainsString('refused', strtolower($data['error']));
        $this->assertEquals('Access to private networks not allowed', $data['error']);
    }
}