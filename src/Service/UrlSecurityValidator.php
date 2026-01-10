<?php

namespace App\Service;

use Symfony\Component\HttpFoundation\Response;

class UrlSecurityValidator
{
    private const ALLOWED_SCHEMES = ['http', 'https'];
    private const MAX_RESPONSE_SIZE = 5 * 1024 * 1024; // 5MB
    private const MAX_REDIRECTS = 3;
    private const CONNECTION_TIMEOUT = 5;
    private const READ_TIMEOUT = 10;
    private const TOTAL_TIMEOUT = 15;

    // Private IP ranges (IPv4)
    private const PRIVATE_IPV4_RANGES = [
        ['127.0.0.0', '127.255.255.255'],     // Loopback
        ['10.0.0.0', '10.255.255.255'],       // Class A private
        ['172.16.0.0', '172.31.255.255'],     // Class B private
        ['192.168.0.0', '192.168.255.255'],   // Class C private
        ['169.254.0.0', '169.254.255.255'],   // Link-local
        ['0.0.0.0', '0.255.255.255'],         // Reserved
        ['224.0.0.0', '255.255.255.255'],     // Multicast & reserved
    ];

    // Private IPv6 ranges
    private const PRIVATE_IPV6_RANGES = [
        '::1/128',          // Loopback
        'fe80::/10',        // Link-local
        'fc00::/7',         // Unique local addresses
        'ff00::/8',         // Multicast
        '::ffff:0:0/96',    // IPv4-mapped IPv6 addresses
    ];

    public function validateUrl(string $url): UrlValidationResult
    {
        try {
            // Step 1: Basic URL format validation
            $urlValidation = $this->validateUrlFormat($url);
            if (!$urlValidation->isValid()) {
                return $urlValidation;
            }

            // Step 2: Parse and normalize URL
            $parsedUrl = $this->parseAndNormalizeUrl($url);
            if (!$parsedUrl) {
                return new UrlValidationResult(false, 'Invalid URL format');
            }

            // Step 3: Protocol validation
            if (!in_array(strtolower($parsedUrl['scheme']), self::ALLOWED_SCHEMES)) {
                return new UrlValidationResult(false, 'Invalid URL format');
            }

            // Step 4: Hostname validation
            $hostnameValidation = $this->validateHostname($parsedUrl['host']);
            if (!$hostnameValidation->isValid()) {
                return $hostnameValidation;
            }

            // Step 5: DNS security validation (atomic resolution + IP validation)
            $dnsValidation = $this->validateDnsSecurity($parsedUrl['host']);
            if (!$dnsValidation->isValid()) {
                return $dnsValidation;
            }

            return new UrlValidationResult(true, 'URL is valid');

        } catch (\Exception $e) {
            return new UrlValidationResult(false, 'URL validation failed');
        }
    }

    private function validateUrlFormat(string $url): UrlValidationResult
    {
        // Basic URL format validation
        if (empty($url) || !is_string($url)) {
            return new UrlValidationResult(false, 'Invalid URL format');
        }

        // Length check (prevent extremely long URLs)
        if (strlen($url) > 2048) {
            return new UrlValidationResult(false, 'Invalid URL format');
        }

        // Basic URL validation
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return new UrlValidationResult(false, 'Invalid URL format');
        }

        return new UrlValidationResult(true, 'URL format is valid');
    }

    private function parseAndNormalizeUrl(string $url): ?array
    {
        // Parse URL
        $parsed = parse_url($url);
        if (!$parsed || !isset($parsed['scheme']) || !isset($parsed['host'])) {
            return null;
        }

        // Normalize scheme to lowercase
        $parsed['scheme'] = strtolower($parsed['scheme']);

        // Normalize host to lowercase and decode
        $parsed['host'] = strtolower($this->normalizeHostname($parsed['host']));

        // Set default port if not specified
        if (!isset($parsed['port'])) {
            $parsed['port'] = ($parsed['scheme'] === 'https') ? 443 : 80;
        }

        return $parsed;
    }

    private function normalizeHostname(string $hostname): string
    {
        // URL decode the hostname
        $hostname = urldecode($hostname);

        // Handle Unicode normalization
        if (function_exists('normalizer_normalize')) {
            $hostname = normalizer_normalize($hostname, \Normalizer::FORM_C);
        }

        return $hostname;
    }

    private function validateHostname(string $hostname): UrlValidationResult
    {
        // Check for empty hostname
        if (empty($hostname)) {
            return new UrlValidationResult(false, 'Invalid URL format');
        }

        // Check for localhost variants
        $localhostVariants = [
            'localhost', '0.0.0.0', '0', '127.0.0.1', '::1',
            'localhost.localdomain', 'local', 'broadcasthost'
        ];

        if (in_array(strtolower($hostname), $localhostVariants)) {
            return new UrlValidationResult(false, 'Access to private IPs not allowed');
        }

        // Check if hostname is an IP address
        if (filter_var($hostname, FILTER_VALIDATE_IP)) {
            return $this->validateIpAddress($hostname);
        }

        // Validate domain name format
        if (!$this->isValidDomainName($hostname)) {
            return new UrlValidationResult(false, 'Invalid URL format');
        }

        return new UrlValidationResult(true, 'Hostname is valid');
    }

    private function validateIpAddress(string $ip): UrlValidationResult
    {
        // Validate IPv4
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            if ($this->isPrivateIpv4($ip)) {
                return new UrlValidationResult(false, 'Access to private IPs not allowed');
            }
            return new UrlValidationResult(true, 'IP address is valid');
        }

        // Validate IPv6
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            if ($this->isPrivateIpv6($ip)) {
                return new UrlValidationResult(false, 'Access to private IPs not allowed');
            }
            return new UrlValidationResult(true, 'IP address is valid');
        }

        return new UrlValidationResult(false, 'Invalid IP address');
    }

    private function isPrivateIpv4(string $ip): bool
    {
        $ipLong = ip2long($ip);
        if ($ipLong === false) {
            return true; // Treat invalid IPs as private for safety
        }

        foreach (self::PRIVATE_IPV4_RANGES as $range) {
            $rangeLow = ip2long($range[0]);
            $rangeHigh = ip2long($range[1]);
            if ($ipLong >= $rangeLow && $ipLong <= $rangeHigh) {
                return true;
            }
        }

        return false;
    }

    private function isPrivateIpv6(string $ip): bool
    {
        // Convert to binary for comparison
        $ipBinary = inet_pton($ip);
        if ($ipBinary === false) {
            return true; // Treat invalid IPs as private for safety
        }

        foreach (self::PRIVATE_IPV6_RANGES as $cidr) {
            if ($this->ipv6InRange($ip, $cidr)) {
                return true;
            }
        }

        return false;
    }

    private function ipv6InRange(string $ip, string $cidr): bool
    {
        list($subnet, $prefixLength) = explode('/', $cidr);
        
        $ipBinary = inet_pton($ip);
        $subnetBinary = inet_pton($subnet);
        
        if ($ipBinary === false || $subnetBinary === false) {
            return true; // Treat invalid IPs as private for safety
        }

        // Calculate the mask
        $maskBytes = intval($prefixLength / 8);
        $maskBits = $prefixLength % 8;

        // Compare byte by byte
        for ($i = 0; $i < $maskBytes; $i++) {
            if ($ipBinary[$i] !== $subnetBinary[$i]) {
                return false;
            }
        }

        // Compare remaining bits if any
        if ($maskBits > 0 && $maskBytes < 16) {
            $mask = 0xFF << (8 - $maskBits);
            $ipByte = ord($ipBinary[$maskBytes]) & $mask;
            $subnetByte = ord($subnetBinary[$maskBytes]) & $mask;
            if ($ipByte !== $subnetByte) {
                return false;
            }
        }

        return true;
    }

    private function isValidDomainName(string $domain): bool
    {
        // Basic domain name validation
        if (strlen($domain) > 253) {
            return false;
        }

        // Check for valid characters and structure
        if (!preg_match('/^[a-zA-Z0-9]([a-zA-Z0-9\-]{0,61}[a-zA-Z0-9])?(\.[a-zA-Z0-9]([a-zA-Z0-9\-]{0,61}[a-zA-Z0-9])?)*$/', $domain)) {
            return false;
        }

        return true;
    }

    private function validateDnsSecurity(string $hostname): UrlValidationResult
    {
        // Skip DNS validation for IP addresses (already validated)
        if (filter_var($hostname, FILTER_VALIDATE_IP)) {
            return new UrlValidationResult(true, 'IP address already validated');
        }

        try {
            // Perform atomic DNS resolution and validation
            $dnsRecords = $this->resolveDnsAtomic($hostname);
            
            if (empty($dnsRecords)) {
                return new UrlValidationResult(false, 'DNS resolution failed');
            }

            // Validate each resolved IP
            foreach ($dnsRecords as $ip) {
                $ipValidation = $this->validateIpAddress($ip);
                if (!$ipValidation->isValid()) {
                    return new UrlValidationResult(false, 'Access to private IPs not allowed');
                }
            }

            return new UrlValidationResult(true, 'DNS validation passed');

        } catch (\Exception $e) {
            return new UrlValidationResult(false, 'DNS validation failed');
        }
    }

    private function resolveDnsAtomic(string $hostname): array
    {
        $ips = [];

        // Get A records (IPv4)
        $aRecords = dns_get_record($hostname, DNS_A);
        if ($aRecords) {
            foreach ($aRecords as $record) {
                if (isset($record['ip'])) {
                    $ips[] = $record['ip'];
                }
            }
        }

        // Get AAAA records (IPv6)
        $aaaaRecords = dns_get_record($hostname, DNS_AAAA);
        if ($aaaaRecords) {
            foreach ($aaaaRecords as $record) {
                if (isset($record['ipv6'])) {
                    $ips[] = $record['ipv6'];
                }
            }
        }

        return $ips;
    }

    public function getSecureHttpClientOptions(): array
    {
        return [
            'timeout' => self::TOTAL_TIMEOUT,
            'max_duration' => self::TOTAL_TIMEOUT,
            'headers' => [
                'User-Agent' => 'RSS Reader/1.0',
                'Accept' => 'application/rss+xml, application/atom+xml, application/xml, text/xml',
            ],
            'max_redirects' => self::MAX_REDIRECTS,
            'resolve' => [], // Will be populated with validated IPs if needed
        ];
    }

    public function getMaxResponseSize(): int
    {
        return self::MAX_RESPONSE_SIZE;
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