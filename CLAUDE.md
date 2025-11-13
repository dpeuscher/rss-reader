# RSS Reader with AI-Powered Content Intelligence

## Project Overview

This is a Symfony 7.1 RSS reader application with advanced AI-powered content intelligence features. The system provides personalized content curation, automatic article summarization, and intelligent recommendations to help users efficiently consume large amounts of RSS content.

## Architecture

### Technology Stack
- **Backend**: Symfony 7.1 with PHP 8.2+
- **Database**: PostgreSQL 16 with Doctrine ORM 3.5
- **AI Integration**: OpenAI GPT-4 Turbo, Anthropic Claude (with fallbacks)
- **Frontend**: Twig templates with Bootstrap 5.3
- **Infrastructure**: Docker containerization

### Core Entities
- `Article`: RSS articles with AI enhancement fields
- `UserArticle`: User interactions and personalization data
- `Feed`: RSS feed sources
- `User`: Application users
- `Category`: Article categorization
- `Subscription`: User feed subscriptions

## AI Features Implementation

### 1. Database Schema Extensions

AI-specific fields added to existing entities:

**Article Entity:**
- `aiSummary` (TEXT): AI-generated article summary
- `aiCategories` (JSON): Categorization with confidence scores
- `aiScore` (FLOAT): Base relevance score (0-1)
- `aiReadingTime` (INT): Estimated reading time in minutes
- `aiProcessedAt` (DATETIME): Last AI processing timestamp

**UserArticle Entity:**
- `personalizationScore` (FLOAT): User-specific relevance score
- `interactionData` (JSON): Detailed user interaction tracking

### 2. AI Services Architecture

Located in `src/Service/AI/`:

#### AIArticleProcessor
Main orchestrator service that coordinates AI processing:
- Article summarization
- Content categorization  
- Reading time estimation
- Base scoring
- Processing status tracking

#### SummarizationService
LLM integration for article summarization:
- **Primary**: OpenAI GPT-4 Turbo API
- **Fallback**: Anthropic Claude API
- **Emergency fallback**: Extractive summarization
- Configurable content length limits
- Error handling and caching

#### CategoryService
Content categorization and sentiment analysis:
- Keyword-based category detection
- 10 predefined categories (Technology, Business, Science, etc.)
- Confidence scoring
- Basic sentiment analysis
- Extensible keyword dictionaries

#### ScoringService
Article relevance scoring system:
- **Base Score** (60%): Content quality, freshness, source credibility
- **Category Preference** (25%): User reading patterns
- **Behavior Boost** (10%): Feed engagement history
- **Time Preference** (5%): Reading time patterns

#### PersonalizationService
User preference learning and recommendations:
- Reading behavior tracking
- Personalized feed generation
- User insights analytics
- Similar article recommendations
- Interaction data management

### 3. API Endpoints

All AI endpoints under `/ai/` prefix:

- `GET /ai/smart-inbox` - Smart Inbox UI
- `GET /ai/smart-inbox/api` - Personalized feed API
- `POST /ai/article/{id}/summarize` - Generate article summary
- `POST /ai/article/{id}/track-interaction` - Track user interactions
- `GET /ai/article/{id}/similar` - Get similar articles
- `GET /ai/insights` - User reading insights
- `POST /ai/process-article/{id}` - Process article with AI

### 4. Smart Inbox Features

**Frontend Components:**
- Personalized article ranking
- AI-generated summaries with caching
- Category badges and reading time estimates
- Similar article recommendations
- User interaction tracking
- Reading insights sidebar

**User Interaction Tracking:**
- Article reads, stars, and views
- Reading time measurement
- Category preference learning
- Time-based reading patterns

## Configuration

### Environment Variables
```bash
# AI Services
OPENAI_API_KEY=your_openai_api_key_here
ANTHROPIC_API_KEY=your_anthropic_api_key_here

# Database
DATABASE_URL=postgresql://user:pass@host:5432/db
```

### Service Configuration
AI services are auto-configured in `config/services.yaml` with API key injection.

## Common Commands

### Development
```bash
# Install dependencies
composer install

# Run database migrations
php bin/console doctrine:migrations:migrate

# Clear cache
php bin/console cache:clear

# Start development server
symfony server:start
```

### Docker Development
```bash
# Start containers
docker-compose up -d

# Run migrations in container
docker-compose exec app php bin/console doctrine:migrations:migrate
```

## AI Processing Workflow

1. **Article Ingestion**: Articles parsed from RSS feeds
2. **AI Processing**: 
   - Content analysis and categorization
   - Summary generation (if API keys configured)
   - Base scoring calculation
   - Reading time estimation
3. **User Interaction**: Track reading behavior and preferences
4. **Personalization**: Generate personalized scores and recommendations
5. **Smart Inbox**: Display ranked content based on user preferences

## Performance Considerations

### Caching Strategy
- AI summaries cached for 24 hours
- Category detection results cached
- User preference data cached during session

### Rate Limiting
- LLM API calls are throttled and monitored
- Fallback mechanisms for API failures
- Cost monitoring for usage tracking

### Background Processing
- Symfony Messenger ready for async processing
- Queue-based article processing (not yet implemented)
- Batch processing for large content volumes

## Error Handling

### Graceful Degradation
- Core RSS functionality works without AI features
- Fallback summarization when LLM APIs fail
- Default scoring when personalization unavailable
- Clear error messages and logging

### Monitoring
- Comprehensive logging for AI operations
- Error tracking for failed API calls
- Performance metrics for processing times

## Security Considerations

### API Key Management
- Secure environment variable storage
- No API keys in code or version control
- Service-level key rotation support

### User Data Protection
- Minimal data collection (reading patterns only)
- No content storage for external APIs
- Privacy-by-design implementation
- GDPR-compliant data handling

## Future Enhancements

### Phase 1 Completed ✅
- Database schema extensions
- Core AI service infrastructure
- Smart Inbox UI
- Basic personalization

### Phase 2 (Planned)
- Background job processing
- Advanced ML model training
- Multi-language support
- Enhanced caching layer

### Phase 3 (Planned)
- Real-time notifications
- Advanced analytics dashboard
- Social features integration
- Mobile app support

## Testing

### AI Service Testing
- Unit tests for scoring algorithms
- Integration tests for LLM APIs
- Mock services for development
- Performance benchmarking

### User Interface Testing
- JavaScript functionality tests
- Responsive design validation
- Accessibility compliance
- Cross-browser compatibility

## Troubleshooting

### Common Issues

**AI Features Not Working:**
- Verify API keys are set in environment
- Check service configuration in services.yaml
- Review logs for API rate limiting

**Database Issues:**
- Run migrations: `php bin/console doctrine:migrations:migrate`
- Check PostgreSQL connection and permissions
- Verify database schema matches entities

**Performance Issues:**
- Enable caching layers
- Optimize database queries
- Monitor LLM API response times
- Consider async processing for large datasets

### Debugging

Enable debug mode and check logs in `var/log/` for detailed error information.

## Contributing

1. Follow PSR-12 coding standards
2. Add tests for new AI features
3. Update this documentation for changes
4. Consider privacy implications for user data
5. Test fallback mechanisms for external APIs

## Dependencies

### Core Dependencies
- symfony/framework-bundle: 7.1.*
- doctrine/orm: ^3.5
- symfony/http-client: 7.1.*
- symfony/messenger: 7.1.*

### AI-Specific Dependencies
- No additional packages required (uses HTTP client for LLM APIs)
- JSON handling for category and interaction data
- DateTime utilities for temporal features

---

*This documentation reflects the current implementation of AI features in the RSS reader application. Update as features evolve.*