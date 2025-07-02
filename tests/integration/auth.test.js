const request = require('supertest');
const jwt = require('jsonwebtoken');
const app = require('../testApp');
const { User } = require('../../src/models');
const { testUsers } = require('../fixtures/testData');

describe('Authentication API', () => {
  beforeEach(async () => {
    await User.deleteMany({});
  });

  describe('POST /api/auth/register', () => {
    it('should register a new user successfully', async () => {
      const userData = { ...testUsers.validUser };

      const response = await request(app)
        .post('/api/auth/register')
        .send(userData)
        .expect(201);

      expect(response.body.message).toBe('User registered successfully');
      expect(response.body.token).toBeDefined();
      expect(response.body.user.username).toBe(userData.username);
      expect(response.body.user.email).toBe(userData.email);
      expect(response.body.user.password).toBeUndefined();

      // Verify user was saved to database
      const savedUser = await User.findOne({ email: userData.email });
      expect(savedUser).toBeDefined();
      expect(savedUser.password).not.toBe(userData.password); // Should be hashed
    });

    it('should return 400 for missing required fields', async () => {
      const response = await request(app)
        .post('/api/auth/register')
        .send({})
        .expect(400);

      expect(response.body.errors).toBeDefined();
      expect(response.body.errors.length).toBeGreaterThan(0);
    });

    it('should return 400 for invalid email format', async () => {
      const userData = {
        ...testUsers.validUser,
        email: 'invalid-email'
      };

      const response = await request(app)
        .post('/api/auth/register')
        .send(userData)
        .expect(400);

      expect(response.body.errors).toBeDefined();
    });

    it('should return 400 for short password', async () => {
      const userData = {
        ...testUsers.validUser,
        password: '123'
      };

      const response = await request(app)
        .post('/api/auth/register')
        .send(userData)
        .expect(400);

      expect(response.body.errors).toBeDefined();
    });

    it('should return 400 for duplicate email', async () => {
      const user = new User(testUsers.validUser);
      await user.save();

      const userData = {
        username: 'different',
        email: testUsers.validUser.email,
        password: 'password123'
      };

      const response = await request(app)
        .post('/api/auth/register')
        .send(userData)
        .expect(400);

      expect(response.body.message).toContain('already exists');
    });

    it('should return 400 for duplicate username', async () => {
      const user = new User(testUsers.validUser);
      await user.save();

      const userData = {
        username: testUsers.validUser.username,
        email: 'different@example.com',
        password: 'password123'
      };

      const response = await request(app)
        .post('/api/auth/register')
        .send(userData)
        .expect(400);

      expect(response.body.message).toContain('already exists');
    });
  });

  describe('POST /api/auth/login', () => {
    let user;

    beforeEach(async () => {
      user = new User(testUsers.validUser);
      await user.save();
    });

    it('should login successfully with valid credentials', async () => {
      const loginData = {
        email: testUsers.validUser.email,
        password: testUsers.validUser.password
      };

      const response = await request(app)
        .post('/api/auth/login')
        .send(loginData)
        .expect(200);

      expect(response.body.message).toBe('Login successful');
      expect(response.body.token).toBeDefined();
      expect(response.body.user.email).toBe(loginData.email);
      expect(response.body.user.password).toBeUndefined();

      // Verify token is valid JWT
      const decoded = jwt.verify(response.body.token, process.env.JWT_SECRET || 'fallback-secret');
      expect(decoded.id).toBe(user._id.toString());

      // Verify lastLoginAt was updated
      const updatedUser = await User.findById(user._id);
      expect(updatedUser.lastLoginAt).toBeDefined();
    });

    it('should return 400 for missing fields', async () => {
      const response = await request(app)
        .post('/api/auth/login')
        .send({})
        .expect(400);

      expect(response.body.errors).toBeDefined();
    });

    it('should return 400 for non-existent email', async () => {
      const loginData = {
        email: 'nonexistent@example.com',
        password: 'password123'
      };

      const response = await request(app)
        .post('/api/auth/login')
        .send(loginData)
        .expect(400);

      expect(response.body.message).toBe('Invalid credentials');
    });

    it('should return 400 for incorrect password', async () => {
      const loginData = {
        email: testUsers.validUser.email,
        password: 'wrongpassword'
      };

      const response = await request(app)
        .post('/api/auth/login')
        .send(loginData)
        .expect(400);

      expect(response.body.message).toBe('Invalid credentials');
    });
  });

  describe('GET /api/auth/profile', () => {
    let user, token;

    beforeEach(async () => {
      user = new User(testUsers.validUser);
      await user.save();
      token = jwt.sign({ id: user._id }, process.env.JWT_SECRET || 'fallback-secret');
    });

    it('should return user profile with valid token', async () => {
      const response = await request(app)
        .get('/api/auth/profile')
        .set('Authorization', `Bearer ${token}`)
        .expect(200);

      expect(response.body.user.username).toBe(user.username);
      expect(response.body.user.email).toBe(user.email);
      expect(response.body.user.password).toBeUndefined();
    });

    it('should return 401 without token', async () => {
      await request(app)
        .get('/api/auth/profile')
        .expect(401);
    });

    it('should return 401 with invalid token', async () => {
      await request(app)
        .get('/api/auth/profile')
        .set('Authorization', 'Bearer invalid-token')
        .expect(401);
    });

    it('should return 401 with expired token', async () => {
      const expiredToken = jwt.sign(
        { id: user._id },
        process.env.JWT_SECRET || 'fallback-secret',
        { expiresIn: '0s' }
      );

      await request(app)
        .get('/api/auth/profile')
        .set('Authorization', `Bearer ${expiredToken}`)
        .expect(401);
    });
  });

  describe('PUT /api/auth/profile', () => {
    let user, token;

    beforeEach(async () => {
      user = new User(testUsers.validUser);
      await user.save();
      token = jwt.sign({ id: user._id }, process.env.JWT_SECRET || 'fallback-secret');
    });

    it('should update user preferences', async () => {
      const updateData = {
        preferences: {
          articlesPerPage: 25,
          defaultView: 'expanded',
          autoMarkAsRead: false
        }
      };

      const response = await request(app)
        .put('/api/auth/profile')
        .set('Authorization', `Bearer ${token}`)
        .send(updateData)
        .expect(200);

      expect(response.body.message).toBe('Profile updated successfully');
      expect(response.body.user.preferences.articlesPerPage).toBe(25);
      expect(response.body.user.preferences.defaultView).toBe('expanded');
      expect(response.body.user.preferences.autoMarkAsRead).toBe(false);

      // Verify in database
      const updatedUser = await User.findById(user._id);
      expect(updatedUser.preferences.articlesPerPage).toBe(25);
    });

    it('should partially update preferences', async () => {
      const updateData = {
        preferences: {
          articlesPerPage: 100
        }
      };

      const response = await request(app)
        .put('/api/auth/profile')
        .set('Authorization', `Bearer ${token}`)
        .send(updateData)
        .expect(200);

      expect(response.body.user.preferences.articlesPerPage).toBe(100);
      expect(response.body.user.preferences.defaultView).toBe('list'); // Should keep default
    });

    it('should require authentication', async () => {
      const updateData = {
        preferences: { articlesPerPage: 25 }
      };

      await request(app)
        .put('/api/auth/profile')
        .send(updateData)
        .expect(401);
    });
  });
});