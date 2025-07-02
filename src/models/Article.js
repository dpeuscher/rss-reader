const mongoose = require('mongoose');

const articleSchema = new mongoose.Schema({
  title: {
    type: String,
    required: true,
    trim: true
  },
  description: {
    type: String,
    trim: true
  },
  content: {
    type: String,
    trim: true
  },
  link: {
    type: String,
    required: true,
    trim: true
  },
  guid: {
    type: String,
    required: true,
    trim: true
  },
  pubDate: {
    type: Date,
    required: true
  },
  author: {
    type: String,
    trim: true
  },
  categories: [{
    type: String,
    trim: true
  }],
  enclosures: [{
    url: String,
    type: String,
    length: Number
  }],
  feed: {
    type: mongoose.Schema.Types.ObjectId,
    ref: 'Feed',
    required: true
  },
  createdAt: {
    type: Date,
    default: Date.now
  }
});

articleSchema.index({ feed: 1, guid: 1 }, { unique: true });
articleSchema.index({ feed: 1, pubDate: -1 });
articleSchema.index({ pubDate: -1 });

module.exports = mongoose.model('Article', articleSchema);