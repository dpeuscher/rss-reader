const express = require('express');
const { body, query } = require('express-validator');
const auth = require('../middleware/auth');
const articleProcessor = require('../services/articleProcessor');

const router = express.Router();

router.get('/', auth, [
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
  query('feedId')
    .optional()
    .isMongoId()
    .withMessage('Invalid feed ID'),
  query('categoryId')
    .optional()
    .isMongoId()
    .withMessage('Invalid category ID'),
  query('search')
    .optional()
    .isLength({ max: 100 })
    .withMessage('Search term must not exceed 100 characters'),
  query('sortBy')
    .optional()
    .isIn(['pubDate', 'title', 'author'])
    .withMessage('SortBy must be pubDate, title, or author'),
  query('sortOrder')
    .optional()
    .isIn(['asc', 'desc'])
    .withMessage('SortOrder must be asc or desc')
], async (req, res) => {
  try {
    const options = {
      page: parseInt(req.query.page) || 1,
      limit: parseInt(req.query.limit) || 50,
      unreadOnly: req.query.unreadOnly === 'true',
      starredOnly: req.query.starredOnly === 'true',
      feedId: req.query.feedId,
      categoryId: req.query.categoryId,
      search: req.query.search,
      sortBy: req.query.sortBy || 'pubDate',
      sortOrder: req.query.sortOrder || 'desc'
    };
    
    const result = await articleProcessor.getAllArticles(req.user._id, options);
    res.json(result);
  } catch (error) {
    res.status(400).json({ message: error.message });
  }
});

router.get('/search', auth, [
  query('q')
    .notEmpty()
    .isLength({ max: 100 })
    .withMessage('Search query is required and must not exceed 100 characters'),
  query('page')
    .optional()
    .isInt({ min: 1 })
    .withMessage('Page must be a positive integer'),
  query('limit')
    .optional()
    .isInt({ min: 1, max: 200 })
    .withMessage('Limit must be between 1 and 200')
], async (req, res) => {
  try {
    const options = {
      page: parseInt(req.query.page) || 1,
      limit: parseInt(req.query.limit) || 50
    };
    
    const result = await articleProcessor.searchArticles(req.user._id, req.query.q, options);
    res.json(result);
  } catch (error) {
    res.status(400).json({ message: error.message });
  }
});

router.get('/stats', auth, async (req, res) => {
  try {
    const stats = await articleProcessor.getArticleStats(req.user._id);
    res.json({ stats });
  } catch (error) {
    res.status(500).json({ message: error.message });
  }
});

router.get('/unread-count', auth, [
  query('feedId')
    .optional()
    .isMongoId()
    .withMessage('Invalid feed ID')
], async (req, res) => {
  try {
    const count = await articleProcessor.getUnreadCount(req.user._id, req.query.feedId);
    res.json({ count });
  } catch (error) {
    res.status(500).json({ message: error.message });
  }
});

router.get('/:articleId', auth, async (req, res) => {
  try {
    const article = await articleProcessor.getArticleById(req.user._id, req.params.articleId);
    res.json({ article });
  } catch (error) {
    res.status(404).json({ message: error.message });
  }
});

router.post('/:articleId/read', auth, [
  body('isRead')
    .optional()
    .isBoolean()
    .withMessage('IsRead must be a boolean')
], async (req, res) => {
  try {
    const isRead = req.body.isRead !== undefined ? req.body.isRead : true;
    const result = await articleProcessor.markAsRead(req.user._id, req.params.articleId, isRead);
    res.json({
      message: `Article marked as ${isRead ? 'read' : 'unread'}`,
      userArticle: result
    });
  } catch (error) {
    res.status(400).json({ message: error.message });
  }
});

router.post('/:articleId/star', auth, [
  body('isStarred')
    .optional()
    .isBoolean()
    .withMessage('IsStarred must be a boolean')
], async (req, res) => {
  try {
    const isStarred = req.body.isStarred !== undefined ? req.body.isStarred : true;
    const result = await articleProcessor.markAsStarred(req.user._id, req.params.articleId, isStarred);
    res.json({
      message: `Article ${isStarred ? 'starred' : 'unstarred'}`,
      userArticle: result
    });
  } catch (error) {
    res.status(400).json({ message: error.message });
  }
});

router.post('/bulk/read', auth, [
  body('articleIds')
    .isArray({ min: 1 })
    .withMessage('ArticleIds must be a non-empty array'),
  body('articleIds.*')
    .isMongoId()
    .withMessage('Each article ID must be valid'),
  body('isRead')
    .optional()
    .isBoolean()
    .withMessage('IsRead must be a boolean')
], async (req, res) => {
  try {
    const { articleIds, isRead = true } = req.body;
    const results = await articleProcessor.markMultipleAsRead(req.user._id, articleIds, isRead);
    res.json({
      message: `Articles marked as ${isRead ? 'read' : 'unread'}`,
      results
    });
  } catch (error) {
    res.status(400).json({ message: error.message });
  }
});

router.post('/mark-all-read', auth, [
  body('feedId')
    .optional()
    .isMongoId()
    .withMessage('Invalid feed ID'),
  body('categoryId')
    .optional()
    .isMongoId()
    .withMessage('Invalid category ID')
], async (req, res) => {
  try {
    const { feedId, categoryId } = req.body;
    const result = await articleProcessor.markAllAsRead(req.user._id, feedId, categoryId);
    res.json({
      message: 'All articles marked as read',
      ...result
    });
  } catch (error) {
    res.status(400).json({ message: error.message });
  }
});

module.exports = router;