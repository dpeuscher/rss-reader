// Tests that don't require database connection
const feedParserService = require('../../src/services/feedParser');

// Mock dependencies
jest.mock('../../src/models', () => ({
  Feed: {
    findOne: jest.fn(),
    findById: jest.fn(),
    findByIdAndUpdate: jest.fn()
  },
  Article: {
    findOne: jest.fn()
  }
}));

describe('FeedParserService (No DB)', () => {
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
  });
});