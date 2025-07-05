class RSSReaderApp {
    constructor() {
        this.token = localStorage.getItem('token');
        this.currentUser = null;
        this.currentFeed = null;
        this.articles = [];
        this.feeds = [];
        
        this.initializeEventListeners();
        this.checkAuthStatus();
    }

    initializeEventListeners() {
        document.getElementById('login-btn').addEventListener('click', () => this.showLogin());
        document.getElementById('register-btn').addEventListener('click', () => this.showRegister());
        document.getElementById('show-register').addEventListener('click', () => this.showRegister());
        document.getElementById('show-login').addEventListener('click', () => this.showLogin());
        document.getElementById('logout-btn').addEventListener('click', () => this.logout());
        
        document.getElementById('login').addEventListener('submit', (e) => this.handleLogin(e));
        document.getElementById('register').addEventListener('submit', (e) => this.handleRegister(e));
        
        document.getElementById('add-feed-btn').addEventListener('click', () => this.showAddFeedModal());
        document.getElementById('add-feed-form').addEventListener('submit', (e) => this.handleAddFeed(e));
        
        document.getElementById('mark-all-read-btn').addEventListener('click', () => this.markAllAsRead());
        document.getElementById('refresh-btn').addEventListener('click', () => this.refreshFeeds());
    }

    async checkAuthStatus() {
        if (this.token) {
            try {
                const response = await this.fetchWithAuth('/api/auth/profile');
                if (response.ok) {
                    const data = await response.json();
                    this.currentUser = data.user;
                    this.showMainApp();
                    await this.loadFeeds();
                    await this.loadArticles();
                    await this.loadStats();
                } else {
                    this.logout();
                }
            } catch (error) {
                console.error('Auth check failed:', error);
                this.logout();
            }
        } else {
            this.showLogin();
        }
    }

    async fetchWithAuth(url, options = {}) {
        const headers = {
            'Content-Type': 'application/json',
            ...options.headers
        };
        
        if (this.token) {
            headers['Authorization'] = `Bearer ${this.token}`;
        }
        
        return fetch(url, {
            ...options,
            headers
        });
    }

    showLogin() {
        document.getElementById('login-form').classList.remove('d-none');
        document.getElementById('register-form').classList.add('d-none');
        document.getElementById('main-app').classList.add('d-none');
    }

    showRegister() {
        document.getElementById('register-form').classList.remove('d-none');
        document.getElementById('login-form').classList.add('d-none');
        document.getElementById('main-app').classList.add('d-none');
    }

    showMainApp() {
        document.getElementById('main-app').classList.remove('d-none');
        document.getElementById('login-form').classList.add('d-none');
        document.getElementById('register-form').classList.add('d-none');
        document.getElementById('auth-section').classList.remove('d-none');
        document.getElementById('guest-section').classList.add('d-none');
        
        if (this.currentUser) {
            document.getElementById('user-name').textContent = this.currentUser.username;
        }
    }

    async handleLogin(e) {
        e.preventDefault();
        
        const email = document.getElementById('login-email').value;
        const password = document.getElementById('login-password').value;
        
        try {
            const response = await fetch('/api/auth/login', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({ email, password })
            });
            
            const data = await response.json();
            
            if (response.ok) {
                this.token = data.token;
                this.currentUser = data.user;
                localStorage.setItem('token', this.token);
                this.showMainApp();
                await this.loadFeeds();
                await this.loadArticles();
                await this.loadStats();
            } else {
                this.showError(data.message || 'Login failed');
            }
        } catch (error) {
            console.error('Login error:', error);
            this.showError('Network error during login');
        }
    }

    async handleRegister(e) {
        e.preventDefault();
        
        const username = document.getElementById('register-username').value;
        const email = document.getElementById('register-email').value;
        const password = document.getElementById('register-password').value;
        
        try {
            const response = await fetch('/api/auth/register', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({ username, email, password })
            });
            
            const data = await response.json();
            
            if (response.ok) {
                this.token = data.token;
                this.currentUser = data.user;
                localStorage.setItem('token', this.token);
                this.showMainApp();
                await this.loadFeeds();
                await this.loadArticles();
                await this.loadStats();
            } else {
                this.showError(data.message || 'Registration failed');
            }
        } catch (error) {
            console.error('Registration error:', error);
            this.showError('Network error during registration');
        }
    }

    logout() {
        this.token = null;
        this.currentUser = null;
        localStorage.removeItem('token');
        document.getElementById('auth-section').classList.add('d-none');
        document.getElementById('guest-section').classList.remove('d-none');
        this.showLogin();
    }

    async loadFeeds() {
        try {
            const response = await this.fetchWithAuth('/api/feeds');
            const data = await response.json();
            
            if (response.ok) {
                this.feeds = data.feeds;
                this.renderFeeds();
            } else {
                this.showError('Failed to load feeds');
            }
        } catch (error) {
            console.error('Error loading feeds:', error);
            this.showError('Network error loading feeds');
        }
    }

    renderFeeds() {
        const feedsList = document.getElementById('feeds-list');
        
        if (this.feeds.length === 0) {
            feedsList.innerHTML = '<div class="list-group-item text-center text-muted">No feeds yet. Add your first feed!</div>';
            return;
        }
        
        feedsList.innerHTML = this.feeds.map(userFeed => `
            <div class="list-group-item feed-item ${this.currentFeed === userFeed.feed._id ? 'active' : ''}" 
                 data-feed-id="${userFeed.feed._id}">
                <div class="feed-title">${userFeed.customTitle || userFeed.feed.title}</div>
                <div class="feed-url">${userFeed.feed.url}</div>
            </div>
        `).join('');
        
        feedsList.querySelectorAll('.feed-item').forEach(item => {
            item.addEventListener('click', () => {
                const feedId = item.dataset.feedId;
                this.selectFeed(feedId);
            });
        });
    }

    async selectFeed(feedId) {
        this.currentFeed = feedId;
        this.renderFeeds();
        await this.loadArticles();
    }

    async loadArticles() {
        try {
            const url = this.currentFeed 
                ? `/api/feeds/${this.currentFeed}/articles` 
                : '/api/articles';
            
            const response = await this.fetchWithAuth(url);
            const data = await response.json();
            
            if (response.ok) {
                this.articles = data.articles;
                this.renderArticles();
            } else {
                this.showError('Failed to load articles');
            }
        } catch (error) {
            console.error('Error loading articles:', error);
            this.showError('Network error loading articles');
        }
    }

    renderArticles() {
        const articlesList = document.getElementById('articles-list');
        
        if (this.articles.length === 0) {
            articlesList.innerHTML = '<div class="text-center p-4 text-muted">No articles found</div>';
            return;
        }
        
        articlesList.innerHTML = this.articles.map(article => `
            <div class="article-item ${!article.isRead ? 'unread' : ''} ${article.isStarred ? 'starred' : ''}" 
                 data-article-id="${article._id}">
                <div class="article-title">${article.title}</div>
                <div class="article-meta">
                    <span>${article.feedTitle || 'Unknown Feed'}</span> • 
                    <span>${new Date(article.pubDate).toLocaleDateString()}</span>
                    ${article.author ? ` • ${article.author}` : ''}
                </div>
                <div class="article-description">${article.description}</div>
                <div class="article-actions">
                    <button class="btn btn-sm btn-outline-primary btn-read" onclick="app.toggleRead('${article._id}', ${!article.isRead})">
                        ${article.isRead ? 'Mark Unread' : 'Mark Read'}
                    </button>
                    <button class="btn btn-sm btn-outline-warning btn-star" onclick="app.toggleStar('${article._id}', ${!article.isStarred})">
                        ${article.isStarred ? 'Unstar' : 'Star'}
                    </button>
                </div>
            </div>
        `).join('');
        
        articlesList.querySelectorAll('.article-item').forEach(item => {
            item.addEventListener('click', (e) => {
                if (!e.target.classList.contains('btn')) {
                    const articleId = item.dataset.articleId;
                    this.showArticle(articleId);
                }
            });
        });
    }

    async showArticle(articleId) {
        try {
            const response = await this.fetchWithAuth(`/api/articles/${articleId}`);
            const data = await response.json();
            
            if (response.ok) {
                const article = data.article;
                
                document.getElementById('article-title').textContent = article.title;
                document.getElementById('article-meta').innerHTML = `
                    <small class="text-muted">
                        ${article.feedTitle} • ${new Date(article.pubDate).toLocaleDateString()} 
                        ${article.author ? ` • ${article.author}` : ''}
                    </small>
                `;
                document.getElementById('article-content').innerHTML = article.content || article.description;
                document.getElementById('article-link').href = article.link;
                
                const starBtn = document.getElementById('article-star-btn');
                const readBtn = document.getElementById('article-read-btn');
                
                starBtn.textContent = article.isStarred ? 'Unstar' : 'Star';
                starBtn.onclick = () => this.toggleStar(articleId, !article.isStarred);
                
                readBtn.textContent = article.isRead ? 'Mark Unread' : 'Mark Read';
                readBtn.onclick = () => this.toggleRead(articleId, !article.isRead);
                
                const modal = new bootstrap.Modal(document.getElementById('article-modal'));
                modal.show();
                
                if (!article.isRead) {
                    await this.toggleRead(articleId, true);
                }
            } else {
                this.showError('Failed to load article');
            }
        } catch (error) {
            console.error('Error loading article:', error);
            this.showError('Network error loading article');
        }
    }

    async toggleRead(articleId, isRead) {
        try {
            const response = await this.fetchWithAuth(`/api/articles/${articleId}/read`, {
                method: 'POST',
                body: JSON.stringify({ isRead })
            });
            
            if (response.ok) {
                await this.loadArticles();
                await this.loadStats();
            } else {
                this.showError('Failed to update read status');
            }
        } catch (error) {
            console.error('Error updating read status:', error);
            this.showError('Network error updating read status');
        }
    }

    async toggleStar(articleId, isStarred) {
        try {
            const response = await this.fetchWithAuth(`/api/articles/${articleId}/star`, {
                method: 'POST',
                body: JSON.stringify({ isStarred })
            });
            
            if (response.ok) {
                await this.loadArticles();
                await this.loadStats();
            } else {
                this.showError('Failed to update star status');
            }
        } catch (error) {
            console.error('Error updating star status:', error);
            this.showError('Network error updating star status');
        }
    }

    async markAllAsRead() {
        try {
            const body = this.currentFeed ? { feedId: this.currentFeed } : {};
            const response = await this.fetchWithAuth('/api/articles/mark-all-read', {
                method: 'POST',
                body: JSON.stringify(body)
            });
            
            if (response.ok) {
                await this.loadArticles();
                await this.loadStats();
                this.showSuccess('All articles marked as read');
            } else {
                this.showError('Failed to mark all as read');
            }
        } catch (error) {
            console.error('Error marking all as read:', error);
            this.showError('Network error marking all as read');
        }
    }

    async refreshFeeds() {
        try {
            await this.loadFeeds();
            await this.loadArticles();
            await this.loadStats();
            this.showSuccess('Feeds refreshed');
        } catch (error) {
            console.error('Error refreshing feeds:', error);
            this.showError('Network error refreshing feeds');
        }
    }

    showAddFeedModal() {
        const modal = new bootstrap.Modal(document.getElementById('add-feed-modal'));
        modal.show();
    }

    async handleAddFeed(e) {
        e.preventDefault();
        
        const url = document.getElementById('feed-url').value;
        const customTitle = document.getElementById('feed-title').value;
        
        try {
            const response = await this.fetchWithAuth('/api/feeds/subscribe', {
                method: 'POST',
                body: JSON.stringify({ url, customTitle })
            });
            
            const data = await response.json();
            
            if (response.ok) {
                const modal = bootstrap.Modal.getInstance(document.getElementById('add-feed-modal'));
                modal.hide();
                document.getElementById('add-feed-form').reset();
                await this.loadFeeds();
                await this.loadArticles();
                await this.loadStats();
                this.showSuccess('Feed added successfully');
            } else {
                this.showError(data.message || 'Failed to add feed');
            }
        } catch (error) {
            console.error('Error adding feed:', error);
            this.showError('Network error adding feed');
        }
    }

    async loadStats() {
        try {
            const response = await this.fetchWithAuth('/api/articles/stats');
            const data = await response.json();
            
            if (response.ok) {
                document.getElementById('unread-count').textContent = data.stats.unreadArticles;
                document.getElementById('total-count').textContent = data.stats.totalArticles;
            }
        } catch (error) {
            console.error('Error loading stats:', error);
        }
    }

    showError(message) {
        console.error(message);
        // You could implement a toast notification system here
        alert(`Error: ${message}`);
    }

    showSuccess(message) {
        console.log(message);
        // You could implement a toast notification system here
        // For now, we'll just log it
    }
}

const app = new RSSReaderApp();