<?php

namespace App\Service;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

class UrlValidator
{
    private const CACHE_TTL = 3600; // 1 hour cache for validation results
    private const MAX_REDIRECTS = 5;
    
    // Private IP ranges (RFC 1918, RFC 4193, localhost, link-local)
    private const BLOCKED_IPV4_RANGES = [
        '127.0.0.0/8',      // Localhost
        '10.0.0.0/8',       // Private Class A
        '172.16.0.0/12',    // Private Class B 
        '192.168.0.0/16',   // Private Class C
        '169.254.0.0/16',   // Link-local
        '0.0.0.0/8',        // This network
        '224.0.0.0/4',      // Multicast
        '240.0.0.0/4',      // Reserved
    ];
    
    private const BLOCKED_IPV6_RANGES = [
        '::1/128',          // Localhost
        'fc00::/7',         // Private (RFC 4193)
        'fe80::/10',        // Link-local
        'ff00::/8',         // Multicast
        '::/128',           // Unspecified
    ];
    
    private const ALLOWED_SCHEMES = ['http', 'https'];

    public function __construct(
        private LoggerInterface $logger,
        private ?CacheInterface $cache = null
    ) {}

    public function validateUrl(string $url): UrlValidationResult
    {
        // Check cache first
        if ($this->cache) {
            $cacheKey = 'url_validation_' . md5($url);
            try {
                return $this->cache->get($cacheKey, function (ItemInterface $item) use ($url) {
                    $item->expiresAfter(self::CACHE_TTL);
                    return $this->performValidation($url);
                });
            } catch (\Exception $e) {
                $this->logger->error('URL validation cache error', ['error' => $e->getMessage()]);
            }
        }
        
        return $this->performValidation($url);
    }

    private function performValidation(string $url): UrlValidationResult
    {
        try {
            // Step 1: Basic URL validation and normalization
            $normalizedUrl = $this->normalizeUrl($url);
            if (!$normalizedUrl) {
                $this->logBlockedRequest($url, 'Invalid URL format');
                return new UrlValidationResult(false, 'Invalid URL format');
            }

            // Step 2: Parse and validate URL components
            $parsed = parse_url($normalizedUrl);
            if (!$parsed || !isset($parsed['scheme'], $parsed['host'])) {
                $this->logBlockedRequest($url, 'Invalid URL structure');
                return new UrlValidationResult(false, 'Invalid URL');
            }

            // Step 3: Validate scheme
            if (!in_array(strtolower($parsed['scheme']), self::ALLOWED_SCHEMES, true)) {
                $this->logBlockedRequest($url, 'Blocked URL scheme: ' . $parsed['scheme']);
                return new UrlValidationResult(false, 'Invalid URL scheme');
            }

            // Step 4: Validate host and resolve IP
            $validationResult = $this->validateHost($parsed['host']);
            if (!$validationResult->isValid()) {
                $this->logBlockedRequest($url, $validationResult->getMessage());
                return $validationResult;
            }

            // Step 5: Validate final URL after potential redirects (HEAD request)
            $finalValidation = $this->validateRedirects($normalizedUrl);
            if (!$finalValidation->isValid()) {
                $this->logBlockedRequest($url, $finalValidation->getMessage());
                return $finalValidation;
            }

            return new UrlValidationResult(true, 'URL is valid', $normalizedUrl);

        } catch (\Exception $e) {
            $this->logger->error('URL validation error', [
                'url_hash' => hash('sha256', $url), // Don't log actual URL for security
                'error' => $e->getMessage()
            ]);
            return new UrlValidationResult(false, 'URL validation failed');
        }
    }

    private function normalizeUrl(string $url): ?string
    {
        // Trim whitespace
        $url = trim($url);
        if (empty($url)) {
            return null;
        }

        // Add scheme if missing
        if (!preg_match('/^https?:\/\//', $url)) {
            $url = 'https://' . $url;
        }

        // Normalize IDN domains
        $parsed = parse_url($url);
        if (isset($parsed['host'])) {
            $normalizedHost = idn_to_ascii($parsed['host'], IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
            if ($normalizedHost !== false) {
                $parsed['host'] = strtolower($normalizedHost);
            }
        }

        // Rebuild URL
        return $this->buildUrl($parsed);
    }

    private function buildUrl(array $parsed): string
    {
        $url = '';
        if (isset($parsed['scheme'])) {
            $url .= $parsed['scheme'] . '://';
        }
        if (isset($parsed['host'])) {
            $url .= $parsed['host'];
        }
        if (isset($parsed['port']) && $parsed['port'] != 80 && $parsed['port'] != 443) {
            $url .= ':' . $parsed['port'];
        }
        if (isset($parsed['path'])) {
            $url .= $parsed['path'];
        }
        if (isset($parsed['query'])) {
            $url .= '?' . $parsed['query'];
        }
        if (isset($parsed['fragment'])) {
            $url .= '#' . $parsed['fragment'];
        }
        return $url;
    }

    private function validateHost(string $host): UrlValidationResult
    {
        // Remove IPv6 brackets if present
        $cleanHost = trim($host, '[]');
        
        // Check if it's an IP address
        if (filter_var($cleanHost, FILTER_VALIDATE_IP)) {
            return $this->validateIpAddress($cleanHost);
        }

        // For domain names, resolve to IP and validate
        $ips = [];
        
        // Get IPv4 addresses
        $ipv4Records = dns_get_record($host, DNS_A);
        if ($ipv4Records) {
            foreach ($ipv4Records as $record) {
                if (isset($record['ip'])) {
                    $ips[] = $record['ip'];
                }
            }
        }

        // Get IPv6 addresses  
        $ipv6Records = dns_get_record($host, DNS_AAAA);
        if ($ipv6Records) {
            foreach ($ipv6Records as $record) {
                if (isset($record['ipv6'])) {
                    $ips[] = $record['ipv6'];
                }
            }
        }

        // If no IPs resolved, it's invalid
        if (empty($ips)) {
            return new UrlValidationResult(false, 'Unable to resolve domain');
        }

        // Validate all resolved IPs
        foreach ($ips as $ip) {
            $ipValidation = $this->validateIpAddress($ip);
            if (!$ipValidation->isValid()) {
                return $ipValidation;
            }
        }

        return new UrlValidationResult(true, 'Host is valid');
    }

    private function validateIpAddress(string $ip): UrlValidationResult
    {
        // Validate IPv4
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            foreach (self::BLOCKED_IPV4_RANGES as $range) {
                if ($this->ipInRange($ip, $range)) {
                    return new UrlValidationResult(false, 'Blocked IP address range');
                }
            }
            return new UrlValidationResult(true, 'IPv4 address is valid');
        }

        // Validate IPv6
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            foreach (self::BLOCKED_IPV6_RANGES as $range) {
                if ($this->ipv6InRange($ip, $range)) {
                    return new UrlValidationResult(false, 'Blocked IP address range');
                }
            }
            return new UrlValidationResult(true, 'IPv6 address is valid');
        }

        return new UrlValidationResult(false, 'Invalid IP address');
    }

    private function ipInRange(string $ip, string $range): bool
    {
        [$subnet, $mask] = explode('/', $range);
        $ip_long = ip2long($ip);
        $subnet_long = ip2long($subnet);
        $mask_long = -1 << (32 - (int)$mask);
        
        return ($ip_long & $mask_long) === ($subnet_long & $mask_long);
    }

    private function ipv6InRange(string $ip, string $range): bool
    {
        [$subnet, $mask] = explode('/', $range);
        
        $ip_bin = inet_pton($ip);
        $subnet_bin = inet_pton($subnet);
        
        if ($ip_bin === false || $subnet_bin === false) {
            return false;
        }
        
        $mask = (int)$mask;
        $bytes = $mask >> 3; // Number of full bytes
        $bits = $mask & 7;   // Remaining bits
        
        // Compare full bytes
        if ($bytes > 0 && substr($ip_bin, 0, $bytes) !== substr($subnet_bin, 0, $bytes)) {
            return false;
        }
        
        // Compare remaining bits if any
        if ($bits > 0 && $bytes < 16) {
            $mask_byte = 0xFF << (8 - $bits);
            $ip_byte = ord($ip_bin[$bytes]) & $mask_byte;
            $subnet_byte = ord($subnet_bin[$bytes]) & $mask_byte;
            return $ip_byte === $subnet_byte;
        }
        
        return true;
    }

    private function validateRedirects(string $url): UrlValidationResult
    {
        $httpClient = new \Symfony\Component\HttpClient\CurlHttpClient([
            'timeout' => 10,
            'max_redirects' => 0, // We'll handle redirects manually
        ]);

        $currentUrl = $url;
        $redirectCount = 0;

        while ($redirectCount < self::MAX_REDIRECTS) {
            try {
                $response = $httpClient->request('HEAD', $currentUrl, [
                    'headers' => [
                        'User-Agent' => 'RSS Reader/1.0',
                    ],
                ]);

                $statusCode = $response->getStatusCode();
                
                // If it's a redirect, validate the redirect URL
                if ($statusCode >= 300 && $statusCode < 400) {
                    $location = $response->getHeaders()['location'][0] ?? null;
                    if (!$location) {
                        return new UrlValidationResult(false, 'Invalid redirect response');
                    }

                    // Resolve relative URLs
                    if (!parse_url($location, PHP_URL_SCHEME)) {
                        $base = parse_url($currentUrl);
                        $location = $base['scheme'] . '://' . $base['host'] . 
                                   (isset($base['port']) ? ':' . $base['port'] : '') . 
                                   '/' . ltrim($location, '/');
                    }

                    // Validate the redirect URL
                    $normalizedRedirect = $this->normalizeUrl($location);
                    if (!$normalizedRedirect) {
                        return new UrlValidationResult(false, 'Invalid redirect URL');
                    }

                    $parsed = parse_url($normalizedRedirect);
                    if (!$parsed || !isset($parsed['scheme'], $parsed['host'])) {
                        return new UrlValidationResult(false, 'Invalid redirect URL structure');
                    }

                    if (!in_array(strtolower($parsed['scheme']), self::ALLOWED_SCHEMES, true)) {
                        return new UrlValidationResult(false, 'Invalid redirect URL scheme');
                    }

                    $hostValidation = $this->validateHost($parsed['host']);
                    if (!$hostValidation->isValid()) {
                        return new UrlValidationResult(false, 'Blocked redirect destination: ' . $hostValidation->getMessage());
                    }

                    $currentUrl = $normalizedRedirect;
                    $redirectCount++;
                } else {
                    // Not a redirect, we're done
                    break;
                }
            } catch (\Exception $e) {
                return new UrlValidationResult(false, 'Network error during validation');
            }
        }

        if ($redirectCount >= self::MAX_REDIRECTS) {
            return new UrlValidationResult(false, 'Too many redirects');
        }

        return new UrlValidationResult(true, 'Redirect validation passed', $currentUrl);
    }

    private function logBlockedRequest(string $url, string $reason): void
    {
        $this->logger->warning('Blocked URL request', [
            'url_hash' => hash('sha256', $url), // Don't log actual URL for security
            'reason' => $reason,
            'timestamp' => time()
        ]);
    }
}

class UrlValidationResult
{
    public function __construct(
        private bool $valid,
        private string $message,
        private ?string $validatedUrl = null
    ) {}

    public function isValid(): bool
    {
        return $this->valid;
    }

    public function getMessage(): string
    {
        return $this->message;
    }

    public function getValidatedUrl(): ?string
    {
        return $this->validatedUrl;
    }
}