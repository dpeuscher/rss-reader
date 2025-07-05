const { Feed, UserFeed, Category, Article, UserArticle } = require('../models');
const feedParser = require('./feedParser');

class FeedManagerService {
  async subscribeTo(userId, feedUrl, categoryId = null, customTitle = null) {
    try {
      let feed = await Feed.findOne({ url: feedUrl });
      
      if (!feed) {
        const { feed: feedData, articles } = await feedParser.parseFeedFromUrl(feedUrl);
        const result = await feedParser.saveFeedAndArticles(feedData, articles);
        feed = result.feed;
      }

      const existingSubscription = await UserFeed.findOne({
        user: userId,
        feed: feed._id
      });

      if (existingSubscription) {
        if (!existingSubscription.isActive) {
          existingSubscription.isActive = true;
          existingSubscription.subscribedAt = new Date();
          await existingSubscription.save();
          return existingSubscription;
        }
        throw new Error('Already subscribed to this feed');
      }

      if (categoryId) {
        const category = await Category.findOne({
          _id: categoryId,
          user: userId
        });
        if (!category) {
          throw new Error('Category not found');
        }
      }

      const userFeed = new UserFeed({
        user: userId,
        feed: feed._id,
        category: categoryId,
        customTitle: customTitle
      });

      await userFeed.save();
      return await this.getUserFeedWithDetails(userFeed._id);
    } catch (error) {
      throw new Error(`Failed to subscribe to feed: ${error.message}`);
    }
  }

  async unsubscribeFrom(userId, feedId) {
    try {
      const userFeed = await UserFeed.findOne({
        user: userId,
        feed: feedId
      });

      if (!userFeed) {
        throw new Error('Subscription not found');
      }

      userFeed.isActive = false;
      await userFeed.save();

      return userFeed;
    } catch (error) {
      throw new Error(`Failed to unsubscribe from feed: ${error.message}`);
    }
  }

  async getUserFeeds(userId, categoryId = null) {
    try {
      const query = { user: userId, isActive: true };
      if (categoryId) {
        query.category = categoryId;
      }

      const userFeeds = await UserFeed.find(query)
        .populate('feed')
        .populate('category')
        .sort({ subscribedAt: -1 });

      return userFeeds;
    } catch (error) {
      throw new Error(`Failed to get user feeds: ${error.message}`);
    }
  }

  async getUserFeedWithDetails(userFeedId) {
    try {
      const userFeed = await UserFeed.findById(userFeedId)
        .populate('feed')
        .populate('category')
        .populate('user', 'username email');

      if (!userFeed) {
        throw new Error('User feed not found');
      }

      return userFeed;
    } catch (error) {
      throw new Error(`Failed to get user feed details: ${error.message}`);
    }
  }

  async updateUserFeed(userId, feedId, updates) {
    try {
      const userFeed = await UserFeed.findOne({
        user: userId,
        feed: feedId
      });

      if (!userFeed) {
        throw new Error('Subscription not found');
      }

      const allowedUpdates = ['customTitle', 'refreshFrequency', 'category'];
      const updateData = {};

      for (const key of allowedUpdates) {
        if (updates[key] !== undefined) {
          updateData[key] = updates[key];
        }
      }

      if (updateData.category) {
        const category = await Category.findOne({
          _id: updateData.category,
          user: userId
        });
        if (!category) {
          throw new Error('Category not found');
        }
      }

      Object.assign(userFeed, updateData);
      await userFeed.save();

      return await this.getUserFeedWithDetails(userFeed._id);
    } catch (error) {
      throw new Error(`Failed to update user feed: ${error.message}`);
    }
  }

  async getFeedArticles(userId, feedId, options = {}) {
    try {
      const {
        page = 1,
        limit = 50,
        unreadOnly = false,
        starredOnly = false,
        search = null
      } = options;

      const userFeed = await UserFeed.findOne({
        user: userId,
        feed: feedId,
        isActive: true
      });

      if (!userFeed) {
        throw new Error('Feed subscription not found');
      }

      const skip = (page - 1) * limit;
      const matchStage = { feed: feedId };

      if (search) {
        matchStage.$or = [
          { title: { $regex: search, $options: 'i' } },
          { description: { $regex: search, $options: 'i' } },
          { content: { $regex: search, $options: 'i' } }
        ];
      }

      const pipeline = [
        { $match: matchStage },
        {
          $lookup: {
            from: 'userarticles',
            let: { articleId: '$_id' },
            pipeline: [
              {
                $match: {
                  $expr: {
                    $and: [
                      { $eq: ['$article', '$$articleId'] },
                      { $eq: ['$user', userId] }
                    ]
                  }
                }
              }
            ],
            as: 'userArticle'
          }
        },
        {
          $addFields: {
            isRead: { $ifNull: [{ $arrayElemAt: ['$userArticle.isRead', 0] }, false] },
            isStarred: { $ifNull: [{ $arrayElemAt: ['$userArticle.isStarred', 0] }, false] },
            readAt: { $arrayElemAt: ['$userArticle.readAt', 0] },
            starredAt: { $arrayElemAt: ['$userArticle.starredAt', 0] }
          }
        }
      ];

      if (unreadOnly) {
        pipeline.push({ $match: { isRead: false } });
      }

      if (starredOnly) {
        pipeline.push({ $match: { isStarred: true } });
      }

      pipeline.push(
        { $sort: { pubDate: -1 } },
        { $skip: skip },
        { $limit: limit }
      );

      const articles = await Article.aggregate(pipeline);
      const totalCount = await Article.countDocuments(matchStage);

      return {
        articles,
        totalCount,
        currentPage: page,
        totalPages: Math.ceil(totalCount / limit),
        hasNextPage: skip + limit < totalCount,
        hasPrevPage: page > 1
      };
    } catch (error) {
      throw new Error(`Failed to get feed articles: ${error.message}`);
    }
  }

  async refreshFeed(feedId) {
    try {
      const feed = await Feed.findById(feedId);
      if (!feed) {
        throw new Error('Feed not found');
      }

      const { feed: feedData, articles } = await feedParser.parseFeedFromUrl(feed.url);
      const result = await feedParser.saveFeedAndArticles(feedData, articles, feedId);

      return result;
    } catch (error) {
      await feedParser.updateFeedError(feedId, error.message);
      throw new Error(`Failed to refresh feed: ${error.message}`);
    }
  }

  async createCategory(userId, name, parentId = null, color = '#007bff') {
    try {
      if (parentId) {
        const parent = await Category.findOne({
          _id: parentId,
          user: userId
        });
        if (!parent) {
          throw new Error('Parent category not found');
        }
      }

      const existingCategory = await Category.findOne({
        user: userId,
        name: name
      });

      if (existingCategory) {
        throw new Error('Category with this name already exists');
      }

      const category = new Category({
        name,
        user: userId,
        parent: parentId,
        color
      });

      await category.save();
      return category;
    } catch (error) {
      throw new Error(`Failed to create category: ${error.message}`);
    }
  }

  async getUserCategories(userId) {
    try {
      const categories = await Category.find({ user: userId })
        .populate('parent')
        .sort({ order: 1, name: 1 });

      return categories;
    } catch (error) {
      throw new Error(`Failed to get user categories: ${error.message}`);
    }
  }

  async updateCategory(userId, categoryId, updates) {
    try {
      const category = await Category.findOne({
        _id: categoryId,
        user: userId
      });

      if (!category) {
        throw new Error('Category not found');
      }

      const allowedUpdates = ['name', 'color', 'parent', 'order'];
      const updateData = {};

      for (const key of allowedUpdates) {
        if (updates[key] !== undefined) {
          updateData[key] = updates[key];
        }
      }

      if (updateData.parent) {
        const parent = await Category.findOne({
          _id: updateData.parent,
          user: userId
        });
        if (!parent) {
          throw new Error('Parent category not found');
        }
      }

      Object.assign(category, updateData);
      await category.save();

      return category;
    } catch (error) {
      throw new Error(`Failed to update category: ${error.message}`);
    }
  }

  async deleteCategory(userId, categoryId) {
    try {
      const category = await Category.findOne({
        _id: categoryId,
        user: userId
      });

      if (!category) {
        throw new Error('Category not found');
      }

      await UserFeed.updateMany(
        { category: categoryId },
        { $unset: { category: 1 } }
      );

      await Category.deleteOne({ _id: categoryId });

      return { message: 'Category deleted successfully' };
    } catch (error) {
      throw new Error(`Failed to delete category: ${error.message}`);
    }
  }
}

module.exports = new FeedManagerService();