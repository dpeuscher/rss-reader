const mongoose = require('mongoose');

const feedSchema = new mongoose.Schema({
  url: {
    type: String,
    required: true,
    unique: true,
    trim: true
  },
  title: {
    type: String,
    required: true,
    trim: true
  },
  description: {
    type: String,
    trim: true
  },
  link: {
    type: String,
    trim: true
  },
  language: {
    type: String,
    trim: true
  },
  copyright: {
    type: String,
    trim: true
  },
  generator: {
    type: String,
    trim: true
  },
  lastBuildDate: {
    type: Date
  },
  pubDate: {
    type: Date
  },
  ttl: {
    type: Number,
    default: 60
  },
  image: {
    url: String,
    title: String,
    link: String
  },
  status: {
    type: String,
    enum: ['active', 'inactive', 'error', 'deleted'],
    default: 'active'
  },
  errorMessage: {
    type: String
  },
  errorCount: {
    type: Number,
    default: 0
  },
  lastFetchedAt: {
    type: Date
  },
  lastSuccessfulFetchAt: {
    type: Date
  },
  etag: {
    type: String
  },
  lastModified: {
    type: String
  },
  createdAt: {
    type: Date,
    default: Date.now
  },
  updatedAt: {
    type: Date,
    default: Date.now
  }
});

feedSchema.index({ url: 1 });
feedSchema.index({ status: 1 });
feedSchema.index({ lastFetchedAt: 1 });

feedSchema.pre('save', function(next) {
  this.updatedAt = Date.now();
  next();
});

module.exports = mongoose.model('Feed', feedSchema);