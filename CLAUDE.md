# RSS Reader - Enhanced Feed Format Support

This document describes the enhanced feed format support implementation for the RSS Reader application.

## Overview

The RSS Reader now supports comprehensive feed format detection and parsing for:
- RSS 2.0
- RSS 1.0 (RDF)
- Atom 1.0
- JSON Feed 1.1

## Key Features

### 1. Feed Format Detection
- Automatic format detection based on content analysis
- Support for XML namespaces and JSON structure validation
- Robust detection logic with fallback mechanisms

### 2. Enhanced Metadata Extraction
- **Authors**: Full author information including name, email, and URL
- **Categories**: Category/tag extraction with scheme support
- **Enclosures**: Media attachments with type and size information
- **Content Types**: Distinction between HTML and text content
- **Updated Dates**: Both published and modified timestamps

### 3. JSON Feed Support
- Complete JSON Feed 1.1 specification compliance
- Security validation to prevent JSON injection attacks
- Support for JSON Feed specific fields (attachments, authors array, etc.)

### 4. Database Schema
New tables for normalized metadata storage:
- `article_author`: Author information per article
- `article_category`: Categories/tags per article  
- `article_enclosure`: Media attachments per article

New fields:
- `feed.feed_format`: Detected feed format
- `feed.language`: Feed language
- `article.content_type`: Content type (html/text)
- `article.updated_at`: Article modification date

### 5. Error Handling
- Format-specific error messages with suggestions
- Detailed network error handling
- Timeout and authentication error detection

## Technical Architecture

### Services
- `FeedFormatDetector`: Detects feed format from content
- `JsonFeedParser`: Parses JSON Feed format with security validation
- `FeedParserService`: Enhanced with format-aware parsing
- `FeedParsingException`: Custom exceptions with format-specific guidance

### Entities
- Enhanced `Feed` entity with format and language fields
- Enhanced `Article` entity with metadata support
- New entities: `ArticleAuthor`, `ArticleCategory`, `ArticleEnclosure`

### Database Migration
- Migration `Version20250721090000.php` creates new schema
- Includes proper constraints and foreign key relationships

## UI Enhancements

### Feed List Display
- New "Format" column showing detected feed type
- Color-coded badges for different formats:
  - RSS 2.0/1.0: Green badge
  - Atom 1.0: Blue badge
  - JSON Feed: Yellow badge
  - Unknown: Gray badge

### Feed Preview
- Format information displayed in preview
- Support for JSON Feed preview data

## Security Considerations

### Content Sanitization
- HTML content sanitization for XSS prevention
- JSON Feed security validation
- Input validation for all metadata fields

### JSON Feed Security
- JSON schema validation
- Protection against JSON injection
- Prototype pollution prevention
- Content type validation

## Performance Features

### Memory Management
- Lazy loading for metadata relationships
- Streaming approach for large feeds
- Configurable limits for metadata extraction

### Database Optimization
- Proper indexes on new tables
- Cascade delete for cleanup
- Database constraints for data integrity

## Testing Strategy

The implementation includes comprehensive error handling and validation:
- Format detection validation
- Security validation for all inputs
- Network error handling with retry logic
- Malformed feed graceful handling

## Usage Examples

### Adding JSON Feed
Users can now add JSON Feed URLs directly in the feed subscription form.

### Viewing Feed Metadata
- Authors, categories, and enclosures are automatically extracted
- Format information is displayed in the feed list
- Enhanced error messages guide users for problematic feeds

## Future Considerations

- Performance monitoring for large feeds
- Additional syndication format support
- Advanced metadata search capabilities
- Feed analytics and statistics

## Dependencies

- **Laminas Feed 2.24**: For RSS/Atom parsing
- **Symfony 7.1**: Framework components
- **PHP 8.2+**: Modern PHP features used throughout

## File Changes

### New Files
- `src/Service/FeedFormatDetector.php`
- `src/Service/JsonFeedParser.php`
- `src/Exception/FeedParsingException.php`
- `src/Entity/ArticleAuthor.php`
- `src/Entity/ArticleCategory.php`
- `src/Entity/ArticleEnclosure.php`
- `migrations/Version20250721090000.php`

### Enhanced Files
- `src/Service/FeedParserService.php`: Enhanced with format detection and metadata extraction
- `src/Entity/Feed.php`: Added format and language fields
- `src/Entity/Article.php`: Added metadata relationships
- `src/Controller/FeedController.php`: Updated to handle new format data
- `templates/feed/index.html.twig`: Added format display
- `templates/feed/preview.html.twig`: Enhanced preview with format info

This implementation provides a robust, secure, and user-friendly enhanced feed format support system that meets all the requirements specified in issue #141.