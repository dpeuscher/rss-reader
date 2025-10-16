# RSS Reader Project - AI Assistant Memory

## Project Overview
This is an RSS reader application built with Symfony PHP framework. Users can subscribe to RSS feeds, view articles, and manage their subscriptions.

## Architecture
- **Framework**: Symfony 6.x
- **Database**: Doctrine ORM
- **HTTP Client**: Symfony HttpClient
- **Feed Processing**: Laminas Feed Reader
- **Logging**: Symfony Logger

## Key Components

### Security Implementation (Critical)
**IMPORTANT**: As of July 2025, comprehensive SSRF protection has been implemented to address security vulnerabilities in feed URL processing.

#### Security Services
- `UrlSecurityValidator` - Validates URLs for SSRF protection
- `SecureHttpClient` - Secure HTTP client with redirect validation and size limits
- Enhanced `FeedParserService` - Uses security services for all HTTP requests

#### Security Features
- Internal IP address blocking (IPv4/IPv6)
- URL scheme restrictions (HTTP/HTTPS only)
- HTTP redirect validation
- Response size limits (10MB default)
- Request timeouts (30s default)
- URL encoding bypass prevention
- Security event logging
- Domain allowlist/blocklist support

#### Configuration
- **File**: `config/feed-security.json`
- **Environment Variables**: `RSS_ALLOWED_DOMAINS`, `RSS_BLOCKED_DOMAINS`
- **Documentation**: `docs/SECURITY_IMPLEMENTATION.md`

#### Blocked Resources
- Internal IPs: 127.0.0.0/8, 10.0.0.0/8, 172.16.0.0/12, 192.168.0.0/16, etc.
- Dangerous ports: 22 (SSH), 3306 (MySQL), 5432 (PostgreSQL), etc.
- Non-HTTP schemes: file://, ftp://, gopher://, etc.

### Entity Structure
- **User**: Authentication and user management
- **Feed**: RSS feed metadata (URL, title, description)
- **Article**: Individual feed items
- **Subscription**: User-Feed relationship
- **UserArticle**: User-specific article state (read/unread)
- **Category**: Feed categorization

### Main Controllers
- **FeedController**: Feed management and subscription
- **MainController**: Dashboard and article viewing
- **SecurityController**: User authentication

### Core Services
- **FeedParserService**: RSS feed parsing and validation (security-enhanced)
- **UrlSecurityValidator**: URL security validation
- **SecureHttpClient**: Secure HTTP request handling

## Common Tasks

### Testing Security
```bash
# Check for SSRF vulnerabilities (should all be blocked)
curl -X POST /feeds/add -d "url=http://127.0.0.1:8080"
curl -X POST /feeds/add -d "url=file:///etc/passwd"
curl -X POST /feeds/add -d "url=http://192.168.1.1"
```

### Configuration Management
```bash
# Environment variables for domain filtering
export RSS_ALLOWED_DOMAINS="rss.cnn.com,feeds.bbci.co.uk"
export RSS_BLOCKED_DOMAINS="localhost,*.internal"
```

### Database Operations
```bash
# Run migrations
php bin/console doctrine:migrations:migrate

# Generate migration
php bin/console make:migration
```

### Development Commands
```bash
# Start development server
symfony server:start

# Clear cache
php bin/console cache:clear

# Install dependencies
composer install
```

## Security Considerations

### Critical Security Rules
1. **NEVER** bypass the UrlSecurityValidator for any URL processing
2. **ALWAYS** use SecureHttpClient instead of direct HttpClient for external requests
3. **MONITOR** security logs for attack attempts
4. **VALIDATE** all user-provided URLs through the security layer

### Security Monitoring
- Check logs for "RSS Feed URL Security Violation" messages
- Monitor for patterns of blocked internal IP access attempts
- Review security configuration regularly

### Code Patterns
```php
// CORRECT: Using security services
$validator = new UrlSecurityValidator($logger);
$result = $validator->validateUrl($userUrl);
if (!$result->isValid()) {
    throw new SecurityException($result->getMessage());
}

// INCORRECT: Direct HTTP client usage (vulnerable to SSRF)
$response = $httpClient->request('GET', $userUrl); // DON'T DO THIS
```

## Recent Changes

### July 2025 - SSRF Protection Implementation
- Implemented comprehensive SSRF protection (Issue #121)
- Added UrlSecurityValidator service
- Added SecureHttpClient service  
- Enhanced FeedParserService with security validation
- Added security configuration system
- Created security documentation

## File Locations
- Controllers: `src/Controller/`
- Services: `src/Service/`
- Entities: `src/Entity/`
- Configuration: `config/`
- Security Config: `config/feed-security.json`
- Templates: `templates/`
- Security Docs: `docs/SECURITY_IMPLEMENTATION.md`

## Dependencies
- symfony/framework-bundle
- symfony/http-client
- doctrine/orm
- laminas/laminas-feed
- symfony/security-bundle

## Notes for AI Assistants
- This project has critical security implementations that must not be bypassed
- All URL processing MUST go through the security validation layer
- Security logging is comprehensive - use it for monitoring
- Configuration is flexible through environment variables and JSON files
- When making changes to URL handling, always consider SSRF implications