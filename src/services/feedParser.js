const Parser = require('rss-parser');
const { Feed, Article } = require('../models');

class FeedParserService {
  constructor() {
    this.parser = new Parser({
      customFields: {
        feed: ['subtitle', 'updated', 'rights', 'generator', 'icon', 'logo'],
        item: ['updated', 'summary', 'content', 'media:group', 'media:thumbnail', 'enclosure']
      }
    });
  }

  async parseFeedFromUrl(url) {
    try {
      const feed = await this.parser.parseURL(url);
      return this.normalizeFeedData(feed, url);
    } catch (error) {
      throw new Error(`Failed to parse feed from ${url}: ${error.message}`);
    }
  }

  async parseFeedFromString(feedContent, url) {
    try {
      const feed = await this.parser.parseString(feedContent);
      return this.normalizeFeedData(feed, url);
    } catch (error) {
      throw new Error(`Failed to parse feed content: ${error.message}`);
    }
  }

  normalizeFeedData(parsedFeed, url) {
    const feedData = {
      url: url,
      title: parsedFeed.title || 'Untitled Feed',
      description: parsedFeed.description || parsedFeed.subtitle || '',
      link: parsedFeed.link || '',
      language: parsedFeed.language || '',
      copyright: parsedFeed.copyright || parsedFeed.rights || '',
      generator: parsedFeed.generator || '',
      lastBuildDate: this.parseDate(parsedFeed.lastBuildDate || parsedFeed.updated),
      pubDate: this.parseDate(parsedFeed.pubDate),
      ttl: parsedFeed.ttl ? parseInt(parsedFeed.ttl) : 60,
      image: this.parseImage(parsedFeed.image || parsedFeed.logo || parsedFeed.icon)
    };

    const articles = parsedFeed.items.map(item => this.normalizeArticleData(item));

    return {
      feed: feedData,
      articles: articles
    };
  }

  normalizeArticleData(item) {
    return {
      title: item.title || 'Untitled Article',
      description: item.summary || item.description || '',
      content: item.content || item['content:encoded'] || item.description || '',
      link: item.link || '',
      guid: item.guid || item.id || item.link || '',
      pubDate: this.parseDate(item.pubDate || item.isoDate || item.updated),
      author: item.creator || item.author || '',
      categories: this.parseCategories(item.categories),
      enclosures: this.parseEnclosures(item.enclosure || item.enclosures)
    };
  }

  parseDate(dateString) {
    if (!dateString) return null;
    
    const date = new Date(dateString);
    return isNaN(date.getTime()) ? null : date;
  }

  parseImage(imageData) {
    if (!imageData) return null;

    if (typeof imageData === 'string') {
      return { url: imageData };
    }

    return {
      url: imageData.url || imageData.href || '',
      title: imageData.title || '',
      link: imageData.link || ''
    };
  }

  parseCategories(categories) {
    if (!categories) return [];
    
    if (Array.isArray(categories)) {
      return categories.map(cat => typeof cat === 'string' ? cat : cat.name || '').filter(Boolean);
    }
    
    return typeof categories === 'string' ? [categories] : [];
  }

  parseEnclosures(enclosures) {
    if (!enclosures) return [];
    
    const enclosureArray = Array.isArray(enclosures) ? enclosures : [enclosures];
    
    return enclosureArray.map(enc => ({
      url: enc.url || '',
      type: enc.type || '',
      length: enc.length ? parseInt(enc.length) : 0
    })).filter(enc => enc.url);
  }

  async saveFeedAndArticles(feedData, articlesData, feedId = null) {
    try {
      let feed;
      
      if (feedId) {
        feed = await Feed.findByIdAndUpdate(feedId, {
          ...feedData,
          lastFetchedAt: new Date(),
          lastSuccessfulFetchAt: new Date(),
          status: 'active',
          errorCount: 0,
          errorMessage: null
        }, { new: true });
      } else {
        const existingFeed = await Feed.findOne({ url: feedData.url });
        if (existingFeed) {
          feed = await Feed.findByIdAndUpdate(existingFeed._id, {
            ...feedData,
            lastFetchedAt: new Date(),
            lastSuccessfulFetchAt: new Date(),
            status: 'active',
            errorCount: 0,
            errorMessage: null
          }, { new: true });
        } else {
          feed = new Feed({
            ...feedData,
            lastFetchedAt: new Date(),
            lastSuccessfulFetchAt: new Date()
          });
          await feed.save();
        }
      }

      const savedArticles = [];
      
      for (const articleData of articlesData) {
        try {
          const existingArticle = await Article.findOne({
            feed: feed._id,
            guid: articleData.guid
          });

          if (!existingArticle) {
            const article = new Article({
              ...articleData,
              feed: feed._id
            });
            await article.save();
            savedArticles.push(article);
          }
        } catch (error) {
          console.error(`Error saving article: ${error.message}`);
        }
      }

      return {
        feed,
        articles: savedArticles,
        totalArticles: articlesData.length,
        newArticles: savedArticles.length
      };
    } catch (error) {
      throw new Error(`Failed to save feed and articles: ${error.message}`);
    }
  }

  async updateFeedError(feedId, errorMessage) {
    try {
      const feed = await Feed.findById(feedId);
      if (feed) {
        feed.errorCount = (feed.errorCount || 0) + 1;
        feed.errorMessage = errorMessage;
        feed.lastFetchedAt = new Date();
        
        if (feed.errorCount >= 5) {
          feed.status = 'error';
        }
        
        await feed.save();
      }
    } catch (error) {
      console.error(`Error updating feed error status: ${error.message}`);
    }
  }
}

module.exports = new FeedParserService();