const mongoose = require('mongoose');

const userFeedSchema = new mongoose.Schema({
  user: {
    type: mongoose.Schema.Types.ObjectId,
    ref: 'User',
    required: true
  },
  feed: {
    type: mongoose.Schema.Types.ObjectId,
    ref: 'Feed',
    required: true
  },
  category: {
    type: mongoose.Schema.Types.ObjectId,
    ref: 'Category',
    default: null
  },
  customTitle: {
    type: String,
    trim: true
  },
  refreshFrequency: {
    type: Number,
    default: 60
  },
  isActive: {
    type: Boolean,
    default: true
  },
  subscribedAt: {
    type: Date,
    default: Date.now
  }
});

userFeedSchema.index({ user: 1, feed: 1 }, { unique: true });
userFeedSchema.index({ user: 1, category: 1 });

module.exports = mongoose.model('UserFeed', userFeedSchema);