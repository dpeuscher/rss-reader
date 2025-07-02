const bcrypt = require('bcryptjs');

const testUsers = {
  validUser: {
    username: 'testuser',
    email: 'test@example.com',
    password: 'password123'
  },
  adminUser: {
    username: 'admin',
    email: 'admin@example.com',
    password: 'adminpass123'
  }
};

const testFeeds = {
  validFeed: {
    url: 'https://example.com/feed.xml',
    title: 'Test Feed',
    description: 'A test RSS feed',
    link: 'https://example.com',
    language: 'en',
    status: 'active'
  },
  secondFeed: {
    url: 'https://another.com/rss.xml',
    title: 'Another Feed',
    description: 'Another test feed',
    link: 'https://another.com',
    status: 'active'
  }
};

const testArticles = {
  validArticle: {
    title: 'Test Article',
    description: 'A test article description',
    content: 'This is the full content of the test article.',
    link: 'https://example.com/article/1',
    guid: 'article-1-guid',
    pubDate: new Date('2024-01-01T10:00:00Z'),
    author: 'Test Author',
    categories: ['Technology', 'Testing']
  },
  secondArticle: {
    title: 'Second Test Article',
    description: 'Another test article',
    content: 'Content of the second article.',
    link: 'https://example.com/article/2',
    guid: 'article-2-guid',
    pubDate: new Date('2024-01-02T10:00:00Z'),
    author: 'Another Author'
  }
};

const testCategories = {
  validCategory: {
    name: 'Technology',
    description: 'Technology related feeds',
    color: '#007bff'
  },
  secondCategory: {
    name: 'News',
    description: 'News feeds',
    color: '#28a745'
  }
};

const sampleRSSFeed = `<?xml version="1.0" encoding="UTF-8" ?>
<rss version="2.0">
<channel>
  <title>Test RSS Feed</title>
  <description>A sample RSS feed for testing</description>
  <link>https://example.com</link>
  <lastBuildDate>Mon, 01 Jan 2024 10:00:00 GMT</lastBuildDate>
  <pubDate>Mon, 01 Jan 2024 10:00:00 GMT</pubDate>
  <ttl>60</ttl>
  
  <item>
    <title>Test Article 1</title>
    <description>Description of test article 1</description>
    <link>https://example.com/article/1</link>
    <guid>article-1-guid</guid>
    <pubDate>Mon, 01 Jan 2024 10:00:00 GMT</pubDate>
    <author>test@example.com (Test Author)</author>
    <category>Technology</category>
  </item>
  
  <item>
    <title>Test Article 2</title>
    <description>Description of test article 2</description>
    <link>https://example.com/article/2</link>
    <guid>article-2-guid</guid>
    <pubDate>Mon, 01 Jan 2024 11:00:00 GMT</pubDate>
    <author>test2@example.com (Another Author)</author>
    <category>Testing</category>
  </item>
</channel>
</rss>`;

const sampleAtomFeed = `<?xml version="1.0" encoding="utf-8"?>
<feed xmlns="http://www.w3.org/2005/Atom">
  <title>Test Atom Feed</title>
  <subtitle>A sample Atom feed for testing</subtitle>
  <link href="https://example.com/atom.xml" rel="self" />
  <link href="https://example.com/" />
  <id>https://example.com/</id>
  <updated>2024-01-01T10:00:00Z</updated>
  
  <entry>
    <title>Atom Test Article</title>
    <link href="https://example.com/atom/1" />
    <id>atom-article-1</id>
    <updated>2024-01-01T10:00:00Z</updated>
    <summary>Summary of atom test article</summary>
    <content type="html">Full content of atom test article</content>
    <author>
      <name>Atom Author</name>
      <email>atom@example.com</email>
    </author>
  </entry>
</feed>`;

module.exports = {
  testUsers,
  testFeeds,
  testArticles,
  testCategories,
  sampleRSSFeed,
  sampleAtomFeed
};