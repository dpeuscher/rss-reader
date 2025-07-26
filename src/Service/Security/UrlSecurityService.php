<?php

namespace App\Service\Security;

class UrlSecurityService implements UrlSecurityServiceInterface
{
    private const MAX_URL_LENGTH = 2048;
    private const ALLOWED_SCHEMES = ['http', 'https'];
    
    // RFC 1918 Private IP ranges
    private const PRIVATE_IP_RANGES = [
        ['10.0.0.0', '10.255.255.255'],
        ['172.16.0.0', '172.31.255.255'],
        ['192.168.0.0', '192.168.255.255'],
        // Localhost ranges
        ['127.0.0.0', '127.255.255.255'],
        // Link-local
        ['169.254.0.0', '169.254.255.255'],
    ];
    
    // IPv6 blocked ranges
    private const BLOCKED_IPV6_RANGES = [
        '::1/128',        // Loopback
        'fe80::/10',      // Link-local
        'fc00::/7',       // Unique local addresses
        '::ffff:0:0/96',  // IPv4-mapped IPv6
    ];

    public function validateUrl(string $url): UrlValidationResult
    {
        // Normalize URL first
        $normalizedUrl = $this->normalizeUrl($url);
        
        // Check URL length
        if (strlen($normalizedUrl) > self::MAX_URL_LENGTH) {
            return UrlValidationResult::invalid(
                'URL length exceeds maximum allowed length of ' . self::MAX_URL_LENGTH . ' characters',
                ['length_violation']
            );
        }

        // Parse URL
        $parsedUrl = parse_url($normalizedUrl);
        if ($parsedUrl === false || !isset($parsedUrl['scheme']) || !isset($parsedUrl['host'])) {
            return UrlValidationResult::invalid(
                'Invalid URL format',
                ['invalid_format']
            );
        }

        // Validate scheme
        if (!in_array(strtolower($parsedUrl['scheme']), self::ALLOWED_SCHEMES, true)) {
            return UrlValidationResult::invalid(
                'Only HTTP and HTTPS schemes are allowed',
                ['invalid_scheme']
            );
        }

        // Validate host
        $hostValidation = $this->validateHost($parsedUrl['host']);
        if (!$hostValidation->isValid()) {
            return $hostValidation;
        }

        return UrlValidationResult::valid();
    }
    
    public function isUrlSafe(string $url): bool
    {
        return $this->validateUrl($url)->isValid();
    }

    private function normalizeUrl(string $url): string
    {
        // Decode URL-encoded characters
        $url = urldecode($url);
        
        // Remove extra whitespace
        $url = trim($url);
        
        // Normalize multiple slashes in path (but not in protocol)
        $url = preg_replace('#(?<!:)//+#', '/', $url);
        
        return $url;
    }

    private function validateHost(string $host): UrlValidationResult
    {
        // Check if host is an IP address
        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return $this->validateIPv4($host);
        }
        
        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            return $this->validateIPv6($host);
        }

        // Domain name validation
        return $this->validateDomain($host);
    }

    private function validateIPv4(string $ip): UrlValidationResult
    {
        // Check against private IP ranges
        foreach (self::PRIVATE_IP_RANGES as [$start, $end]) {
            if ($this->ipInRange($ip, $start, $end)) {
                return UrlValidationResult::invalid(
                    'Access to private/internal IP addresses is not allowed',
                    ['private_ip']
                );
            }
        }

        return UrlValidationResult::valid();
    }

    private function validateIPv6(string $ip): UrlValidationResult
    {
        // Remove brackets if present
        $ip = trim($ip, '[]');
        
        // Check against blocked IPv6 ranges
        foreach (self::BLOCKED_IPV6_RANGES as $range) {
            if ($this->ipv6InRange($ip, $range)) {
                return UrlValidationResult::invalid(
                    'Access to private/internal IPv6 addresses is not allowed',
                    ['private_ipv6']
                );
            }
        }

        return UrlValidationResult::valid();
    }
    
    private function validateDomain(string $domain): UrlValidationResult
    {
        // Basic domain validation
        if (!preg_match('/^[a-zA-Z0-9]([a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?(\.[a-zA-Z0-9]([a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?)*$/', $domain)) {
            return UrlValidationResult::invalid(
                'Invalid domain name format',
                ['invalid_domain']
            );
        }

        // Resolve domain to IP addresses and validate them
        try {
            $ips = $this->resolveDomainToIPs($domain);
            foreach ($ips as $ip) {
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                    $ipValidation = $this->validateIPv4($ip);
                } elseif (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
                    $ipValidation = $this->validateIPv6($ip);
                } else {
                    continue; // Skip invalid IPs
                }
                
                if (!$ipValidation->isValid()) {
                    return UrlValidationResult::invalid(
                        'Domain resolves to a blocked IP address: ' . $ip,
                        ['dns_resolution_blocked']
                    );
                }
            }
        } catch (\Exception $e) {
            return UrlValidationResult::invalid(
                'DNS resolution failed: ' . $e->getMessage(),
                ['dns_resolution_failed']
            );
        }

        return UrlValidationResult::valid();
    }

    private function resolveDomainToIPs(string $domain): array
    {
        $ips = [];
        
        // Get A records (IPv4)
        $aRecords = dns_get_record($domain, DNS_A);
        if ($aRecords !== false) {
            foreach ($aRecords as $record) {
                if (isset($record['ip'])) {
                    $ips[] = $record['ip'];
                }
            }
        }
        
        // Get AAAA records (IPv6)
        $aaaaRecords = dns_get_record($domain, DNS_AAAA);
        if ($aaaaRecords !== false) {
            foreach ($aaaaRecords as $record) {
                if (isset($record['ipv6'])) {
                    $ips[] = $record['ipv6'];
                }
            }
        }
        
        if (empty($ips)) {
            throw new \Exception('No IP addresses resolved for domain: ' . $domain);
        }
        
        return $ips;
    }

    private function ipInRange(string $ip, string $startIP, string $endIP): bool
    {
        $ipLong = ip2long($ip);
        $startLong = ip2long($startIP);
        $endLong = ip2long($endIP);
        
        return $ipLong !== false && $startLong !== false && $endLong !== false 
            && $ipLong >= $startLong && $ipLong <= $endLong;
    }
    
    private function ipv6InRange(string $ip, string $cidr): bool
    {
        [$network, $prefixLength] = explode('/', $cidr);
        
        $ipBinary = inet_pton($ip);
        $networkBinary = inet_pton($network);
        
        if ($ipBinary === false || $networkBinary === false) {
            return false;
        }
        
        $bytesToCheck = intval($prefixLength / 8);
        $bitsToCheck = $prefixLength % 8;
        
        // Check full bytes
        if ($bytesToCheck > 0 && substr($ipBinary, 0, $bytesToCheck) !== substr($networkBinary, 0, $bytesToCheck)) {
            return false;
        }
        
        // Check remaining bits
        if ($bitsToCheck > 0 && $bytesToCheck < 16) {
            $ipByte = ord($ipBinary[$bytesToCheck]);
            $networkByte = ord($networkBinary[$bytesToCheck]);
            $mask = 0xFF << (8 - $bitsToCheck);
            
            if (($ipByte & $mask) !== ($networkByte & $mask)) {
                return false;
            }
        }
        
        return true;
    }
}