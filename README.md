# RSS Reader

A Google Reader-inspired RSS reader application built with Symfony PHP.

## Features

- **User Management**: Registration, authentication, and user profiles
- **Feed Management**: Subscribe to RSS/Atom feeds with automatic parsing
- **Article Reading**: Clean, responsive interface for reading articles
- **Read/Unread Tracking**: Mark articles as read with persistent state
- **Search**: Full-text search across all subscribed content
- **Background Updates**: Automated feed refreshing via console commands
- **RESTful API**: Complete API for mobile apps and external integrations
- **Modern UI**: Clean, Google Reader-inspired interface

## Installation

1. **Install dependencies:**
```bash
composer install
```

2. **Set up the database:**
```bash
php bin/console doctrine:database:create
php bin/console doctrine:migrations:migrate
```

3. **Start the development server:**
```bash
symfony serve
```

Or use PHP's built-in server:
```bash
php -S localhost:8000 -t public/
```

## Usage

1. **Register** a new account at `/register`
2. **Login** at `/login`
3. **Add RSS feeds** using the sidebar form on the main page
4. **Browse articles** from all your subscribed feeds
5. **Search content** using the search functionality
6. **Read articles** by clicking on titles
7. **Filter by feed** or view only unread articles

## Background Tasks

**Update all feeds:**
```bash
php bin/console app:update-feeds
```

Set up a cron job to run this command regularly:
```bash
# Update feeds every 30 minutes
*/30 * * * * /path/to/php /path/to/project/bin/console app:update-feeds
```

## API Documentation

The application provides a complete RESTful API:

### Authentication
All API endpoints require user authentication via session cookies.

### Endpoints

**Get user's feeds:**
```
GET /api/feeds
```

**Get articles:**
```
GET /api/articles?feed_id=1&unread_only=true&limit=20
```

**Get specific article:**
```
GET /api/articles/{id}
```

**Mark article as read:**
```
POST /api/articles/{id}/mark-read
```

**Add new feed:**
```
POST /api/feeds/add
Content-Type: application/json
{
  "feed_url": "https://example.com/feed.xml",
  "custom_title": "Optional Custom Title"
}
```

**Refresh feed:**
```
POST /api/feeds/{id}/refresh
```

**Search articles:**
```
GET /api/search?q=search+term&limit=20
```

## Configuration

The application uses environment variables for configuration:

- `APP_ENV`: Application environment (dev/prod)
- `APP_SECRET`: Application secret key
- `DATABASE_URL`: Database connection string

## System Requirements

- **PHP**: 8.1 or higher
- **Extensions**: ctype, iconv, xml, simplexml
- **Database**: SQLite (default), MySQL, or PostgreSQL
- **Memory**: 256MB minimum recommended

## Production Deployment

1. **Install dependencies:**
```bash
composer install --no-dev --optimize-autoloader
```

2. **Set environment:**
```bash
export APP_ENV=prod
export APP_SECRET=your-secret-key
```

3. **Clear cache:**
```bash
php bin/console cache:clear --env=prod
```

4. **Set up web server** (Apache/Nginx) to serve from `public/` directory

## License

This project is open source and available under the MIT License.