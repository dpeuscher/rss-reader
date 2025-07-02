const { Article, UserArticle, UserFeed } = require('../models');
const mongoose = require('mongoose');

class ArticleProcessorService {
  async getAllArticles(userId, options = {}) {
    try {
      const {
        page = 1,
        limit = 50,
        unreadOnly = false,
        starredOnly = false,
        feedId = null,
        categoryId = null,
        search = null,
        sortBy = 'pubDate',
        sortOrder = 'desc'
      } = options;

      const skip = (page - 1) * limit;
      const sort = {};
      sort[sortBy] = sortOrder === 'desc' ? -1 : 1;

      let feedIds = [];
      if (feedId) {
        feedIds = [mongoose.Types.ObjectId(feedId)];
      } else {
        const query = { user: userId, isActive: true };
        if (categoryId) {
          query.category = categoryId;
        }
        const userFeeds = await UserFeed.find(query).select('feed');
        feedIds = userFeeds.map(uf => uf.feed);
      }

      if (feedIds.length === 0) {
        return {
          articles: [],
          totalCount: 0,
          currentPage: page,
          totalPages: 0,
          hasNextPage: false,
          hasPrevPage: false
        };
      }

      const matchStage = { feed: { $in: feedIds } };

      if (search) {
        matchStage.$or = [
          { title: { $regex: search, $options: 'i' } },
          { description: { $regex: search, $options: 'i' } },
          { content: { $regex: search, $options: 'i' } },
          { author: { $regex: search, $options: 'i' } }
        ];
      }

      const pipeline = [
        { $match: matchStage },
        {
          $lookup: {
            from: 'feeds',
            localField: 'feed',
            foreignField: '_id',
            as: 'feedInfo'
          }
        },
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
                      { $eq: ['$user', mongoose.Types.ObjectId(userId)] }
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
            starredAt: { $arrayElemAt: ['$userArticle.starredAt', 0] },
            feedTitle: { $arrayElemAt: ['$feedInfo.title', 0] },
            feedUrl: { $arrayElemAt: ['$feedInfo.url', 0] }
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
        { $sort: sort },
        { $skip: skip },
        { $limit: limit }
      );

      const articles = await Article.aggregate(pipeline);
      
      const countPipeline = [
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
                      { $eq: ['$user', mongoose.Types.ObjectId(userId)] }
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
            isStarred: { $ifNull: [{ $arrayElemAt: ['$userArticle.isStarred', 0] }, false] }
          }
        }
      ];

      if (unreadOnly) {
        countPipeline.push({ $match: { isRead: false } });
      }

      if (starredOnly) {
        countPipeline.push({ $match: { isStarred: true } });
      }

      countPipeline.push({ $count: 'total' });

      const countResult = await Article.aggregate(countPipeline);
      const totalCount = countResult.length > 0 ? countResult[0].total : 0;

      return {
        articles,
        totalCount,
        currentPage: page,
        totalPages: Math.ceil(totalCount / limit),
        hasNextPage: skip + limit < totalCount,
        hasPrevPage: page > 1
      };
    } catch (error) {
      throw new Error(`Failed to get articles: ${error.message}`);
    }
  }

  async getArticleById(userId, articleId) {
    try {
      const pipeline = [
        { $match: { _id: mongoose.Types.ObjectId(articleId) } },
        {
          $lookup: {
            from: 'feeds',
            localField: 'feed',
            foreignField: '_id',
            as: 'feedInfo'
          }
        },
        {
          $lookup: {
            from: 'userfeeds',
            let: { feedId: '$feed' },
            pipeline: [
              {
                $match: {
                  $expr: {
                    $and: [
                      { $eq: ['$feed', '$$feedId'] },
                      { $eq: ['$user', mongoose.Types.ObjectId(userId)] },
                      { $eq: ['$isActive', true] }
                    ]
                  }
                }
              }
            ],
            as: 'userFeed'
          }
        }
      ];

      const result = await Article.aggregate(pipeline);
      
      if (result.length === 0 || result[0].userFeed.length === 0) {
        throw new Error('Article not found or access denied');
      }

      const article = result[0];
      
      const userArticle = await UserArticle.findOne({
        user: userId,
        article: articleId
      });

      article.isRead = userArticle ? userArticle.isRead : false;
      article.isStarred = userArticle ? userArticle.isStarred : false;
      article.readAt = userArticle ? userArticle.readAt : null;
      article.starredAt = userArticle ? userArticle.starredAt : null;
      article.feedTitle = article.feedInfo[0] ? article.feedInfo[0].title : '';
      article.feedUrl = article.feedInfo[0] ? article.feedInfo[0].url : '';

      return article;
    } catch (error) {
      throw new Error(`Failed to get article: ${error.message}`);
    }
  }

  async markAsRead(userId, articleId, isRead = true) {
    try {
      const article = await this.getArticleById(userId, articleId);
      
      let userArticle = await UserArticle.findOne({
        user: userId,
        article: articleId
      });

      if (!userArticle) {
        userArticle = new UserArticle({
          user: userId,
          article: articleId,
          isRead
        });
      } else {
        userArticle.isRead = isRead;
      }

      await userArticle.save();
      return userArticle;
    } catch (error) {
      throw new Error(`Failed to mark article as ${isRead ? 'read' : 'unread'}: ${error.message}`);
    }
  }

  async markAsStarred(userId, articleId, isStarred = true) {
    try {
      const article = await this.getArticleById(userId, articleId);
      
      let userArticle = await UserArticle.findOne({
        user: userId,
        article: articleId
      });

      if (!userArticle) {
        userArticle = new UserArticle({
          user: userId,
          article: articleId,
          isStarred
        });
      } else {
        userArticle.isStarred = isStarred;
      }

      await userArticle.save();
      return userArticle;
    } catch (error) {
      throw new Error(`Failed to ${isStarred ? 'star' : 'unstar'} article: ${error.message}`);
    }
  }

  async markMultipleAsRead(userId, articleIds, isRead = true) {
    try {
      const results = [];
      
      for (const articleId of articleIds) {
        try {
          const result = await this.markAsRead(userId, articleId, isRead);
          results.push({ articleId, success: true, result });
        } catch (error) {
          results.push({ articleId, success: false, error: error.message });
        }
      }

      return results;
    } catch (error) {
      throw new Error(`Failed to mark multiple articles: ${error.message}`);
    }
  }

  async markAllAsRead(userId, feedId = null, categoryId = null) {
    try {
      let feedIds = [];
      
      if (feedId) {
        feedIds = [mongoose.Types.ObjectId(feedId)];
      } else {
        const query = { user: userId, isActive: true };
        if (categoryId) {
          query.category = categoryId;
        }
        const userFeeds = await UserFeed.find(query).select('feed');
        feedIds = userFeeds.map(uf => uf.feed);
      }

      if (feedIds.length === 0) {
        return { modified: 0 };
      }

      const articles = await Article.find({
        feed: { $in: feedIds }
      }).select('_id');

      const articleIds = articles.map(a => a._id);
      
      const bulkOps = articleIds.map(articleId => ({
        updateOne: {
          filter: { user: userId, article: articleId },
          update: { 
            $set: { isRead: true },
            $setOnInsert: { user: userId, article: articleId }
          },
          upsert: true
        }
      }));

      if (bulkOps.length === 0) {
        return { modified: 0 };
      }

      const result = await UserArticle.bulkWrite(bulkOps);
      
      return {
        modified: result.modifiedCount + result.upsertedCount
      };
    } catch (error) {
      throw new Error(`Failed to mark all articles as read: ${error.message}`);
    }
  }

  async getUnreadCount(userId, feedId = null) {
    try {
      let feedIds = [];
      
      if (feedId) {
        feedIds = [mongoose.Types.ObjectId(feedId)];
      } else {
        const userFeeds = await UserFeed.find({ 
          user: userId, 
          isActive: true 
        }).select('feed');
        feedIds = userFeeds.map(uf => uf.feed);
      }

      if (feedIds.length === 0) {
        return 0;
      }

      const pipeline = [
        { $match: { feed: { $in: feedIds } } },
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
                      { $eq: ['$user', mongoose.Types.ObjectId(userId)] }
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
            isRead: { $ifNull: [{ $arrayElemAt: ['$userArticle.isRead', 0] }, false] }
          }
        },
        { $match: { isRead: false } },
        { $count: 'unread' }
      ];

      const result = await Article.aggregate(pipeline);
      return result.length > 0 ? result[0].unread : 0;
    } catch (error) {
      throw new Error(`Failed to get unread count: ${error.message}`);
    }
  }

  async searchArticles(userId, searchTerm, options = {}) {
    try {
      return await this.getAllArticles(userId, {
        ...options,
        search: searchTerm
      });
    } catch (error) {
      throw new Error(`Failed to search articles: ${error.message}`);
    }
  }

  async getArticleStats(userId) {
    try {
      const userFeeds = await UserFeed.find({ 
        user: userId, 
        isActive: true 
      }).select('feed');
      
      const feedIds = userFeeds.map(uf => uf.feed);

      if (feedIds.length === 0) {
        return {
          totalArticles: 0,
          unreadArticles: 0,
          starredArticles: 0,
          readArticles: 0
        };
      }

      const pipeline = [
        { $match: { feed: { $in: feedIds } } },
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
                      { $eq: ['$user', mongoose.Types.ObjectId(userId)] }
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
            isStarred: { $ifNull: [{ $arrayElemAt: ['$userArticle.isStarred', 0] }, false] }
          }
        },
        {
          $group: {
            _id: null,
            totalArticles: { $sum: 1 },
            unreadArticles: { $sum: { $cond: [{ $eq: ['$isRead', false] }, 1, 0] } },
            starredArticles: { $sum: { $cond: [{ $eq: ['$isStarred', true] }, 1, 0] } },
            readArticles: { $sum: { $cond: [{ $eq: ['$isRead', true] }, 1, 0] } }
          }
        }
      ];

      const result = await Article.aggregate(pipeline);
      
      return result.length > 0 ? result[0] : {
        totalArticles: 0,
        unreadArticles: 0,
        starredArticles: 0,
        readArticles: 0
      };
    } catch (error) {
      throw new Error(`Failed to get article stats: ${error.message}`);
    }
  }
}

module.exports = new ArticleProcessorService();