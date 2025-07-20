<?php

namespace App\Service;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Component\HttpClient\Exception\TransportException;

class SecureHttpClient
{
    private HttpClientInterface $httpClient;
    private UrlSecurityValidator $urlValidator;
    private LoggerInterface $logger;
    private array $config;

    public function __construct(
        HttpClientInterface $httpClient,
        UrlSecurityValidator $urlValidator,
        LoggerInterface $logger
    ) {
        $this->httpClient = $httpClient;
        $this->urlValidator = $urlValidator;
        $this->logger = $logger;
        $this->config = $urlValidator->getConfig();
    }

    /**
     * Makes a secure HTTP request with SSRF protection
     */
    public function request(string $method, string $url, array $options = []): SecureHttpResponse
    {
        // Step 1: Validate initial URL
        $validationResult = $this->urlValidator->validateUrl($url);
        if (!$validationResult->isValid()) {
            return new SecureHttpResponse(false, null, $validationResult->getMessage());
        }

        $normalizedUrl = $validationResult->getNormalizedUrl();

        // Step 2: Configure secure HTTP client options
        $secureOptions = $this->buildSecureOptions($options);

        try {
            // Step 3: Make HTTP request with redirect handling
            $response = $this->makeRequestWithRedirectValidation($method, $normalizedUrl, $secureOptions);
            
            // Step 4: Validate response size
            $content = $this->getValidatedContent($response);
            
            return new SecureHttpResponse(true, $response, null, $content);
            
        } catch (TransportException $e) {
            $this->logger->error('Secure HTTP request transport error', [
                'url' => $url,
                'error' => $e->getMessage(),
                'method' => $method
            ]);
            return new SecureHttpResponse(false, null, 'Network error: ' . $e->getMessage());
        } catch (\Exception $e) {
            $this->logger->error('Secure HTTP request error', [
                'url' => $url,
                'error' => $e->getMessage(),
                'method' => $method
            ]);
            return new SecureHttpResponse(false, null, 'Request error: ' . $e->getMessage());
        }
    }

    /**
     * Builds secure options for HTTP client
     */
    private function buildSecureOptions(array $userOptions = []): array
    {
        $secureOptions = [
            'timeout' => $this->config['timeout'] ?? 30,
            'max_redirects' => 5,
            'headers' => [
                'User-Agent' => 'RSS Reader/1.0 (Security Enhanced)',
            ],
            'verify_peer' => true,
            'verify_host' => true,
            'cafile' => null, // Use system CA bundle
            'max_duration' => 60, // Maximum time for entire request including redirects
        ];

        // Merge with user options (user options take precedence for headers)
        if (isset($userOptions['headers'])) {
            $secureOptions['headers'] = array_merge($secureOptions['headers'], $userOptions['headers']);
        }

        // Override security-critical options to prevent bypassing
        $secureOptions['timeout'] = min($secureOptions['timeout'], $this->config['timeout'] ?? 30);
        $secureOptions['max_redirects'] = min($secureOptions['max_redirects'] ?? 5, 5);

        return array_merge($userOptions, $secureOptions);
    }

    /**
     * Makes HTTP request with manual redirect validation
     */
    private function makeRequestWithRedirectValidation(string $method, string $url, array $options): ResponseInterface
    {
        // Disable automatic redirects to handle them manually
        $options['max_redirects'] = 0;
        
        $maxRedirects = 5;
        $redirectCount = 0;
        $originalUrl = $url;
        
        while ($redirectCount < $maxRedirects) {
            $response = $this->httpClient->request($method, $url, $options);
            $statusCode = $response->getStatusCode();
            
            // If not a redirect, return the response
            if (!in_array($statusCode, [301, 302, 303, 307, 308], true)) {
                return $response;
            }
            
            // Get redirect location
            $headers = $response->getHeaders();
            if (!isset($headers['location'][0])) {
                throw new \RuntimeException('Redirect response missing Location header');
            }
            
            $redirectUrl = $headers['location'][0];
            
            // Handle relative redirects
            if (!parse_url($redirectUrl, PHP_URL_SCHEME)) {
                $redirectUrl = $this->resolveRelativeUrl($url, $redirectUrl);
            }
            
            // Validate redirect URL
            $redirectValidation = $this->urlValidator->validateRedirectUrl($redirectUrl, $originalUrl);
            if (!$redirectValidation->isValid()) {
                throw new \RuntimeException('Redirect blocked: ' . $redirectValidation->getMessage());
            }
            
            $url = $redirectValidation->getNormalizedUrl();
            $redirectCount++;
            
            // For POST requests, change method to GET for 303 redirects
            if ($statusCode === 303 && $method === 'POST') {
                $method = 'GET';
                unset($options['body']);
            }
        }
        
        throw new \RuntimeException('Too many redirects (maximum: ' . $maxRedirects . ')');
    }

    /**
     * Resolves relative URL against base URL
     */
    private function resolveRelativeUrl(string $baseUrl, string $relativeUrl): string
    {
        $parsedBase = parse_url($baseUrl);
        $parsedRelative = parse_url($relativeUrl);
        
        if (isset($parsedRelative['scheme'])) {
            return $relativeUrl; // Already absolute
        }
        
        $scheme = $parsedBase['scheme'];
        $host = $parsedBase['host'];
        $port = isset($parsedBase['port']) ? ':' . $parsedBase['port'] : '';
        
        if ($relativeUrl[0] === '/') {
            // Absolute path
            return $scheme . '://' . $host . $port . $relativeUrl;
        }
        
        // Relative path
        $basePath = dirname($parsedBase['path'] ?? '/');
        if ($basePath === '.') {
            $basePath = '/';
        }
        
        return $scheme . '://' . $host . $port . rtrim($basePath, '/') . '/' . $relativeUrl;
    }

    /**
     * Gets and validates response content with size limits
     */
    private function getValidatedContent(ResponseInterface $response): string
    {
        $maxSize = $this->config['max_response_size'] ?? (10 * 1024 * 1024); // 10MB default
        $content = '';
        $totalSize = 0;
        
        foreach ($this->httpClient->stream($response) as $chunk) {
            $chunkContent = $chunk->getContent();
            $chunkSize = strlen($chunkContent);
            
            if ($totalSize + $chunkSize > $maxSize) {
                throw new \RuntimeException('Response size exceeds maximum allowed size (' . 
                    $this->formatBytes($maxSize) . ')');
            }
            
            $content .= $chunkContent;
            $totalSize += $chunkSize;
        }
        
        return $content;
    }

    /**
     * Formats bytes for human-readable output
     */
    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $unitIndex = 0;
        
        while ($bytes >= 1024 && $unitIndex < count($units) - 1) {
            $bytes /= 1024;
            $unitIndex++;
        }
        
        return round($bytes, 2) . ' ' . $units[$unitIndex];
    }
}

class SecureHttpResponse
{
    private bool $success;
    private ?ResponseInterface $response;
    private ?string $error;
    private ?string $content;

    public function __construct(bool $success, ?ResponseInterface $response, ?string $error = null, ?string $content = null)
    {
        $this->success = $success;
        $this->response = $response;
        $this->error = $error;
        $this->content = $content;
    }

    public function isSuccess(): bool
    {
        return $this->success;
    }

    public function getResponse(): ?ResponseInterface
    {
        return $this->response;
    }

    public function getError(): ?string
    {
        return $this->error;
    }

    public function getContent(): ?string
    {
        return $this->content;
    }

    public function getStatusCode(): ?int
    {
        return $this->response?->getStatusCode();
    }

    public function getHeaders(): array
    {
        return $this->response?->getHeaders() ?? [];
    }
}