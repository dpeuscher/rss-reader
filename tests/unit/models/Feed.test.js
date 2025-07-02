const Feed = require('../../../src/models/Feed');
const { testFeeds } = require('../../fixtures/testData');

describe('Feed Model', () => {
  describe('Feed Creation', () => {
    it('should create a valid feed', async () => {
      const feedData = { ...testFeeds.validFeed };
      const feed = new Feed(feedData);
      
      expect(feed.url).toBe(feedData.url);
      expect(feed.title).toBe(feedData.title);
      expect(feed.description).toBe(feedData.description);
      expect(feed.status).toBe('active');
      expect(feed.ttl).toBe(60);
      expect(feed.errorCount).toBe(0);
    });

    it('should require url', async () => {
      const feedData = { ...testFeeds.validFeed };
      delete feedData.url;
      const feed = new Feed(feedData);
      
      await expect(feed.save()).rejects.toThrow();
    });

    it('should require title', async () => {
      const feedData = { ...testFeeds.validFeed };
      delete feedData.title;
      const feed = new Feed(feedData);
      
      await expect(feed.save()).rejects.toThrow();
    });

    it('should enforce unique url', async () => {
      const feedData1 = { ...testFeeds.validFeed };
      const feedData2 = { ...testFeeds.validFeed, title: 'Different Title' };
      
      const feed1 = new Feed(feedData1);
      await feed1.save();
      
      const feed2 = new Feed(feedData2);
      await expect(feed2.save()).rejects.toThrow();
    });

    it('should set default values', () => {
      const feed = new Feed(testFeeds.validFeed);
      
      expect(feed.status).toBe('active');
      expect(feed.ttl).toBe(60);
      expect(feed.errorCount).toBe(0);
      expect(feed.createdAt).toBeInstanceOf(Date);
      expect(feed.updatedAt).toBeInstanceOf(Date);
    });

    it('should validate status enum', async () => {
      const feedData = { ...testFeeds.validFeed, status: 'invalid' };
      const feed = new Feed(feedData);
      
      await expect(feed.save()).rejects.toThrow();
    });

    it('should accept valid status values', async () => {
      const statuses = ['active', 'inactive', 'error', 'deleted'];
      
      for (const status of statuses) {
        const feedData = { 
          ...testFeeds.validFeed, 
          url: `https://example${status}.com/feed.xml`,
          status 
        };
        const feed = new Feed(feedData);
        await feed.save();
        
        expect(feed.status).toBe(status);
      }
    });
  });

  describe('Feed Pre-save Hook', () => {
    it('should update updatedAt on save', async () => {
      const feed = new Feed(testFeeds.validFeed);
      await feed.save();
      
      const originalUpdatedAt = feed.updatedAt;
      
      // Wait a moment to ensure time difference
      await new Promise(resolve => setTimeout(resolve, 10));
      
      feed.title = 'Updated Title';
      await feed.save();
      
      expect(feed.updatedAt.getTime()).toBeGreaterThan(originalUpdatedAt.getTime());
    });
  });

  describe('Feed Image', () => {
    it('should accept image object', async () => {
      const feedData = {
        ...testFeeds.validFeed,
        image: {
          url: 'https://example.com/image.png',
          title: 'Feed Image',
          link: 'https://example.com'
        }
      };
      
      const feed = new Feed(feedData);
      await feed.save();
      
      expect(feed.image.url).toBe('https://example.com/image.png');
      expect(feed.image.title).toBe('Feed Image');
      expect(feed.image.link).toBe('https://example.com');
    });
  });

  describe('Feed Error Handling', () => {
    it('should track error count and message', async () => {
      const feedData = {
        ...testFeeds.validFeed,
        status: 'error',
        errorMessage: 'Failed to fetch feed',
        errorCount: 3
      };
      
      const feed = new Feed(feedData);
      await feed.save();
      
      expect(feed.status).toBe('error');
      expect(feed.errorMessage).toBe('Failed to fetch feed');
      expect(feed.errorCount).toBe(3);
    });
  });

  describe('Feed Timestamps', () => {
    it('should track fetch timestamps', async () => {
      const now = new Date();
      const feedData = {
        ...testFeeds.validFeed,
        lastFetchedAt: now,
        lastSuccessfulFetchAt: now
      };
      
      const feed = new Feed(feedData);
      await feed.save();
      
      expect(feed.lastFetchedAt).toEqual(now);
      expect(feed.lastSuccessfulFetchAt).toEqual(now);
    });

    it('should accept etag and lastModified for caching', async () => {
      const feedData = {
        ...testFeeds.validFeed,
        etag: '"abc123"',
        lastModified: 'Wed, 21 Oct 2015 07:28:00 GMT'
      };
      
      const feed = new Feed(feedData);
      await feed.save();
      
      expect(feed.etag).toBe('"abc123"');
      expect(feed.lastModified).toBe('Wed, 21 Oct 2015 07:28:00 GMT');
    });
  });
});