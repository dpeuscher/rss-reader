# RSS Reader Security Implementation

## Overview

This document describes the security features implemented to protect against SSRF (Server-Side Request Forgery) attacks and other security vulnerabilities in the RSS Reader application's FeedParserService.

## Security Features Implemented

### 1. URL Security Validator (`UrlSecurityValidator`)

**Location**: `src/Service/UrlSecurityValidator.php`

**Features**:
- URL format validation and normalization
- URL scheme restriction (HTTP/HTTPS only)
- Internal IP address blocking (IPv4 and IPv6)
- Domain allowlist/blocklist support
- Port validation and blocking of dangerous ports
- URL encoding bypass prevention
- Security event logging

**Blocked IP Ranges**:
- IPv4: `127.0.0.0/8`, `10.0.0.0/8`, `172.16.0.0/12`, `192.168.0.0/16`, `169.254.0.0/16`, `0.0.0.0/8`, `224.0.0.0/4`, `240.0.0.0/4`
- IPv6: `::1/128`, `fe80::/10`, `fc00::/7`, `ff00::/8`

**Blocked Ports**:
- SSH (22), Telnet (23), SMTP (25), DNS (53)
- Database ports: MySQL (3306), PostgreSQL (5432), SQL Server (1433), MongoDB (27017)
- Cache services: Redis (6379), Memcached (11211)
- Email services: POP3 (110), IMAP (143), IMAPS (993), POP3S (995)

### 2. Secure HTTP Client (`SecureHttpClient`)

**Location**: `src/Service/SecureHttpClient.php`

**Features**:
- HTTP redirect validation to prevent redirect-based SSRF
- Response size limits (10MB default)
- Request timeout controls (30 seconds default)
- Strict HTTPS certificate validation
- Manual redirect handling with security checks
- Relative URL resolution security

### 3. Enhanced Feed Parser Service

**Location**: `src/Service/FeedParserService.php`

**Changes**:
- Integrated URL security validation before all HTTP requests
- Replaced direct HTTP client usage with SecureHttpClient
- Added comprehensive security event logging
- Enhanced error handling for security violations

### 4. Configuration System

**Location**: `config/feed-security.json`

**Environment Variables**:
- `RSS_ALLOWED_DOMAINS`: Comma-separated list of allowed domains
- `RSS_BLOCKED_DOMAINS`: Comma-separated list of blocked domains

**Configuration Options**:
```json
{
    "allowed_domains": [],
    "blocked_domains": ["localhost", "127.0.0.1", "*.local"],
    "max_response_size": 10485760,
    "timeout": 30,
    "max_redirects": 5
}
```

## Security Protections

### 1. SSRF Attack Prevention
- Blocks access to internal IP addresses and localhost
- Validates all redirect URLs
- Prevents DNS rebinding attacks through IP validation at connection time

### 2. URL Scheme Restrictions
- Only allows HTTP and HTTPS protocols
- Blocks dangerous schemes like `file://`, `ftp://`, `gopher://`

### 3. Encoding Bypass Prevention
- URL normalization prevents double encoding attacks
- Handles mixed case domains and unicode (IDN) domains
- Recursive URL decoding detection

### 4. Request Size and Timeout Limits
- Maximum response size limit (configurable, default 10MB)
- Connection timeout (configurable, default 30 seconds)
- Maximum request duration including redirects

### 5. Redirect Security
- Manual redirect handling with validation
- Limits number of redirects (max 5)
- Validates each redirect URL against security rules

### 6. Security Logging
- Logs all security violations with context
- Includes violation type, URL, IP address, and user agent
- Integrates with Symfony's logging framework

## Usage Examples

### Basic Feed Validation
```php
$validator = new UrlSecurityValidator($logger);
$result = $validator->validateUrl('https://example.com/feed.xml');

if ($result->isValid()) {
    $normalizedUrl = $result->getNormalizedUrl();
    // Proceed with feed processing
} else {
    // Handle security violation
    echo $result->getMessage();
}
```

### Secure HTTP Request
```php
$secureClient = new SecureHttpClient($httpClient, $urlValidator, $logger);
$response = $secureClient->request('GET', 'https://example.com/feed.xml');

if ($response->isSuccess()) {
    $content = $response->getContent();
    // Process response
} else {
    // Handle error
    echo $response->getError();
}
```

## Security Testing

### Test Cases Covered
1. **Internal IP Blocking**: Verifies that URLs pointing to localhost, private networks, and link-local addresses are blocked
2. **Scheme Validation**: Ensures only HTTP/HTTPS schemes are allowed
3. **Redirect Validation**: Tests that redirects to internal addresses are blocked
4. **Encoding Bypass**: Validates that double encoding and other bypass techniques are prevented
5. **Response Size Limits**: Confirms that oversized responses are rejected
6. **Timeout Handling**: Verifies that long-running requests are terminated

### Attack Scenarios Prevented
- `http://127.0.0.1:8080/admin` - Localhost access
- `http://192.168.1.1/config` - Private network access
- `http://169.254.169.254/latest/meta-data/` - Cloud metadata access
- `file:///etc/passwd` - File system access
- `http://evil.com/redirect-to-localhost` - Redirect-based SSRF
- `http://127.0.0.%31/` - URL encoding bypass

## Configuration Recommendations

### Production Environment
1. Set strict domain allowlists in production
2. Monitor security logs for attack attempts
3. Consider implementing rate limiting
4. Regularly review and update blocked domain lists

### Environment Variables
```bash
# Example production configuration
RSS_ALLOWED_DOMAINS="rss.cnn.com,feeds.bbci.co.uk,rss.nytimes.com"
RSS_BLOCKED_DOMAINS="localhost,127.0.0.1,*.local,*.internal"
```

## Compliance

This implementation addresses:
- **OWASP Top 10 2021 - A10**: Server-Side Request Forgery (SSRF)
- **CWE-918**: Server-Side Request Forgery (SSRF)
- **NIST Cybersecurity Framework**: Security controls and logging

## Future Enhancements

1. **Rate Limiting**: Implement per-IP rate limiting for feed requests
2. **Certificate Pinning**: Add certificate pinning for critical RSS sources
3. **DNS Caching**: Implement intelligent DNS caching for performance
4. **Admin Dashboard**: Create web interface for security monitoring
5. **Machine Learning**: Add ML-based suspicious pattern detection