const feedParserService = require('../../../src/services/feedParser');
const { Feed, Article } = require('../../../src/models');
const { sampleRSSFeed, sampleAtomFeed, testFeeds } = require('../../fixtures/testData');

// Mock the Parser to avoid actual HTTP requests
jest.mock('rss-parser');
const Parser = require('rss-parser');

describe('FeedParserService', () => {
  let mockParser;

  beforeEach(() => {
    mockParser = {
      parseURL: jest.fn(),
      parseString: jest.fn()
    };
    Parser.mockImplementation(() => mockParser);
  });

  describe('parseFeedFromString', () => {
    it('should parse RSS feed string correctly', async () => {
      const mockParsedFeed = {
        title: 'Test RSS Feed',
        description: 'A sample RSS feed for testing',
        link: 'https://example.com',
        language: 'en',
        lastBuildDate: 'Mon, 01 Jan 2024 10:00:00 GMT',
        items: [
          {
            title: 'Test Article 1',
            description: 'Description of test article 1',
            link: 'https://example.com/article/1',
            guid: 'article-1-guid',
            pubDate: 'Mon, 01 Jan 2024 10:00:00 GMT',
            author: 'Test Author',
            categories: ['Technology']
          }
        ]
      };

      mockParser.parseString.mockResolvedValue(mockParsedFeed);

      const result = await feedParserService.parseFeedFromString(sampleRSSFeed, 'https://example.com/feed.xml');

      expect(result.feed.title).toBe('Test RSS Feed');
      expect(result.feed.description).toBe('A sample RSS feed for testing');
      expect(result.feed.url).toBe('https://example.com/feed.xml');
      expect(result.articles).toHaveLength(1);
      expect(result.articles[0].title).toBe('Test Article 1');
      expect(result.articles[0].categories).toEqual(['Technology']);
    });

    it('should handle parse errors', async () => {
      mockParser.parseString.mockRejectedValue(new Error('Invalid XML'));

      await expect(
        feedParserService.parseFeedFromString('invalid xml', 'https://example.com/feed.xml')
      ).rejects.toThrow('Failed to parse feed content: Invalid XML');
    });
  });

  describe('normalizeFeedData', () => {
    it('should normalize feed data with all fields', () => {
      const parsedFeed = {
        title: 'Test Feed',
        description: 'Test Description',
        link: 'https://example.com',
        language: 'en',
        copyright: '© 2024 Test',
        generator: 'Test Generator',
        lastBuildDate: '2024-01-01T10:00:00Z',
        pubDate: '2024-01-01T09:00:00Z',
        ttl: '120',
        image: { url: 'https://example.com/image.png' },
        items: []
      };

      const result = feedParserService.normalizeFeedData(parsedFeed, 'https://example.com/feed.xml');

      expect(result.feed.title).toBe('Test Feed');
      expect(result.feed.description).toBe('Test Description');
      expect(result.feed.url).toBe('https://example.com/feed.xml');
      expect(result.feed.language).toBe('en');
      expect(result.feed.copyright).toBe('© 2024 Test');
      expect(result.feed.generator).toBe('Test Generator');
      expect(result.feed.ttl).toBe(120);
      expect(result.feed.image.url).toBe('https://example.com/image.png');
    });

    it('should handle missing fields with defaults', () => {
      const parsedFeed = {
        items: []
      };

      const result = feedParserService.normalizeFeedData(parsedFeed, 'https://example.com/feed.xml');

      expect(result.feed.title).toBe('Untitled Feed');
      expect(result.feed.description).toBe('');
      expect(result.feed.ttl).toBe(60);
    });

    it('should use subtitle as fallback for description', () => {
      const parsedFeed = {
        subtitle: 'Feed Subtitle',
        items: []
      };

      const result = feedParserService.normalizeFeedData(parsedFeed, 'https://example.com/feed.xml');

      expect(result.feed.description).toBe('Feed Subtitle');
    });
  });

  describe('normalizeArticleData', () => {
    it('should normalize article data with all fields', () => {
      const item = {
        title: 'Test Article',
        description: 'Article description',
        content: 'Full article content',
        link: 'https://example.com/article/1',
        guid: 'article-1',
        pubDate: '2024-01-01T10:00:00Z',
        author: 'Test Author',
        categories: ['Tech', 'News'],
        enclosure: { url: 'https://example.com/audio.mp3', type: 'audio/mpeg' }
      };

      const result = feedParserService.normalizeArticleData(item);

      expect(result.title).toBe('Test Article');
      expect(result.description).toBe('Article description');
      expect(result.content).toBe('Full article content');
      expect(result.link).toBe('https://example.com/article/1');
      expect(result.guid).toBe('article-1');
      expect(result.author).toBe('Test Author');
      expect(result.categories).toEqual(['Tech', 'News']);
      expect(result.enclosures).toHaveLength(1);
      expect(result.enclosures[0].url).toBe('https://example.com/audio.mp3');
    });

    it('should handle missing fields with defaults', () => {
      const item = {};

      const result = feedParserService.normalizeArticleData(item);

      expect(result.title).toBe('Untitled Article');
      expect(result.description).toBe('');
      expect(result.content).toBe('');
      expect(result.link).toBe('');
      expect(result.guid).toBe('');
      expect(result.author).toBe('');
      expect(result.categories).toEqual([]);
      expect(result.enclosures).toEqual([]);
    });

    it('should use summary as fallback for description', () => {
      const item = {
        summary: 'Article summary'
      };

      const result = feedParserService.normalizeArticleData(item);

      expect(result.description).toBe('Article summary');
    });
  });

  describe('parseDate', () => {
    it('should parse valid date strings', () => {
      const date1 = feedParserService.parseDate('2024-01-01T10:00:00Z');
      const date2 = feedParserService.parseDate('Mon, 01 Jan 2024 10:00:00 GMT');

      expect(date1).toBeInstanceOf(Date);
      expect(date2).toBeInstanceOf(Date);
      expect(date1.getFullYear()).toBe(2024);
      expect(date2.getFullYear()).toBe(2024);
    });

    it('should return null for invalid dates', () => {
      const result1 = feedParserService.parseDate('invalid date');
      const result2 = feedParserService.parseDate(null);
      const result3 = feedParserService.parseDate(undefined);

      expect(result1).toBeNull();
      expect(result2).toBeNull();
      expect(result3).toBeNull();
    });
  });

  describe('parseImage', () => {
    it('should parse image object', () => {
      const imageData = {
        url: 'https://example.com/image.png',
        title: 'Image Title',
        link: 'https://example.com'
      };

      const result = feedParserService.parseImage(imageData);

      expect(result.url).toBe('https://example.com/image.png');
      expect(result.title).toBe('Image Title');
      expect(result.link).toBe('https://example.com');
    });

    it('should parse image string', () => {
      const result = feedParserService.parseImage('https://example.com/image.png');

      expect(result.url).toBe('https://example.com/image.png');
    });

    it('should return null for empty image data', () => {
      const result = feedParserService.parseImage(null);
      expect(result).toBeNull();
    });
  });

  describe('parseCategories', () => {
    it('should parse array of category strings', () => {
      const categories = ['Tech', 'News', 'Science'];
      const result = feedParserService.parseCategories(categories);

      expect(result).toEqual(['Tech', 'News', 'Science']);
    });

    it('should parse array of category objects', () => {
      const categories = [
        { name: 'Tech' },
        { name: 'News' },
        'Science'
      ];
      const result = feedParserService.parseCategories(categories);

      expect(result).toEqual(['Tech', 'News', 'Science']);
    });

    it('should parse single category string', () => {
      const result = feedParserService.parseCategories('Technology');
      expect(result).toEqual(['Technology']);
    });

    it('should return empty array for null/undefined', () => {
      expect(feedParserService.parseCategories(null)).toEqual([]);
      expect(feedParserService.parseCategories(undefined)).toEqual([]);
    });
  });

  describe('parseEnclosures', () => {
    it('should parse single enclosure', () => {
      const enclosure = {
        url: 'https://example.com/audio.mp3',
        type: 'audio/mpeg',
        length: '1024'
      };

      const result = feedParserService.parseEnclosures(enclosure);

      expect(result).toHaveLength(1);
      expect(result[0].url).toBe('https://example.com/audio.mp3');
      expect(result[0].type).toBe('audio/mpeg');
      expect(result[0].length).toBe(1024);
    });

    it('should parse array of enclosures', () => {
      const enclosures = [
        { url: 'https://example.com/audio.mp3', type: 'audio/mpeg' },
        { url: 'https://example.com/video.mp4', type: 'video/mp4' }
      ];

      const result = feedParserService.parseEnclosures(enclosures);

      expect(result).toHaveLength(2);
      expect(result[0].url).toBe('https://example.com/audio.mp3');
      expect(result[1].url).toBe('https://example.com/video.mp4');
    });

    it('should filter out enclosures without URL', () => {
      const enclosures = [
        { url: 'https://example.com/audio.mp3', type: 'audio/mpeg' },
        { type: 'audio/mpeg' }
      ];

      const result = feedParserService.parseEnclosures(enclosures);

      expect(result).toHaveLength(1);
    });
  });

  describe('saveFeedAndArticles', () => {
    beforeEach(async () => {
      await Feed.deleteMany({});
      await Article.deleteMany({});
    });

    it('should save new feed and articles', async () => {
      const feedData = { ...testFeeds.validFeed };
      const articlesData = [
        {
          title: 'Test Article',
          description: 'Test description',
          content: 'Test content',
          link: 'https://example.com/article/1',
          guid: 'article-1',
          pubDate: new Date()
        }
      ];

      const result = await feedParserService.saveFeedAndArticles(feedData, articlesData);

      expect(result.feed).toBeDefined();
      expect(result.feed.url).toBe(feedData.url);
      expect(result.articles).toHaveLength(1);
      expect(result.newArticles).toBe(1);
      expect(result.totalArticles).toBe(1);

      // Verify in database
      const savedFeed = await Feed.findOne({ url: feedData.url });
      expect(savedFeed).toBeDefined();
      expect(savedFeed.status).toBe('active');
      expect(savedFeed.errorCount).toBe(0);

      const savedArticles = await Article.find({ feed: savedFeed._id });
      expect(savedArticles).toHaveLength(1);
    });

    it('should update existing feed', async () => {
      // Create existing feed
      const existingFeed = new Feed(testFeeds.validFeed);
      await existingFeed.save();

      const updatedFeedData = {
        ...testFeeds.validFeed,
        title: 'Updated Feed Title'
      };
      const articlesData = [];

      const result = await feedParserService.saveFeedAndArticles(updatedFeedData, articlesData);

      expect(result.feed.title).toBe('Updated Feed Title');
      expect(result.feed.status).toBe('active');
    });

    it('should not duplicate articles with same GUID', async () => {
      const feed = new Feed(testFeeds.validFeed);
      await feed.save();

      const articleData = {
        title: 'Test Article',
        guid: 'unique-guid',
        link: 'https://example.com/article/1'
      };

      // First save
      await feedParserService.saveFeedAndArticles(testFeeds.validFeed, [articleData]);
      
      // Second save with same GUID
      const result = await feedParserService.saveFeedAndArticles(testFeeds.validFeed, [articleData]);

      expect(result.newArticles).toBe(0);
      
      const articlesCount = await Article.countDocuments({ feed: feed._id });
      expect(articlesCount).toBe(1);
    });
  });

  describe('updateFeedError', () => {
    it('should increment error count and set message', async () => {
      const feed = new Feed(testFeeds.validFeed);
      await feed.save();

      await feedParserService.updateFeedError(feed._id, 'Connection timeout');

      const updatedFeed = await Feed.findById(feed._id);
      expect(updatedFeed.errorCount).toBe(1);
      expect(updatedFeed.errorMessage).toBe('Connection timeout');
      expect(updatedFeed.status).toBe('active');
    });

    it('should set status to error after 5 failures', async () => {
      const feed = new Feed({ ...testFeeds.validFeed, errorCount: 4 });
      await feed.save();

      await feedParserService.updateFeedError(feed._id, 'Fifth error');

      const updatedFeed = await Feed.findById(feed._id);
      expect(updatedFeed.errorCount).toBe(5);
      expect(updatedFeed.status).toBe('error');
    });
  });
});