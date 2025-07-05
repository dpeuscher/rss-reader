const express = require('express');
const { body, query } = require('express-validator');
const auth = require('../middleware/auth');
const feedManager = require('../services/feedManager');

const router = express.Router();

router.post('/subscribe', auth, [
  body('url')
    .isURL()
    .withMessage('Please provide a valid URL'),
  body('categoryId')
    .optional()
    .isMongoId()
    .withMessage('Invalid category ID'),
  body('customTitle')
    .optional()
    .isLength({ max: 200 })
    .withMessage('Custom title must not exceed 200 characters')
], async (req, res) => {
  try {
    const { url, categoryId, customTitle } = req.body;
    const result = await feedManager.subscribeTo(req.user._id, url, categoryId, customTitle);
    res.status(201).json({
      message: 'Successfully subscribed to feed',
      subscription: result
    });
  } catch (error) {
    res.status(400).json({ message: error.message });
  }
});

router.delete('/:feedId/unsubscribe', auth, async (req, res) => {
  try {
    const result = await feedManager.unsubscribeFrom(req.user._id, req.params.feedId);
    res.json({
      message: 'Successfully unsubscribed from feed',
      subscription: result
    });
  } catch (error) {
    res.status(400).json({ message: error.message });
  }
});

router.get('/', auth, [
  query('categoryId')
    .optional()
    .isMongoId()
    .withMessage('Invalid category ID')
], async (req, res) => {
  try {
    const { categoryId } = req.query;
    const feeds = await feedManager.getUserFeeds(req.user._id, categoryId);
    res.json({ feeds });
  } catch (error) {
    res.status(500).json({ message: error.message });
  }
});

router.get('/:feedId', auth, async (req, res) => {
  try {
    const feed = await feedManager.getUserFeedWithDetails(req.params.feedId);
    if (feed.user._id.toString() !== req.user._id.toString()) {
      return res.status(403).json({ message: 'Access denied' });
    }
    res.json({ feed });
  } catch (error) {
    res.status(404).json({ message: error.message });
  }
});

router.put('/:feedId', auth, [
  body('customTitle')
    .optional()
    .isLength({ max: 200 })
    .withMessage('Custom title must not exceed 200 characters'),
  body('refreshFrequency')
    .optional()
    .isInt({ min: 15, max: 1440 })
    .withMessage('Refresh frequency must be between 15 and 1440 minutes'),
  body('categoryId')
    .optional()
    .isMongoId()
    .withMessage('Invalid category ID')
], async (req, res) => {
  try {
    const updates = req.body;
    const result = await feedManager.updateUserFeed(req.user._id, req.params.feedId, updates);
    res.json({
      message: 'Feed updated successfully',
      subscription: result
    });
  } catch (error) {
    res.status(400).json({ message: error.message });
  }
});

router.get('/:feedId/articles', auth, [
  query('page')
    .optional()
    .isInt({ min: 1 })
    .withMessage('Page must be a positive integer'),
  query('limit')
    .optional()
    .isInt({ min: 1, max: 200 })
    .withMessage('Limit must be between 1 and 200'),
  query('unreadOnly')
    .optional()
    .isBoolean()
    .withMessage('UnreadOnly must be a boolean'),
  query('starredOnly')
    .optional()
    .isBoolean()
    .withMessage('StarredOnly must be a boolean'),
  query('search')
    .optional()
    .isLength({ max: 100 })
    .withMessage('Search term must not exceed 100 characters')
], async (req, res) => {
  try {
    const options = {
      page: parseInt(req.query.page) || 1,
      limit: parseInt(req.query.limit) || 50,
      unreadOnly: req.query.unreadOnly === 'true',
      starredOnly: req.query.starredOnly === 'true',
      search: req.query.search
    };
    
    const result = await feedManager.getFeedArticles(req.user._id, req.params.feedId, options);
    res.json(result);
  } catch (error) {
    res.status(400).json({ message: error.message });
  }
});

router.post('/:feedId/refresh', auth, async (req, res) => {
  try {
    const result = await feedManager.refreshFeed(req.params.feedId);
    res.json({
      message: 'Feed refreshed successfully',
      result
    });
  } catch (error) {
    res.status(400).json({ message: error.message });
  }
});

router.post('/categories', auth, [
  body('name')
    .notEmpty()
    .isLength({ max: 100 })
    .withMessage('Category name is required and must not exceed 100 characters'),
  body('parentId')
    .optional()
    .isMongoId()
    .withMessage('Invalid parent category ID'),
  body('color')
    .optional()
    .matches(/^#[0-9A-F]{6}$/i)
    .withMessage('Color must be a valid hex color')
], async (req, res) => {
  try {
    const { name, parentId, color } = req.body;
    const category = await feedManager.createCategory(req.user._id, name, parentId, color);
    res.status(201).json({
      message: 'Category created successfully',
      category
    });
  } catch (error) {
    res.status(400).json({ message: error.message });
  }
});

router.get('/categories/list', auth, async (req, res) => {
  try {
    const categories = await feedManager.getUserCategories(req.user._id);
    res.json({ categories });
  } catch (error) {
    res.status(500).json({ message: error.message });
  }
});

router.put('/categories/:categoryId', auth, [
  body('name')
    .optional()
    .isLength({ max: 100 })
    .withMessage('Category name must not exceed 100 characters'),
  body('parentId')
    .optional()
    .isMongoId()
    .withMessage('Invalid parent category ID'),
  body('color')
    .optional()
    .matches(/^#[0-9A-F]{6}$/i)
    .withMessage('Color must be a valid hex color'),
  body('order')
    .optional()
    .isInt({ min: 0 })
    .withMessage('Order must be a non-negative integer')
], async (req, res) => {
  try {
    const updates = req.body;
    const category = await feedManager.updateCategory(req.user._id, req.params.categoryId, updates);
    res.json({
      message: 'Category updated successfully',
      category
    });
  } catch (error) {
    res.status(400).json({ message: error.message });
  }
});

router.delete('/categories/:categoryId', auth, async (req, res) => {
  try {
    const result = await feedManager.deleteCategory(req.user._id, req.params.categoryId);
    res.json(result);
  } catch (error) {
    res.status(400).json({ message: error.message });
  }
});

module.exports = router;