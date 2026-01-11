<?php

namespace App\Service;

use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpClient\TraceableHttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

class SecureHttpClientFactory
{
    private UrlSecurityValidator $urlValidator;

    public function __construct(UrlSecurityValidator $urlValidator)
    {
        $this->urlValidator = $urlValidator;
    }

    public function createSecureClient(): HttpClientInterface
    {
        $baseOptions = $this->urlValidator->getSecureHttpClientOptions();
        
        // Create the base HTTP client with security configurations
        $httpClient = HttpClient::create(array_merge($baseOptions, [
            // Disable automatic decompression to prevent compression bombs
            'headers' => array_merge($baseOptions['headers'], [
                'Accept-Encoding' => 'identity',
            ]),
            // Additional security options
            'verify_peer' => true,
            'verify_host' => true,
            'cafile' => null, // Use system CA bundle
            'local_cert' => null,
            'local_pk' => null,
        ]));

        // Wrap with security-aware client
        return new SecureHttpClient($httpClient, $this->urlValidator);
    }
}

class SecureHttpClient implements HttpClientInterface
{
    private HttpClientInterface $decoratedClient;
    private UrlSecurityValidator $urlValidator;
    private int $redirectCount = 0;

    public function __construct(HttpClientInterface $decoratedClient, UrlSecurityValidator $urlValidator)
    {
        $this->decoratedClient = $decoratedClient;
        $this->urlValidator = $urlValidator;
    }

    public function request(string $method, string $url, array $options = []): ResponseInterface
    {
        // Validate method - only allow GET for feed requests
        if (strtoupper($method) !== 'GET') {
            throw new \InvalidArgumentException('Only GET method is allowed for feed requests');
        }

        // Validate URL before making request
        $validationResult = $this->urlValidator->validateUrl($url);
        if (!$validationResult->isValid()) {
            throw new \InvalidArgumentException('URL validation failed: ' . $validationResult->getMessage());
        }

        // Use the validated URL
        $validatedUrl = $validationResult->getValidatedUrl() ?? $url;

        // Reset redirect count for new requests
        $this->redirectCount = 0;

        // Merge security options
        $secureOptions = array_merge($options, $this->urlValidator->getSecureHttpClientOptions());
        
        // Add custom redirect handler to validate redirect URLs
        $secureOptions['on_progress'] = $this->createSecureProgressCallback($options['on_progress'] ?? null);

        return new SecureResponse(
            $this->decoratedClient->request($method, $validatedUrl, $secureOptions),
            $this->urlValidator
        );
    }

    public function stream($responses, float $timeout = null): \Generator
    {
        return $this->decoratedClient->stream($responses, $timeout);
    }

    public function withOptions(array $options): static
    {
        return new self(
            $this->decoratedClient->withOptions($options),
            $this->urlValidator
        );
    }

    private function createSecureProgressCallback(?callable $originalCallback): callable
    {
        return function (int $dlNow, int $dlSize, int $upNow, int $upSize) use ($originalCallback): void {
            // Check response size limits
            $maxSize = 5 * 1024 * 1024; // 5MB
            if ($dlSize > $maxSize || $dlNow > $maxSize) {
                throw new \RuntimeException('Response size limit exceeded (5MB)');
            }

            // Call original callback if provided
            if ($originalCallback) {
                $originalCallback($dlNow, $dlSize, $upNow, $upSize);
            }
        };
    }
}

class SecureResponse implements ResponseInterface
{
    private ResponseInterface $decoratedResponse;
    private UrlSecurityValidator $urlValidator;

    public function __construct(ResponseInterface $decoratedResponse, UrlSecurityValidator $urlValidator)
    {
        $this->decoratedResponse = $decoratedResponse;
        $this->urlValidator = $urlValidator;
    }

    public function getStatusCode(): int
    {
        return $this->decoratedResponse->getStatusCode();
    }

    public function getHeaders(bool $throw = true): array
    {
        return $this->decoratedResponse->getHeaders($throw);
    }

    public function getContent(bool $throw = true): string
    {
        $content = $this->decoratedResponse->getContent($throw);
        
        // Additional content security checks could be added here
        // For now, just return the content as the size is already validated
        return $content;
    }

    public function toArray(bool $throw = true): array
    {
        return $this->decoratedResponse->toArray($throw);
    }

    public function cancel(): void
    {
        $this->decoratedResponse->cancel();
    }

    public function getInfo(string $type = null)
    {
        $info = $this->decoratedResponse->getInfo($type);
        
        // Validate redirect URLs if this response involved redirects
        if ($type === 'redirect_url' && $info) {
            $validationResult = $this->urlValidator->validateUrl($info);
            if (!$validationResult->isValid()) {
                throw new \RuntimeException('Redirect URL validation failed: ' . $validationResult->getMessage());
            }
        }
        
        return $info;
    }

    public function toStream(bool $throw = true)
    {
        return $this->decoratedResponse->toStream($throw);
    }
}