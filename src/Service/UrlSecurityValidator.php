<?php

namespace App\Service;

use Psr\Log\LoggerInterface;

class UrlSecurityValidator
{
    private LoggerInterface $logger;
    private array $config;

    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
        $this->config = $this->loadConfiguration();
    }

    /**
     * Validates a URL for security compliance and SSRF protection
     */
    public function validateUrl(string $url): UrlValidationResult
    {
        // Step 1: Basic URL format validation
        $normalizedUrl = $this->normalizeUrl($url);
        if (!$normalizedUrl) {
            $this->logSecurityViolation('invalid_url_format', $url);
            return new UrlValidationResult(false, 'Invalid URL format provided.');
        }

        // Step 2: Parse URL components
        $parsedUrl = parse_url($normalizedUrl);
        if (!$parsedUrl || !isset($parsedUrl['scheme'], $parsedUrl['host'])) {
            $this->logSecurityViolation('url_parse_failure', $url);
            return new UrlValidationResult(false, 'Unable to parse URL components.');
        }

        // Step 3: Validate URL scheme (only HTTP/HTTPS allowed)
        if (!$this->isSchemeAllowed($parsedUrl['scheme'])) {
            $this->logSecurityViolation('invalid_scheme', $url, ['scheme' => $parsedUrl['scheme']]);
            return new UrlValidationResult(false, 'Only HTTP and HTTPS schemes are allowed.');
        }

        // Step 4: Check domain allowlist/blocklist
        if (!$this->isDomainAllowed($parsedUrl['host'])) {
            $this->logSecurityViolation('domain_blocked', $url, ['host' => $parsedUrl['host']]);
            return new UrlValidationResult(false, 'Domain is not allowed or is blocked.');
        }

        // Step 5: Resolve hostname and check for internal IP addresses
        $ipAddress = $this->resolveHostname($parsedUrl['host']);
        if (!$ipAddress) {
            $this->logSecurityViolation('dns_resolution_failure', $url, ['host' => $parsedUrl['host']]);
            return new UrlValidationResult(false, 'Unable to resolve hostname.');
        }

        if ($this->isInternalIpAddress($ipAddress)) {
            $this->logSecurityViolation('internal_ip_blocked', $url, ['ip' => $ipAddress, 'host' => $parsedUrl['host']]);
            return new UrlValidationResult(false, 'Access to internal network addresses is not allowed.');
        }

        // Step 6: Validate port if specified
        $port = $parsedUrl['port'] ?? ($parsedUrl['scheme'] === 'https' ? 443 : 80);
        if (!$this->isPortAllowed($port)) {
            $this->logSecurityViolation('port_blocked', $url, ['port' => $port]);
            return new UrlValidationResult(false, 'Access to this port is not allowed.');
        }

        return new UrlValidationResult(true, 'URL validation successful.', $normalizedUrl);
    }

    /**
     * Validates redirect URLs to prevent redirect-based SSRF attacks
     */
    public function validateRedirectUrl(string $url, string $originalUrl): UrlValidationResult
    {
        $result = $this->validateUrl($url);
        if (!$result->isValid()) {
            $this->logSecurityViolation('redirect_url_blocked', $originalUrl, [
                'redirect_url' => $url,
                'reason' => $result->getMessage()
            ]);
            return new UrlValidationResult(false, 'Redirect URL validation failed: ' . $result->getMessage());
        }

        return $result;
    }

    /**
     * Normalizes URL to prevent encoding bypasses
     */
    private function normalizeUrl(string $url): ?string
    {
        // Remove any whitespace
        $url = trim($url);
        
        if (empty($url)) {
            return null;
        }

        // Decode URL encoding (handle double encoding)
        $previousUrl = '';
        while ($previousUrl !== $url) {
            $previousUrl = $url;
            $url = urldecode($url);
        }

        // Convert to lowercase for scheme and host
        $parsed = parse_url($url);
        if (!$parsed) {
            return null;
        }

        // Reconstruct URL with normalized components
        $scheme = isset($parsed['scheme']) ? strtolower($parsed['scheme']) : null;
        $host = isset($parsed['host']) ? strtolower($parsed['host']) : null;
        $port = $parsed['port'] ?? null;
        $path = $parsed['path'] ?? '/';
        $query = isset($parsed['query']) ? '?' . $parsed['query'] : '';
        $fragment = isset($parsed['fragment']) ? '#' . $parsed['fragment'] : '';

        if (!$scheme || !$host) {
            return null;
        }

        $normalizedUrl = $scheme . '://' . $host;
        if ($port && (($scheme === 'http' && $port !== 80) || ($scheme === 'https' && $port !== 443))) {
            $normalizedUrl .= ':' . $port;
        }
        $normalizedUrl .= $path . $query . $fragment;

        return $normalizedUrl;
    }

    /**
     * Checks if the URL scheme is allowed (HTTP/HTTPS only)
     */
    private function isSchemeAllowed(string $scheme): bool
    {
        return in_array(strtolower($scheme), ['http', 'https'], true);
    }

    /**
     * Checks if the domain is allowed based on allowlist/blocklist
     */
    private function isDomainAllowed(string $host): bool
    {
        // Check blocklist first
        if ($this->isDomainInList($host, $this->config['blocked_domains'])) {
            return false;
        }

        // If allowlist is empty, allow all (except blocked)
        if (empty($this->config['allowed_domains'])) {
            return true;
        }

        // Check allowlist
        return $this->isDomainInList($host, $this->config['allowed_domains']);
    }

    /**
     * Checks if a domain matches any pattern in the given list
     */
    private function isDomainInList(string $host, array $domainList): bool
    {
        foreach ($domainList as $pattern) {
            if ($this->matchesDomainPattern($host, $pattern)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Matches domain against pattern (supports wildcards)
     */
    private function matchesDomainPattern(string $host, string $pattern): bool
    {
        // Exact match
        if ($host === $pattern) {
            return true;
        }

        // Wildcard subdomain match (e.g., *.example.com)
        if (str_starts_with($pattern, '*.')) {
            $baseDomain = substr($pattern, 2);
            return str_ends_with($host, '.' . $baseDomain) || $host === $baseDomain;
        }

        return false;
    }

    /**
     * Resolves hostname to IP address
     */
    private function resolveHostname(string $hostname): ?string
    {
        // If it's already an IP address, validate and return it
        if (filter_var($hostname, FILTER_VALIDATE_IP)) {
            return $hostname;
        }

        // Resolve hostname to IP
        $ip = gethostbyname($hostname);
        
        // gethostbyname returns the hostname if resolution fails
        if ($ip === $hostname) {
            return null;
        }

        return $ip;
    }

    /**
     * Checks if an IP address is in internal/private range
     */
    private function isInternalIpAddress(string $ip): bool
    {
        // IPv4 private and reserved ranges
        $ipv4Ranges = [
            '127.0.0.0/8',      // Loopback
            '10.0.0.0/8',       // Private Class A
            '172.16.0.0/12',    // Private Class B
            '192.168.0.0/16',   // Private Class C
            '169.254.0.0/16',   // Link-local
            '0.0.0.0/8',        // Current network
            '224.0.0.0/4',      // Multicast
            '240.0.0.0/4',      // Reserved
        ];

        // Check IPv4
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            foreach ($ipv4Ranges as $range) {
                if ($this->ipInRange($ip, $range)) {
                    return true;
                }
            }
            return false;
        }

        // IPv6 private and reserved ranges
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $ipv6Ranges = [
                '::1/128',          // Loopback
                'fe80::/10',        // Link-local
                'fc00::/7',         // Unique local
                'ff00::/8',         // Multicast
            ];

            foreach ($ipv6Ranges as $range) {
                if ($this->ipv6InRange($ip, $range)) {
                    return true;
                }
            }
            return false;
        }

        // Invalid IP format
        return true;
    }

    /**
     * Checks if IPv4 address is in CIDR range
     */
    private function ipInRange(string $ip, string $cidr): bool
    {
        list($range, $netmask) = explode('/', $cidr, 2);
        $netmask = (int) $netmask;
        
        $ipDecimal = ip2long($ip);
        $rangeDecimal = ip2long($range);
        $wildcardDecimal = pow(2, (32 - $netmask)) - 1;
        $netmaskDecimal = ~ $wildcardDecimal;
        
        return ($ipDecimal & $netmaskDecimal) === ($rangeDecimal & $netmaskDecimal);
    }

    /**
     * Checks if IPv6 address is in CIDR range
     */
    private function ipv6InRange(string $ip, string $cidr): bool
    {
        list($range, $prefixLength) = explode('/', $cidr, 2);
        $prefixLength = (int) $prefixLength;
        
        $ipBinary = inet_pton($ip);
        $rangeBinary = inet_pton($range);
        
        if (!$ipBinary || !$rangeBinary) {
            return false;
        }
        
        $bytesToCheck = intval($prefixLength / 8);
        $bitsToCheck = $prefixLength % 8;
        
        // Check complete bytes
        if ($bytesToCheck > 0 && substr($ipBinary, 0, $bytesToCheck) !== substr($rangeBinary, 0, $bytesToCheck)) {
            return false;
        }
        
        // Check partial byte
        if ($bitsToCheck > 0) {
            $mask = 0xFF << (8 - $bitsToCheck);
            $ipByte = ord($ipBinary[$bytesToCheck]) & $mask;
            $rangeByte = ord($rangeBinary[$bytesToCheck]) & $mask;
            
            return $ipByte === $rangeByte;
        }
        
        return true;
    }

    /**
     * Checks if port is allowed
     */
    private function isPortAllowed(int $port): bool
    {
        // Block common internal service ports
        $blockedPorts = [
            22,    // SSH
            23,    // Telnet
            25,    // SMTP
            53,    // DNS
            110,   // POP3
            143,   // IMAP
            993,   // IMAPS
            995,   // POP3S
            1433,  // SQL Server
            3306,  // MySQL
            5432,  // PostgreSQL
            6379,  // Redis
            11211, // Memcached
            27017, // MongoDB
        ];

        return !in_array($port, $blockedPorts, true) && $port >= 1 && $port <= 65535;
    }

    /**
     * Loads configuration from environment variables and config files
     */
    private function loadConfiguration(): array
    {
        $config = [
            'allowed_domains' => [],
            'blocked_domains' => [],
            'max_response_size' => 10 * 1024 * 1024, // 10MB
            'timeout' => 30,
        ];

        // Load from environment variables
        if ($allowedDomains = $_ENV['RSS_ALLOWED_DOMAINS'] ?? null) {
            $config['allowed_domains'] = array_map('trim', explode(',', $allowedDomains));
        }

        if ($blockedDomains = $_ENV['RSS_BLOCKED_DOMAINS'] ?? null) {
            $config['blocked_domains'] = array_map('trim', explode(',', $blockedDomains));
        }

        // Load from config file if exists
        $configFile = __DIR__ . '/../../config/feed-security.json';
        if (file_exists($configFile)) {
            $fileConfig = json_decode(file_get_contents($configFile), true);
            if ($fileConfig) {
                $config = array_merge($config, $fileConfig);
            }
        }

        return $config;
    }

    /**
     * Logs security violations for monitoring
     */
    private function logSecurityViolation(string $violationType, string $url, array $context = []): void
    {
        $this->logger->warning('RSS Feed URL Security Violation', [
            'violation_type' => $violationType,
            'url' => $url,
            'context' => $context,
            'timestamp' => new \DateTime(),
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
        ]);
    }

    /**
     * Gets configuration values
     */
    public function getConfig(): array
    {
        return $this->config;
    }
}

class UrlValidationResult
{
    private bool $valid;
    private string $message;
    private ?string $normalizedUrl;

    public function __construct(bool $valid, string $message, ?string $normalizedUrl = null)
    {
        $this->valid = $valid;
        $this->message = $message;
        $this->normalizedUrl = $normalizedUrl;
    }

    public function isValid(): bool
    {
        return $this->valid;
    }

    public function getMessage(): string
    {
        return $this->message;
    }

    public function getNormalizedUrl(): ?string
    {
        return $this->normalizedUrl;
    }
}