const mongoose = require('mongoose');

const userArticleSchema = new mongoose.Schema({
  user: {
    type: mongoose.Schema.Types.ObjectId,
    ref: 'User',
    required: true
  },
  article: {
    type: mongoose.Schema.Types.ObjectId,
    ref: 'Article',
    required: true
  },
  isRead: {
    type: Boolean,
    default: false
  },
  isStarred: {
    type: Boolean,
    default: false
  },
  readAt: {
    type: Date
  },
  starredAt: {
    type: Date
  },
  createdAt: {
    type: Date,
    default: Date.now
  }
});

userArticleSchema.index({ user: 1, article: 1 }, { unique: true });
userArticleSchema.index({ user: 1, isRead: 1 });
userArticleSchema.index({ user: 1, isStarred: 1 });

userArticleSchema.pre('save', function(next) {
  if (this.isModified('isRead') && this.isRead) {
    this.readAt = new Date();
  }
  if (this.isModified('isStarred') && this.isStarred) {
    this.starredAt = new Date();
  }
  next();
});

module.exports = mongoose.model('UserArticle', userArticleSchema);