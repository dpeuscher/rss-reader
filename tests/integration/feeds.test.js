const request = require('supertest');
const jwt = require('jsonwebtoken');
const app = require('../testApp');
const { User, Feed, Category } = require('../../src/models');
const { testUsers, testFeeds, testCategories } = require('../fixtures/testData');

// Mock the feed manager service for testing
jest.mock('../../src/services/feedManager', () => ({
  subscribeTo: jest.fn(),
  unsubscribeFrom: jest.fn(),
  getUserFeeds: jest.fn(),
  getUserFeedWithDetails: jest.fn(),
  updateUserFeed: jest.fn(),
  getFeedArticles: jest.fn(),
  refreshFeed: jest.fn(),
  createCategory: jest.fn(),
  getUserCategories: jest.fn(),
  updateCategory: jest.fn(),
  deleteCategory: jest.fn()
}));

const feedManager = require('../../src/services/feedManager');

describe('Feeds API', () => {
  let user, token;

  beforeEach(async () => {
    await User.deleteMany({});
    await Feed.deleteMany({});
    await Category.deleteMany({});
    
    user = new User(testUsers.validUser);
    await user.save();
    token = jwt.sign({ id: user._id }, process.env.JWT_SECRET || 'fallback-secret');
    
    // Reset all mocks
    jest.clearAllMocks();
  });

  describe('POST /api/feeds/subscribe', () => {
    it('should subscribe to a feed successfully', async () => {
      const mockSubscription = {
        feed: testFeeds.validFeed,
        user: user._id,
        subscriptionData: { customTitle: 'My Feed' }
      };

      feedManager.subscribeTo.mockResolvedValue(mockSubscription);

      const response = await request(app)
        .post('/api/feeds/subscribe')
        .set('Authorization', `Bearer ${token}`)
        .send({
          url: testFeeds.validFeed.url,
          customTitle: 'My Feed'
        })
        .expect(201);

      expect(response.body.message).toBe('Successfully subscribed to feed');
      expect(response.body.subscription).toEqual(mockSubscription);
      expect(feedManager.subscribeTo).toHaveBeenCalledWith(
        user._id,
        testFeeds.validFeed.url,
        undefined,
        'My Feed'
      );
    });

    it('should return 400 for invalid URL', async () => {
      const response = await request(app)
        .post('/api/feeds/subscribe')
        .set('Authorization', `Bearer ${token}`)
        .send({ url: 'invalid-url' })
        .expect(400);

      expect(response.body.errors).toBeDefined();
    });

    it('should return 401 without authentication', async () => {
      await request(app)
        .post('/api/feeds/subscribe')
        .send({ url: testFeeds.validFeed.url })
        .expect(401);
    });

    it('should handle service errors', async () => {
      feedManager.subscribeTo.mockRejectedValue(new Error('Feed already exists'));

      const response = await request(app)
        .post('/api/feeds/subscribe')
        .set('Authorization', `Bearer ${token}`)
        .send({ url: testFeeds.validFeed.url })
        .expect(400);

      expect(response.body.message).toBe('Feed already exists');
    });
  });

  describe('DELETE /api/feeds/:feedId/unsubscribe', () => {
    it('should unsubscribe from feed successfully', async () => {
      const mockResult = { message: 'Unsubscribed successfully' };
      feedManager.unsubscribeFrom.mockResolvedValue(mockResult);

      const feedId = '507f1f77bcf86cd799439011';
      const response = await request(app)
        .delete(`/api/feeds/${feedId}/unsubscribe`)
        .set('Authorization', `Bearer ${token}`)
        .expect(200);

      expect(response.body.message).toBe('Successfully unsubscribed from feed');
      expect(feedManager.unsubscribeFrom).toHaveBeenCalledWith(user._id, feedId);
    });

    it('should require authentication', async () => {
      const feedId = '507f1f77bcf86cd799439011';
      await request(app)
        .delete(`/api/feeds/${feedId}/unsubscribe`)
        .expect(401);
    });
  });

  describe('GET /api/feeds', () => {
    it('should get user feeds', async () => {
      const mockFeeds = [testFeeds.validFeed, testFeeds.secondFeed];
      feedManager.getUserFeeds.mockResolvedValue(mockFeeds);

      const response = await request(app)
        .get('/api/feeds')
        .set('Authorization', `Bearer ${token}`)
        .expect(200);

      expect(response.body.feeds).toEqual(mockFeeds);
      expect(feedManager.getUserFeeds).toHaveBeenCalledWith(user._id, undefined);
    });

    it('should filter by category', async () => {
      const categoryId = '507f1f77bcf86cd799439011';
      const mockFeeds = [testFeeds.validFeed];
      feedManager.getUserFeeds.mockResolvedValue(mockFeeds);

      const response = await request(app)
        .get('/api/feeds')
        .set('Authorization', `Bearer ${token}`)
        .query({ categoryId })
        .expect(200);

      expect(feedManager.getUserFeeds).toHaveBeenCalledWith(user._id, categoryId);
    });

    it('should return 400 for invalid categoryId', async () => {
      const response = await request(app)
        .get('/api/feeds')
        .set('Authorization', `Bearer ${token}`)
        .query({ categoryId: 'invalid-id' })
        .expect(400);

      expect(response.body.errors).toBeDefined();
    });
  });

  describe('GET /api/feeds/:feedId', () => {
    it('should get feed details', async () => {
      const feedId = '507f1f77bcf86cd799439011';
      const mockFeed = {
        ...testFeeds.validFeed,
        user: { _id: user._id }
      };
      feedManager.getUserFeedWithDetails.mockResolvedValue(mockFeed);

      const response = await request(app)
        .get(`/api/feeds/${feedId}`)
        .set('Authorization', `Bearer ${token}`)
        .expect(200);

      expect(response.body.feed).toEqual(mockFeed);
    });

    it('should return 403 for unauthorized access', async () => {
      const feedId = '507f1f77bcf86cd799439011';
      const mockFeed = {
        ...testFeeds.validFeed,
        user: { _id: '507f1f77bcf86cd799439012' } // Different user
      };
      feedManager.getUserFeedWithDetails.mockResolvedValue(mockFeed);

      await request(app)
        .get(`/api/feeds/${feedId}`)
        .set('Authorization', `Bearer ${token}`)
        .expect(403);
    });
  });

  describe('PUT /api/feeds/:feedId', () => {
    it('should update feed settings', async () => {
      const feedId = '507f1f77bcf86cd799439011';
      const updates = {
        customTitle: 'Updated Title',
        refreshFrequency: 30
      };
      const mockResult = { ...testFeeds.validFeed, ...updates };
      feedManager.updateUserFeed.mockResolvedValue(mockResult);

      const response = await request(app)
        .put(`/api/feeds/${feedId}`)
        .set('Authorization', `Bearer ${token}`)
        .send(updates)
        .expect(200);

      expect(response.body.message).toBe('Feed updated successfully');
      expect(feedManager.updateUserFeed).toHaveBeenCalledWith(user._id, feedId, updates);
    });

    it('should validate refresh frequency', async () => {
      const feedId = '507f1f77bcf86cd799439011';
      const response = await request(app)
        .put(`/api/feeds/${feedId}`)
        .set('Authorization', `Bearer ${token}`)
        .send({ refreshFrequency: 5 }) // Too low
        .expect(400);

      expect(response.body.errors).toBeDefined();
    });
  });

  describe('GET /api/feeds/:feedId/articles', () => {
    it('should get feed articles with default pagination', async () => {
      const feedId = '507f1f77bcf86cd799439011';
      const mockResult = {
        articles: [],
        pagination: { page: 1, limit: 50, total: 0 }
      };
      feedManager.getFeedArticles.mockResolvedValue(mockResult);

      const response = await request(app)
        .get(`/api/feeds/${feedId}/articles`)
        .set('Authorization', `Bearer ${token}`)
        .expect(200);

      expect(feedManager.getFeedArticles).toHaveBeenCalledWith(
        user._id,
        feedId,
        {
          page: 1,
          limit: 50,
          unreadOnly: false,
          starredOnly: false,
          search: undefined
        }
      );
    });

    it('should handle custom pagination and filters', async () => {
      const feedId = '507f1f77bcf86cd799439011';
      const mockResult = {
        articles: [],
        pagination: { page: 2, limit: 25, total: 0 }
      };
      feedManager.getFeedArticles.mockResolvedValue(mockResult);

      const response = await request(app)
        .get(`/api/feeds/${feedId}/articles`)
        .set('Authorization', `Bearer ${token}`)
        .query({
          page: 2,
          limit: 25,
          unreadOnly: true,
          starredOnly: false,
          search: 'test'
        })
        .expect(200);

      expect(feedManager.getFeedArticles).toHaveBeenCalledWith(
        user._id,
        feedId,
        {
          page: 2,
          limit: 25,
          unreadOnly: true,
          starredOnly: false,
          search: 'test'
        }
      );
    });

    it('should validate pagination parameters', async () => {
      const feedId = '507f1f77bcf86cd799439011';
      const response = await request(app)
        .get(`/api/feeds/${feedId}/articles`)
        .set('Authorization', `Bearer ${token}`)
        .query({ page: 0, limit: 300 }) // Invalid values
        .expect(400);

      expect(response.body.errors).toBeDefined();
    });
  });

  describe('POST /api/feeds/:feedId/refresh', () => {
    it('should refresh feed successfully', async () => {
      const feedId = '507f1f77bcf86cd799439011';
      const mockResult = { newArticles: 5, totalArticles: 100 };
      feedManager.refreshFeed.mockResolvedValue(mockResult);

      const response = await request(app)
        .post(`/api/feeds/${feedId}/refresh`)
        .set('Authorization', `Bearer ${token}`)
        .expect(200);

      expect(response.body.message).toBe('Feed refreshed successfully');
      expect(response.body.result).toEqual(mockResult);
    });
  });

  describe('POST /api/feeds/categories', () => {
    it('should create category successfully', async () => {
      const categoryData = {
        name: 'Technology',
        color: '#007bff'
      };
      const mockCategory = { ...testCategories.validCategory, _id: '507f1f77bcf86cd799439011' };
      feedManager.createCategory.mockResolvedValue(mockCategory);

      const response = await request(app)
        .post('/api/feeds/categories')
        .set('Authorization', `Bearer ${token}`)
        .send(categoryData)
        .expect(201);

      expect(response.body.message).toBe('Category created successfully');
      expect(response.body.category).toEqual(mockCategory);
    });

    it('should validate category name', async () => {
      const response = await request(app)
        .post('/api/feeds/categories')
        .set('Authorization', `Bearer ${token}`)
        .send({ color: '#007bff' }) // Missing name
        .expect(400);

      expect(response.body.errors).toBeDefined();
    });

    it('should validate color format', async () => {
      const response = await request(app)
        .post('/api/feeds/categories')
        .set('Authorization', `Bearer ${token}`)
        .send({
          name: 'Technology',
          color: 'invalid-color'
        })
        .expect(400);

      expect(response.body.errors).toBeDefined();
    });
  });

  describe('GET /api/feeds/categories/list', () => {
    it('should get user categories', async () => {
      const mockCategories = [testCategories.validCategory, testCategories.secondCategory];
      feedManager.getUserCategories.mockResolvedValue(mockCategories);

      const response = await request(app)
        .get('/api/feeds/categories/list')
        .set('Authorization', `Bearer ${token}`)
        .expect(200);

      expect(response.body.categories).toEqual(mockCategories);
    });
  });

  describe('PUT /api/feeds/categories/:categoryId', () => {
    it('should update category', async () => {
      const categoryId = '507f1f77bcf86cd799439011';
      const updates = { name: 'Updated Category', color: '#28a745' };
      const mockCategory = { ...testCategories.validCategory, ...updates };
      feedManager.updateCategory.mockResolvedValue(mockCategory);

      const response = await request(app)
        .put(`/api/feeds/categories/${categoryId}`)
        .set('Authorization', `Bearer ${token}`)
        .send(updates)
        .expect(200);

      expect(response.body.message).toBe('Category updated successfully');
      expect(feedManager.updateCategory).toHaveBeenCalledWith(user._id, categoryId, updates);
    });
  });

  describe('DELETE /api/feeds/categories/:categoryId', () => {
    it('should delete category', async () => {
      const categoryId = '507f1f77bcf86cd799439011';
      const mockResult = { message: 'Category deleted successfully' };
      feedManager.deleteCategory.mockResolvedValue(mockResult);

      const response = await request(app)
        .delete(`/api/feeds/categories/${categoryId}`)
        .set('Authorization', `Bearer ${token}`)
        .expect(200);

      expect(response.body).toEqual(mockResult);
    });
  });
});