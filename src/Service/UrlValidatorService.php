<?php

namespace App\Service;

use Psr\Log\LoggerInterface;

class UrlValidatorService implements UrlValidatorInterface
{
    private array $allowedSchemes = ['http', 'https'];
    private array $blockedIpRanges = [
        '127.0.0.0/8',    // localhost
        '10.0.0.0/8',     // private networks
        '172.16.0.0/12',  // private networks
        '192.168.0.0/16', // private networks
        '169.254.0.0/16', // link-local
    ];
    
    private array $blockedIpv6Ranges = [
        '::1',         // localhost
        'fc00::/7',    // unique local addresses
        'fe80::/10',   // link-local
    ];

    public function __construct(
        private LoggerInterface $logger
    ) {}

    public function validateFeedUrl(string $url): bool
    {
        try {
            // Validate URL format
            if (!filter_var($url, FILTER_VALIDATE_URL)) {
                $this->logger->warning('Invalid URL format', ['url' => $url]);
                return false;
            }
            
            $parsed = parse_url($url);
            
            // Only allow HTTP/HTTPS
            if (!isset($parsed['scheme']) || !in_array($parsed['scheme'], $this->allowedSchemes)) {
                $this->logger->warning('Invalid URL scheme', ['url' => $url, 'scheme' => $parsed['scheme'] ?? 'null']);
                return false;
            }
            
            // Must have a host
            if (!isset($parsed['host']) || empty($parsed['host'])) {
                $this->logger->warning('URL missing host', ['url' => $url]);
                return false;
            }
            
            $host = $parsed['host'];
            
            // Check if host is an IP address
            if (filter_var($host, FILTER_VALIDATE_IP)) {
                $isAllowed = $this->isAllowedIp($host);
                if (!$isAllowed) {
                    $this->logger->warning('Blocked IP address detected', ['url' => $url, 'ip' => $host]);
                }
                return $isAllowed;
            }
            
            // For hostnames, validate format and check against blocklist
            if (!$this->isValidHostname($host)) {
                $this->logger->warning('Invalid hostname format', ['url' => $url, 'hostname' => $host]);
                return false;
            }
            
            // Additional validation: resolve hostname safely and check resulting IPs
            $ips = $this->resolveDnsSafely($host);
            if (empty($ips)) {
                $this->logger->warning('Unable to resolve hostname', ['url' => $url, 'hostname' => $host]);
                return false;
            }
            
            foreach ($ips as $ip) {
                if (!$this->isAllowedIp($ip)) {
                    $this->logger->warning('Hostname resolves to blocked IP', [
                        'url' => $url, 
                        'hostname' => $host, 
                        'resolved_ip' => $ip
                    ]);
                    return false;
                }
            }
            
            $this->logger->info('URL validation passed', ['url' => $url, 'resolved_ips' => $ips]);
            return true;
            
        } catch (\Exception $e) {
            $this->logger->error('URL validation failed with exception', [
                'url' => $url,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }
    
    private function isAllowedIp(string $ip): bool
    {
        // Check IPv4 private/reserved ranges using PHP's built-in filters
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            // Use NO_PRIV_RANGE and NO_RES_RANGE filters to block private and reserved ranges
            return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
        }
        
        // Check IPv6 private ranges
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            foreach ($this->blockedIpv6Ranges as $range) {
                if ($this->isIpInRange($ip, $range)) {
                    return false;
                }
            }
            return true;
        }
        
        return false;
    }
    
    private function isValidHostname(string $hostname): bool
    {
        // Basic hostname validation including IDN support
        if (function_exists('idn_to_ascii')) {
            $hostname = idn_to_ascii($hostname, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
            if ($hostname === false) {
                return false;
            }
        }
        
        // Validate hostname format and length
        return preg_match('/^[a-zA-Z0-9.-]+$/', $hostname) && strlen($hostname) <= 253 && strlen($hostname) > 0;
    }
    
    private function resolveDnsSafely(string $hostname): array
    {
        // Use dns_get_record with timeout instead of gethostbyname to prevent DNS poisoning
        $records = @dns_get_record($hostname, DNS_A | DNS_AAAA);
        $ips = [];
        
        if ($records) {
            foreach ($records as $record) {
                if (isset($record['ip'])) {
                    $ips[] = $record['ip'];
                } elseif (isset($record['ipv6'])) {
                    $ips[] = $record['ipv6'];
                }
            }
        }
        
        return $ips;
    }
    
    private function isIpInRange(string $ip, string $range): bool
    {
        // IPv6 range checking implementation
        if (strpos($range, '/') !== false) {
            [$subnet, $mask] = explode('/', $range);
            $ipBin = inet_pton($ip);
            $subnetBin = inet_pton($subnet);
            
            if ($ipBin === false || $subnetBin === false) {
                return false;
            }
            
            $mask = (int) $mask;
            if ($mask < 0 || $mask > 128) {
                return false;
            }
            
            // Create mask
            $maskBin = str_repeat("\xFF", intval($mask / 8));
            if ($mask % 8 !== 0) {
                $maskBin .= chr(0xFF << (8 - ($mask % 8)));
            }
            $maskBin = str_pad($maskBin, strlen($ipBin), "\x00");
            
            return ($ipBin & $maskBin) === ($subnetBin & $maskBin);
        }
        
        return $ip === $range;
    }
}