# RSS Reader

A comprehensive RSS reader application built with Node.js and Express, designed to replace Google Reader with modern web technologies. This application provides users with a centralized platform to subscribe to, organize, and read RSS feeds.

## Features

### Core Functionality
- **User Authentication**: Secure registration and login system with JWT tokens
- **Feed Management**: Subscribe to RSS/Atom feeds by URL
- **Article Reading**: View articles in a clean, organized interface
- **Read/Unread Tracking**: Mark articles as read/unread individually or in bulk
- **Star/Favorite System**: Save important articles for later reference
- **Search**: Search through articles by title, content, or author
- **Categories**: Organize feeds into folders/categories
- **Responsive Design**: Mobile-friendly interface

### Technical Features
- **RESTful API**: Complete API for all operations
- **MongoDB Database**: Scalable document-based storage
- **Feed Parsing**: Robust RSS/Atom feed parsing with error handling
- **Real-time Updates**: Background feed processing
- **Security**: Input validation, XSS protection, rate limiting
- **Docker Support**: Containerized deployment

## Quick Start

### Using Docker (Recommended)

1. Clone the repository:
```bash
git clone <repository-url>
cd rss-reader
```

2. Start the application:
```bash
docker-compose up -d
```

3. Visit http://localhost:3000

### Manual Installation

1. Prerequisites:
   - Node.js 18+ 
   - MongoDB 4.4+

2. Install dependencies:
```bash
npm install
```

3. Set up environment variables:
```bash
cp .env.example .env
# Edit .env with your configuration
```

4. Start MongoDB (if not using Docker)

5. Start the application:
```bash
# Development mode
npm run dev

# Production mode
npm start
```

## API Documentation

### Authentication Endpoints

#### Register User
```http
POST /api/auth/register
Content-Type: application/json

{
  "username": "johndoe",
  "email": "john@example.com", 
  "password": "password123"
}
```

#### Login
```http
POST /api/auth/login
Content-Type: application/json

{
  "email": "john@example.com",
  "password": "password123"
}
```

#### Get Profile
```http
GET /api/auth/profile
Authorization: Bearer <token>
```

### Feed Management Endpoints

#### Subscribe to Feed
```http
POST /api/feeds/subscribe
Authorization: Bearer <token>
Content-Type: application/json

{
  "url": "https://example.com/feed.xml",
  "customTitle": "My Custom Feed Name",
  "categoryId": "category_id_here"
}
```

#### Get User Feeds
```http
GET /api/feeds
Authorization: Bearer <token>
```

#### Get Feed Articles
```http
GET /api/feeds/:feedId/articles?page=1&limit=50&unreadOnly=true
Authorization: Bearer <token>
```

#### Unsubscribe from Feed
```http
DELETE /api/feeds/:feedId/unsubscribe
Authorization: Bearer <token>
```

### Article Management Endpoints

#### Get All Articles
```http
GET /api/articles?page=1&limit=50&unreadOnly=false&starredOnly=false
Authorization: Bearer <token>
```

#### Get Article by ID
```http
GET /api/articles/:articleId
Authorization: Bearer <token>
```

#### Mark Article as Read/Unread
```http
POST /api/articles/:articleId/read
Authorization: Bearer <token>
Content-Type: application/json

{
  "isRead": true
}
```

#### Star/Unstar Article
```http
POST /api/articles/:articleId/star
Authorization: Bearer <token>
Content-Type: application/json

{
  "isStarred": true
}
```

#### Mark All Articles as Read
```http
POST /api/articles/mark-all-read
Authorization: Bearer <token>
Content-Type: application/json

{
  "feedId": "optional_feed_id",
  "categoryId": "optional_category_id"
}
```

#### Search Articles
```http
GET /api/articles/search?q=search_term&page=1&limit=50
Authorization: Bearer <token>
```

#### Get Article Statistics
```http
GET /api/articles/stats
Authorization: Bearer <token>
```

### Category Management Endpoints

#### Create Category
```http
POST /api/feeds/categories
Authorization: Bearer <token>
Content-Type: application/json

{
  "name": "Technology",
  "color": "#007bff",
  "parentId": "optional_parent_id"
}
```

#### Get User Categories
```http
GET /api/feeds/categories/list
Authorization: Bearer <token>
```

#### Update Category
```http
PUT /api/feeds/categories/:categoryId
Authorization: Bearer <token>
Content-Type: application/json

{
  "name": "Updated Name",
  "color": "#28a745"
}
```

#### Delete Category
```http
DELETE /api/feeds/categories/:categoryId
Authorization: Bearer <token>
```

## Database Schema

### User Model
- `username`: Unique username
- `email`: User email address  
- `password`: Hashed password
- `preferences`: User preferences (articles per page, default view, etc.)
- `createdAt`: Account creation timestamp
- `lastLoginAt`: Last login timestamp

### Feed Model
- `url`: RSS/Atom feed URL
- `title`: Feed title
- `description`: Feed description
- `link`: Feed website URL
- `status`: Feed status (active, inactive, error)
- `lastFetchedAt`: Last fetch attempt
- `lastSuccessfulFetchAt`: Last successful fetch
- `errorCount`: Number of consecutive errors
- `errorMessage`: Latest error message

### Article Model
- `title`: Article title
- `description`: Article summary
- `content`: Full article content
- `link`: Article URL
- `guid`: Unique article identifier
- `pubDate`: Publication date
- `author`: Article author
- `categories`: Article categories/tags
- `feed`: Reference to Feed

### UserFeed Model (Subscription)
- `user`: Reference to User
- `feed`: Reference to Feed
- `category`: Reference to Category (optional)
- `customTitle`: Custom feed name
- `refreshFrequency`: Refresh interval in minutes
- `isActive`: Subscription status
- `subscribedAt`: Subscription date

### UserArticle Model (Read Status)
- `user`: Reference to User
- `article`: Reference to Article
- `isRead`: Read status
- `isStarred`: Star/favorite status
- `readAt`: When marked as read
- `starredAt`: When starred

### Category Model
- `name`: Category name
- `user`: Reference to User
- `parent`: Reference to parent Category (optional)
- `color`: Category color
- `order`: Display order

## Architecture

The application follows a layered architecture:

1. **Routes Layer** (`src/routes/`): Express route handlers
2. **Controllers Layer** (`src/controllers/`): Request/response logic
3. **Services Layer** (`src/services/`): Business logic
4. **Models Layer** (`src/models/`): Database models
5. **Middleware Layer** (`src/middleware/`): Authentication, validation

### Key Services

- **Feed Parser Service**: Handles RSS/Atom feed parsing and normalization
- **Feed Manager Service**: Manages feed subscriptions and categories
- **Article Processor Service**: Handles article operations and user interactions

## Security Features

- **Input Validation**: All inputs validated using express-validator
- **Authentication**: JWT-based authentication
- **Password Hashing**: Bcrypt for secure password storage
- **Rate Limiting**: Protection against abuse
- **CORS**: Cross-origin request protection
- **Helmet**: Security headers
- **XSS Protection**: Content sanitization

## Performance Considerations

- **Database Indexing**: Optimized queries with proper indexes
- **Pagination**: All list endpoints support pagination
- **Caching**: Ready for Redis integration
- **Background Processing**: Asynchronous feed updates
- **Error Handling**: Graceful error handling with retries

## Development

### Project Structure
```
├── src/
│   ├── controllers/     # Request handlers
│   ├── middleware/      # Express middleware
│   ├── models/          # Database models
│   ├── routes/          # API routes
│   ├── services/        # Business logic
│   ├── utils/           # Utility functions
│   └── index.js         # Main application file
├── public/              # Static frontend files
│   ├── css/
│   ├── js/
│   └── index.html
├── tests/               # Test files
├── Dockerfile
├── docker-compose.yml
└── package.json
```

### Available Scripts

```bash
npm start          # Start production server
npm run dev        # Start development server with nodemon
npm test           # Run tests
npm run test:watch # Run tests in watch mode
```

### Environment Variables

Create a `.env` file with:

```env
NODE_ENV=development
PORT=3000
MONGODB_URI=mongodb://localhost:27017/rss-reader
JWT_SECRET=your-super-secure-jwt-secret-key
```

## Deployment

### Docker Deployment

1. Build and run with Docker Compose:
```bash
docker-compose up -d
```

2. The application will be available at http://localhost:3000

### Manual Deployment

1. Set NODE_ENV=production
2. Use a process manager like PM2
3. Set up MongoDB with proper security
4. Configure reverse proxy (nginx/Apache)
5. Set up SSL certificates

## Testing

The application includes comprehensive tests:

```bash
# Run all tests
npm test

# Run tests in watch mode
npm run test:watch

# Run specific test file
npm test -- tests/auth.test.js
```

## Contributing

1. Fork the repository
2. Create a feature branch
3. Make your changes
4. Add tests for new functionality
5. Ensure all tests pass
6. Submit a pull request

## License

This project is licensed under the MIT License - see the LICENSE file for details.

## Support

For support, please open an issue on GitHub or contact the maintainers.

## Roadmap

### Phase 1 (Current)
- [x] Core feed management
- [x] User authentication
- [x] Article reading interface
- [x] Basic search functionality

### Phase 2 (Planned)
- [ ] OPML import/export
- [ ] Keyboard shortcuts
- [ ] Advanced search filters
- [ ] Feed discovery
- [ ] Mobile app (React Native)

### Phase 3 (Future)
- [ ] Real-time notifications
- [ ] Social features (sharing, comments)
- [ ] Machine learning recommendations
- [ ] Browser extension
- [ ] API rate limiting and quotas

## Acknowledgments

- Inspired by Google Reader
- Built with modern web technologies
- Thanks to the open source community