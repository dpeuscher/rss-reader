<?php

namespace App\Service;

use Symfony\Contracts\HttpClient\HttpClientInterface;

class UrlValidationService
{
    private HttpClientInterface $httpClient;
    private array $allowedProtocols = ['http', 'https'];
    private array $dnsCache = [];
    private array $privateIpRanges = [
        '127.0.0.0/8',      // Loopback
        '10.0.0.0/8',       // Private Class A
        '172.16.0.0/12',    // Private Class B
        '192.168.0.0/16',   // Private Class C
        '169.254.0.0/16',   // Link-local
        '0.0.0.0/8',        // Current network
        '224.0.0.0/4',      // Multicast
        '240.0.0.0/4',      // Reserved
        '::1/128',          // IPv6 loopback
        'fc00::/7',         // IPv6 unique local
        'fe80::/10',        // IPv6 link-local
        '::ffff:0:0/96',    // IPv4-mapped IPv6
    ];

    public function __construct(HttpClientInterface $httpClient)
    {
        $this->httpClient = $httpClient;
    }

    public function validateUrl(string $url): UrlValidationResult
    {
        // Step 1: Basic URL format validation
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return new UrlValidationResult(false, 'Invalid URL format');
        }

        $parsedUrl = parse_url($url);
        if (!$parsedUrl) {
            return new UrlValidationResult(false, 'Invalid URL format');
        }

        // Step 2: Protocol validation
        if (!isset($parsedUrl['scheme']) || !in_array(strtolower($parsedUrl['scheme']), $this->allowedProtocols)) {
            return new UrlValidationResult(false, 'Invalid URL format');
        }

        // Step 3: Host validation
        if (!isset($parsedUrl['host'])) {
            return new UrlValidationResult(false, 'Invalid URL format');
        }

        $host = $parsedUrl['host'];

        // Step 4: Block URLs with credentials
        if (isset($parsedUrl['user']) || isset($parsedUrl['pass'])) {
            return new UrlValidationResult(false, 'Invalid URL format');
        }

        // Step 5: Initial IP validation (if host is already an IP)
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            if ($this->isPrivateIp($host)) {
                return new UrlValidationResult(false, 'Access to private networks not allowed');
            }
        } else {
            // Step 6: DNS resolution validation with caching
            $resolvedIps = $this->resolveHostnameWithCache($host);
            if (empty($resolvedIps)) {
                return new UrlValidationResult(false, 'Unable to resolve hostname');
            }

            foreach ($resolvedIps as $ip) {
                if ($this->isPrivateIp($ip)) {
                    return new UrlValidationResult(false, 'Access to private networks not allowed');
                }
            }
        }

        // Step 7: Redirect validation (check first redirect only to prevent infinite loops)
        $redirectValidation = $this->validateRedirects($url);
        if (!$redirectValidation->isValid()) {
            return $redirectValidation;
        }

        return new UrlValidationResult(true, 'URL validation passed');
    }

    private function isPrivateIp(string $ip): bool
    {
        foreach ($this->privateIpRanges as $range) {
            if ($this->ipInRange($ip, $range)) {
                return true;
            }
        }
        return false;
    }

    private function ipInRange(string $ip, string $range): bool
    {
        if (strpos($range, '/') === false) {
            return $ip === $range;
        }

        list($subnet, $bits) = explode('/', $range);
        
        // Handle IPv6
        if (strpos($ip, ':') !== false || strpos($subnet, ':') !== false) {
            return $this->ipv6InRange($ip, $subnet, (int)$bits);
        }
        
        // Handle IPv4 - validate addresses before conversion
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) || 
            !filter_var($subnet, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return false;
        }
        
        $ip = ip2long($ip);
        $subnet = ip2long($subnet);
        
        if ($ip === false || $subnet === false) {
            return false;
        }
        
        $mask = -1 << (32 - $bits);
        $subnet &= $mask;
        
        return ($ip & $mask) === $subnet;
    }

    private function ipv6InRange(string $ip, string $subnet, int $bits): bool
    {
        $ipBinary = inet_pton($ip);
        $subnetBinary = inet_pton($subnet);
        
        if ($ipBinary === false || $subnetBinary === false) {
            return false;
        }
        
        $bytesToCheck = intval($bits / 8);
        $bitsToCheck = $bits % 8;
        
        // Check full bytes
        for ($i = 0; $i < $bytesToCheck; $i++) {
            if ($ipBinary[$i] !== $subnetBinary[$i]) {
                return false;
            }
        }
        
        // Check remaining bits
        if ($bitsToCheck > 0 && $bytesToCheck < 16) {
            $mask = 0xFF << (8 - $bitsToCheck);
            if ((ord($ipBinary[$bytesToCheck]) & $mask) !== (ord($subnetBinary[$bytesToCheck]) & $mask)) {
                return false;
            }
        }
        
        return true;
    }

    private function resolveHostname(string $hostname): array
    {
        $ips = [];
        
        // Set DNS timeout to prevent DoS attacks via slow DNS responses
        $originalTimeout = ini_get('default_socket_timeout');
        ini_set('default_socket_timeout', 3);
        
        try {
            // Get IPv4 addresses
            $ipv4 = gethostbynamel($hostname);
            if ($ipv4 !== false) {
                $ips = array_merge($ips, $ipv4);
            }
            
            // Get IPv6 addresses
            $records = dns_get_record($hostname, DNS_AAAA);
            if ($records !== false) {
                foreach ($records as $record) {
                    if (isset($record['ipv6'])) {
                        $ips[] = $record['ipv6'];
                    }
                }
            }
        } finally {
            // Restore original timeout
            ini_set('default_socket_timeout', $originalTimeout);
        }
        
        return array_unique($ips);
    }

    private function resolveHostnameWithCache(string $hostname): array
    {
        if (isset($this->dnsCache[$hostname])) {
            return $this->dnsCache[$hostname];
        }
        
        $resolvedIps = $this->resolveHostname($hostname);
        $this->dnsCache[$hostname] = $resolvedIps;
        
        return $resolvedIps;
    }

    private function validateRedirects(string $url): UrlValidationResult
    {
        try {
            // Make a HEAD request to check for redirects without downloading content
            $response = $this->httpClient->request('HEAD', $url, [
                'timeout' => 5,
                'max_redirects' => 0, // Don't follow redirects, we'll check manually
                'headers' => [
                    'User-Agent' => 'RSS Reader/1.0',
                ],
            ]);

            // Check if there's a redirect
            $statusCode = $response->getStatusCode();
            if (in_array($statusCode, [301, 302, 303, 307, 308])) {
                $headers = $response->getHeaders();
                if (isset($headers['location'][0])) {
                    $redirectUrl = $headers['location'][0];
                    
                    // Validate the redirect target
                    $parsedRedirect = parse_url($redirectUrl);
                    if (!$parsedRedirect || !isset($parsedRedirect['host'])) {
                        return new UrlValidationResult(false, 'Invalid redirect target');
                    }
                    
                    $redirectHost = $parsedRedirect['host'];
                    
                    // Check if redirect target is a private IP
                    if (filter_var($redirectHost, FILTER_VALIDATE_IP)) {
                        if ($this->isPrivateIp($redirectHost)) {
                            return new UrlValidationResult(false, 'Access to private networks not allowed');
                        }
                    } else {
                        // Resolve redirect hostname with caching
                        $resolvedIps = $this->resolveHostnameWithCache($redirectHost);
                        foreach ($resolvedIps as $ip) {
                            if ($this->isPrivateIp($ip)) {
                                return new UrlValidationResult(false, 'Access to private networks not allowed');
                            }
                        }
                    }
                }
            }
            
            return new UrlValidationResult(true, 'Redirect validation passed');
        } catch (\Exception $e) {
            // If we can't check redirects, allow the request to proceed
            // The actual request will be made later and may still fail
            return new UrlValidationResult(true, 'Redirect validation skipped due to error');
        }
    }
}

class UrlValidationResult
{
    private bool $valid;
    private string $message;

    public function __construct(bool $valid, string $message)
    {
        $this->valid = $valid;
        $this->message = $message;
    }

    public function isValid(): bool
    {
        return $this->valid;
    }

    public function getMessage(): string
    {
        return $this->message;
    }
}