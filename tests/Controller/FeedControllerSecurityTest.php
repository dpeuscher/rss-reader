<?php

namespace App\Tests\Controller;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

class FeedControllerSecurityTest extends WebTestCase
{
    private $client;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);
        
        // Create and authenticate a test user for the protected endpoints
        $this->authenticateUser();
    }

    private function authenticateUser(): void
    {
        // Create a test user if it doesn't exist
        $user = $this->entityManager->getRepository(User::class)->findOneBy(['email' => 'test@example.com']);
        
        if (!$user) {
            $user = new User();
            $user->setEmail('test@example.com');
            $user->setPassword('$2y$13$hashedpassword'); // Mock hashed password
            $this->entityManager->persist($user);
            $this->entityManager->flush();
        }

        $this->client->loginUser($user);
    }

    /**
     * Test feed preview blocks SSRF attempts to localhost
     */
    public function testPreviewBlocksLocalhostSSRF(): void
    {
        $ssrfUrls = [
            'http://127.0.0.1:6379/',           // Redis
            'http://localhost:3306/',           // MySQL
            'http://127.0.0.1:22/',             // SSH
            'http://localhost:8080/admin/',     // Internal admin
        ];

        foreach ($ssrfUrls as $url) {
            $this->client->request('GET', '/feeds/' . urlencode($url) . '/preview');
            
            $response = $this->client->getResponse();
            $this->assertEquals(Response::HTTP_BAD_REQUEST, $response->getStatusCode(), 
                "SSRF URL should be blocked: $url");
            
            $responseData = json_decode($response->getContent(), true);
            $this->assertArrayHasKey('error', $responseData);
            $this->assertEquals('Access to private IPs not allowed', $responseData['error']);
        }
    }

    /**
     * Test feed preview blocks SSRF attempts to private networks
     */
    public function testPreviewBlocksPrivateNetworkSSRF(): void
    {
        $privateNetworkUrls = [
            'http://10.0.0.1/',
            'http://172.16.1.1/',
            'http://192.168.1.1/',
            'http://169.254.169.254/',      // AWS metadata
        ];

        foreach ($privateNetworkUrls as $url) {
            $this->client->request('GET', '/feeds/' . urlencode($url) . '/preview');
            
            $response = $this->client->getResponse();
            $this->assertEquals(Response::HTTP_BAD_REQUEST, $response->getStatusCode(), 
                "Private network URL should be blocked: $url");
            
            $responseData = json_decode($response->getContent(), true);
            $this->assertArrayHasKey('error', $responseData);
            $this->assertEquals('Access to private IPs not allowed', $responseData['error']);
        }
    }

    /**
     * Test feed preview blocks invalid protocols
     */
    public function testPreviewBlocksInvalidProtocols(): void
    {
        $invalidProtocolUrls = [
            'ftp://example.com/feed.xml',
            'file:///etc/passwd',
            'gopher://example.com/',
            'ldap://example.com/',
            'javascript:alert(1)',
        ];

        foreach ($invalidProtocolUrls as $url) {
            $this->client->request('GET', '/feeds/' . urlencode($url) . '/preview');
            
            $response = $this->client->getResponse();
            $this->assertEquals(Response::HTTP_BAD_REQUEST, $response->getStatusCode(), 
                "Invalid protocol should be blocked: $url");
            
            $responseData = json_decode($response->getContent(), true);
            $this->assertArrayHasKey('error', $responseData);
            $this->assertEquals('Invalid URL format', $responseData['error']);
        }
    }

    /**
     * Test feed preview blocks IPv6 SSRF attempts
     */
    public function testPreviewBlocksIPv6SSRF(): void
    {
        $ipv6SSRFUrls = [
            'http://[::1]/',                        // IPv6 localhost
            'http://[fe80::1]/',                    // Link-local
            'http://[::ffff:127.0.0.1]/',          // IPv4-mapped IPv6
        ];

        foreach ($ipv6SSRFUrls as $url) {
            $this->client->request('GET', '/feeds/' . urlencode($url) . '/preview');
            
            $response = $this->client->getResponse();
            $this->assertEquals(Response::HTTP_BAD_REQUEST, $response->getStatusCode(), 
                "IPv6 SSRF URL should be blocked: $url");
            
            $responseData = json_decode($response->getContent(), true);
            $this->assertArrayHasKey('error', $responseData);
            $this->assertEquals('Access to private IPs not allowed', $responseData['error']);
        }
    }

    /**
     * Test feed add endpoint has same SSRF protection
     */
    public function testAddFeedBlocksSSRF(): void
    {
        $ssrfUrls = [
            'http://127.0.0.1:6379/',
            'http://169.254.169.254/',
            'ftp://example.com/feed.xml',
        ];

        foreach ($ssrfUrls as $url) {
            $this->client->request('POST', '/feeds/add', [
                'url' => $url
            ]);
            
            $response = $this->client->getResponse();
            $this->assertEquals(Response::HTTP_BAD_REQUEST, $response->getStatusCode(), 
                "Add feed should block SSRF: $url");
            
            $responseData = json_decode($response->getContent(), true);
            $this->assertArrayHasKey('error', $responseData);
            $this->assertContains($responseData['error'], [
                'Access to private IPs not allowed',
                'Invalid URL format'
            ]);
        }
    }

    /**
     * Test URL encoding bypass attempts are prevented
     */
    public function testPreviewBlocksUrlEncodingBypass(): void
    {
        $bypassUrls = [
            'http://127.0.0.1%2Eexample.com/',
            'http://0x7f000001/',                   // Hex encoding
            'http://2130706433/',                   // Decimal encoding
        ];

        foreach ($bypassUrls as $url) {
            $this->client->request('GET', '/feeds/' . urlencode($url) . '/preview');
            
            $response = $this->client->getResponse();
            $this->assertEquals(Response::HTTP_BAD_REQUEST, $response->getStatusCode(), 
                "URL encoding bypass should be prevented: $url");
        }
    }

    /**
     * Test that valid external URLs would be processed (mock test)
     * Note: This test validates the positive case without making actual HTTP requests
     */
    public function testValidExternalUrlsAreAccepted(): void
    {
        // These tests would require mocking the HTTP client to avoid actual network requests
        // For now, we verify that the URL validation layer accepts valid URLs
        
        $validUrls = [
            'https://feeds.feedburner.com/example',
            'http://blog.example.com/rss.xml',
        ];

        foreach ($validUrls as $url) {
            $this->client->request('GET', '/feeds/' . urlencode($url) . '/preview');
            
            $response = $this->client->getResponse();
            // We expect this to fail with a network error (not a validation error)
            // since we're not mocking the HTTP client, but it should pass URL validation
            $this->assertNotEquals(Response::HTTP_BAD_REQUEST, $response->getStatusCode(), 
                "Valid URL should pass validation: $url");
        }
    }

    /**
     * Test that unauthenticated requests are blocked
     */
    public function testUnauthenticatedRequestsBlocked(): void
    {
        // Create a new client without authentication
        $unauthenticatedClient = static::createClient();
        
        $unauthenticatedClient->request('GET', '/feeds/http://example.com/preview');
        $response = $unauthenticatedClient->getResponse();
        
        // Should redirect to login or return 401/403
        $this->assertContains($response->getStatusCode(), [
            Response::HTTP_UNAUTHORIZED,
            Response::HTTP_FORBIDDEN,
            Response::HTTP_FOUND  // Redirect to login
        ]);
    }

    /**
     * Test error message consistency (no information disclosure)
     */
    public function testErrorMessageConsistency(): void
    {
        $urls = [
            'http://127.0.0.1/',
            'http://192.168.1.1/',
            'http://10.0.0.1/',
        ];

        $errorMessages = [];
        foreach ($urls as $url) {
            $this->client->request('GET', '/feeds/' . urlencode($url) . '/preview');
            $response = $this->client->getResponse();
            $responseData = json_decode($response->getContent(), true);
            $errorMessages[] = $responseData['error'];
        }

        // All private IP errors should have the same message
        $uniqueMessages = array_unique($errorMessages);
        $this->assertCount(1, $uniqueMessages, 'Error messages should be consistent');
        $this->assertEquals('Access to private IPs not allowed', $uniqueMessages[0]);
    }
}