<?php

namespace App\Service;

class UrlSecurityValidator
{
    private const ALLOWED_SCHEMES = ['http', 'https'];
    private const MAX_REDIRECTS = 3;
    private const CONNECTION_TIMEOUT = 5;
    private const READ_TIMEOUT = 10;
    private const TOTAL_TIMEOUT = 15;
    private const MAX_RESPONSE_SIZE = 5 * 1024 * 1024; // 5MB

    private const PRIVATE_IPV4_RANGES = [
        ['127.0.0.0', '127.255.255.255'],     // Loopback
        ['10.0.0.0', '10.255.255.255'],       // Private Class A
        ['172.16.0.0', '172.31.255.255'],     // Private Class B
        ['192.168.0.0', '192.168.255.255'],   // Private Class C
        ['169.254.0.0', '169.254.255.255'],   // Link-local (AWS metadata)
        ['0.0.0.0', '0.255.255.255'],         // Current network
        ['224.0.0.0', '255.255.255.255'],     // Multicast and reserved
    ];

    private const PRIVATE_IPV6_PREFIXES = [
        '::1',          // Loopback
        'fe80::/10',    // Link-local
        'fc00::/7',     // Unique local addresses
        'ff00::/8',     // Multicast
        '::ffff:',      // IPv4-mapped IPv6 prefix
    ];

    public function validateUrl(string $url): UrlValidationResult
    {
        // Step 1: Basic URL format validation
        $normalizedUrl = $this->normalizeUrl($url);
        if (!$normalizedUrl) {
            return new UrlValidationResult(false, 'Invalid URL format');
        }

        // Step 2: Parse URL components
        $parsedUrl = parse_url($normalizedUrl);
        if (!$parsedUrl || !isset($parsedUrl['scheme'], $parsedUrl['host'])) {
            return new UrlValidationResult(false, 'Invalid URL format');
        }

        // Step 3: Validate scheme
        if (!in_array(strtolower($parsedUrl['scheme']), self::ALLOWED_SCHEMES, true)) {
            return new UrlValidationResult(false, 'Invalid URL format');
        }

        // Step 4: Validate host and perform DNS security check
        $dnsResult = $this->validateHostSecurity($parsedUrl['host']);
        if (!$dnsResult->isValid()) {
            return new UrlValidationResult(false, $dnsResult->getMessage());
        }

        // Step 5: Additional URL encoding and bypass checks
        $bypassResult = $this->checkBypassAttempts($normalizedUrl, $parsedUrl);
        if (!$bypassResult->isValid()) {
            return new UrlValidationResult(false, $bypassResult->getMessage());
        }

        return new UrlValidationResult(true, 'URL is valid', $normalizedUrl);
    }

    private function normalizeUrl(string $url): ?string
    {
        // Remove any whitespace
        $url = trim($url);
        
        // Decode URL encoding to prevent bypass attempts
        $decoded = urldecode($url);
        
        // Check for multiple encoding layers
        $previousDecoded = '';
        $iterations = 0;
        while ($decoded !== $previousDecoded && $iterations < 5) {
            $previousDecoded = $decoded;
            $decoded = urldecode($decoded);
            $iterations++;
        }
        
        // Unicode normalization to prevent bypass
        if (class_exists('Normalizer')) {
            $decoded = \Normalizer::normalize($decoded, \Normalizer::FORM_C);
        }

        // Basic URL validation
        if (!filter_var($decoded, FILTER_VALIDATE_URL)) {
            return null;
        }

        return $decoded;
    }

    private function validateHostSecurity(string $host): UrlValidationResult
    {
        // Step 1: Direct IP validation if host is an IP address
        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            if ($this->isPrivateIPv4($host)) {
                return new UrlValidationResult(false, 'Access to private IPs not allowed');
            }
            return new UrlValidationResult(true, 'IPv4 address is valid');
        }

        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            if ($this->isPrivateIPv6($host)) {
                return new UrlValidationResult(false, 'Access to private IPs not allowed');
            }
            return new UrlValidationResult(true, 'IPv6 address is valid');
        }

        // Step 2: Atomic DNS resolution and validation
        return $this->performAtomicDnsValidation($host);
    }

    private function performAtomicDnsValidation(string $host): UrlValidationResult
    {
        try {
            // Resolve both A and AAAA records
            $aRecords = @dns_get_record($host, DNS_A);
            $aaaaRecords = @dns_get_record($host, DNS_AAAA);
            
            if (empty($aRecords) && empty($aaaaRecords)) {
                return new UrlValidationResult(false, 'Domain resolution failed');
            }

            // Check all resolved IPv4 addresses
            if ($aRecords) {
                foreach ($aRecords as $record) {
                    if (isset($record['ip']) && $this->isPrivateIPv4($record['ip'])) {
                        return new UrlValidationResult(false, 'Access to private IPs not allowed');
                    }
                }
            }

            // Check all resolved IPv6 addresses
            if ($aaaaRecords) {
                foreach ($aaaaRecords as $record) {
                    if (isset($record['ipv6']) && $this->isPrivateIPv6($record['ipv6'])) {
                        return new UrlValidationResult(false, 'Access to private IPs not allowed');
                    }
                }
            }

            return new UrlValidationResult(true, 'DNS validation passed');
        } catch (\Exception $e) {
            return new UrlValidationResult(false, 'DNS resolution error');
        }
    }

    private function isPrivateIPv4(string $ip): bool
    {
        $ipLong = ip2long($ip);
        if ($ipLong === false) {
            return true; // Invalid IP, treat as private for security
        }

        foreach (self::PRIVATE_IPV4_RANGES as [$start, $end]) {
            $startLong = ip2long($start);
            $endLong = ip2long($end);
            if ($ipLong >= $startLong && $ipLong <= $endLong) {
                return true;
            }
        }

        return false;
    }

    private function isPrivateIPv6(string $ip): bool
    {
        // Remove brackets if present
        $ip = trim($ip, '[]');
        
        // Check for IPv4-mapped IPv6 addresses (::ffff:192.168.1.1)
        if (strpos($ip, '::ffff:') === 0) {
            $ipv4Part = substr($ip, 7);
            // Check if it's dotted decimal notation
            if (strpos($ipv4Part, '.') !== false) {
                return $this->isPrivateIPv4($ipv4Part);
            }
            // Check if it's hex notation
            if (strlen($ipv4Part) === 8) {
                $octets = str_split($ipv4Part, 2);
                $dottedDecimal = implode('.', array_map('hexdec', $octets));
                return $this->isPrivateIPv4($dottedDecimal);
            }
        }

        // Check standard IPv6 private ranges
        foreach (self::PRIVATE_IPV6_PREFIXES as $prefix) {
            if ($prefix === '::1' && $ip === '::1') {
                return true;
            }
            if (strpos($prefix, '/') !== false) {
                [$network, $prefixLength] = explode('/', $prefix);
                if ($this->ipv6InSubnet($ip, $network, (int)$prefixLength)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function ipv6InSubnet(string $ip, string $network, int $prefixLength): bool
    {
        $ipBinary = inet_pton($ip);
        $networkBinary = inet_pton($network);
        
        if ($ipBinary === false || $networkBinary === false) {
            return true; // Invalid IP, treat as private for security
        }

        $bytesToCheck = intval($prefixLength / 8);
        $bitsToCheck = $prefixLength % 8;

        // Check full bytes
        for ($i = 0; $i < $bytesToCheck; $i++) {
            if ($ipBinary[$i] !== $networkBinary[$i]) {
                return false;
            }
        }

        // Check remaining bits
        if ($bitsToCheck > 0 && $bytesToCheck < strlen($ipBinary)) {
            $mask = 0xFF << (8 - $bitsToCheck);
            if ((ord($ipBinary[$bytesToCheck]) & $mask) !== (ord($networkBinary[$bytesToCheck]) & $mask)) {
                return false;
            }
        }

        return true;
    }

    private function checkBypassAttempts(string $url, array $parsedUrl): UrlValidationResult
    {
        // Check for suspicious URL patterns that might indicate bypass attempts
        $suspiciousPatterns = [
            '/\b(?:0x[0-9a-f]+|0[0-7]+|\d+)\b/i', // Hex, octal, or decimal IP encoding
            '/[%@]/',                              // URL encoding or unusual characters
            '/\\\/',                               // Backslash in URL
            '/[\x00-\x1f\x7f-\x9f]/',            // Control characters
        ];

        foreach ($suspiciousPatterns as $pattern) {
            if (preg_match($pattern, $url)) {
                // Additional validation for potentially suspicious URLs
                if (!$this->validateSuspiciousUrl($url, $parsedUrl)) {
                    return new UrlValidationResult(false, 'Invalid URL format');
                }
            }
        }

        return new UrlValidationResult(true, 'Bypass checks passed');
    }

    private function validateSuspiciousUrl(string $url, array $parsedUrl): bool
    {
        // For now, implement conservative approach
        // Could be extended with more sophisticated analysis
        
        // Check if host contains only valid characters
        $host = $parsedUrl['host'] ?? '';
        if (!preg_match('/^[a-zA-Z0-9.-]+$/', $host)) {
            return false;
        }

        return true;
    }

    public function getSecureHttpClientOptions(): array
    {
        return [
            'timeout' => self::TOTAL_TIMEOUT,
            'max_duration' => self::TOTAL_TIMEOUT,
            'max_redirects' => self::MAX_REDIRECTS,
            'headers' => [
                'User-Agent' => 'RSS Reader/1.0 (Security-Enhanced)',
            ],
            'on_progress' => function ($dlNow, $dlSize, $upNow, $upSize): void {
                if ($dlSize > self::MAX_RESPONSE_SIZE || $dlNow > self::MAX_RESPONSE_SIZE) {
                    throw new \RuntimeException('Response size limit exceeded');
                }
            },
        ];
    }
}

class UrlValidationResult
{
    private bool $valid;
    private string $message;
    private ?string $validatedUrl;

    public function __construct(bool $valid, string $message, ?string $validatedUrl = null)
    {
        $this->valid = $valid;
        $this->message = $message;
        $this->validatedUrl = $validatedUrl;
    }

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