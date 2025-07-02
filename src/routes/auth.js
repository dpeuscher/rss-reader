const express = require('express');
const { body } = require('express-validator');
const authController = require('../controllers/authController');
const auth = require('../middleware/auth');

const router = express.Router();

router.post('/register', [
  body('username')
    .isLength({ min: 3, max: 50 })
    .withMessage('Username must be between 3 and 50 characters')
    .matches(/^[a-zA-Z0-9_]+$/)
    .withMessage('Username can only contain letters, numbers, and underscores'),
  body('email')
    .isEmail()
    .withMessage('Please provide a valid email')
    .normalizeEmail(),
  body('password')
    .isLength({ min: 6 })
    .withMessage('Password must be at least 6 characters long')
], authController.register);

router.post('/login', [
  body('email')
    .isEmail()
    .withMessage('Please provide a valid email')
    .normalizeEmail(),
  body('password')
    .notEmpty()
    .withMessage('Password is required')
], authController.login);

router.get('/profile', auth, authController.getProfile);

router.put('/profile', auth, [
  body('preferences.articlesPerPage')
    .optional()
    .isInt({ min: 10, max: 200 })
    .withMessage('Articles per page must be between 10 and 200'),
  body('preferences.defaultView')
    .optional()
    .isIn(['list', 'expanded', 'magazine'])
    .withMessage('Default view must be list, expanded, or magazine'),
  body('preferences.autoMarkAsRead')
    .optional()
    .isBoolean()
    .withMessage('Auto mark as read must be a boolean')
], authController.updateProfile);

module.exports = router;