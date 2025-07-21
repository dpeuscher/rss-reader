# RSS Reader - Claude Documentation

## Project Overview
This is a Symfony 7.1 RSS feed reader application that allows users to subscribe to and read RSS feeds.

## Security Implementation

### SSRF Protection (Issue #130)
**Critical security vulnerability fixed**: The application now includes comprehensive Server-Side Request Forgery (SSRF) protection.

#### Implementation Details
- **UrlValidator Service**: `src/Service/UrlValidator.php`
  - Validates URL schemes (only http/https allowed)
  - Blocks private IP ranges (RFC 1918, localhost, link-local)
  - Validates redirects to prevent bypass attacks
  - Implements URL normalization (IDN, IPv6 handling)
  - Includes caching for performance
  - Provides security logging without information leakage

- **Integration**: Modified `FeedParserService` to use URL validation before HTTP requests
- **Error Handling**: Updated to prevent information disclosure
- **Testing**: Comprehensive unit and integration tests for security boundaries

#### Blocked Resources
- Private IP ranges: 10.0.0.0/8, 172.16.0.0/12, 192.168.0.0/16
- Localhost: 127.0.0.0/8, ::1/128
- Link-local: 169.254.0.0/16, fe80::/10
- IPv6 private: fc00::/7
- Dangerous schemes: file://, ftp://, gopher://, etc.
- Cloud metadata services (AWS: 169.254.169.254)

## Development Commands

### Testing
```bash
# Run all tests
vendor/bin/phpunit

# Run unit tests only
vendor/bin/phpunit tests/Unit

# Run integration tests only  
vendor/bin/phpunit tests/Integration

# Run specific test class
vendor/bin/phpunit tests/Unit/Service/UrlValidatorTest.php
```

### Dependencies
- **PHP**: 8.2+
- **Symfony**: 7.1.*
- **Testing**: PHPUnit 11.5
- **Feed Parsing**: Laminas Feed Reader

## Architecture

### Services
- `FeedParserService`: Handles RSS feed parsing with security validation
- `UrlValidator`: Comprehensive URL security validation service

### Controllers
- `FeedController`: Manages feed subscriptions and previews
  - `/feeds/add` (POST): Add new feed subscription
  - `/feeds/{id}/preview` (GET): Preview feed content  
  - `/feeds/{id}` (DELETE): Remove feed subscription

### Security Configuration
- HTTP client timeout: 30 seconds
- Response size limit: 10MB
- Max redirects: 5
- URL validation caching: 1 hour TTL
- Security logging: No sensitive data exposure

## Security Best Practices Implemented
1. ✅ URL scheme validation (whitelist approach)
2. ✅ IP address filtering (comprehensive private range blocking)
3. ✅ Redirect validation (prevent bypass attacks)
4. ✅ Request limits (timeout, size, redirect count)
5. ✅ Error handling (no information leakage)
6. ✅ DNS rebinding protection
7. ✅ URL normalization (IDN, IPv6, encoding)
8. ✅ Performance optimization (caching)
9. ✅ Security logging (sanitized)
10. ✅ Comprehensive testing coverage

## Code Conventions
- **PSR-12**: PHP coding standards
- **Symfony**: Framework conventions and best practices
- **Security First**: All user input validation, especially URLs
- **Dependency Injection**: Services use constructor injection
- **Error Handling**: Generic error messages to prevent information disclosure
- **Testing**: Unit tests for business logic, integration tests for security boundaries

## Future Considerations
- Consider implementing rate limiting for feed validation endpoints
- Monitor security logs for attack patterns
- Regular security audits of URL validation logic
- Consider domain allowlisting for additional security layers