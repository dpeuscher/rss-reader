const User = require('../../../src/models/User');
const { testUsers } = require('../../fixtures/testData');

describe('User Model', () => {
  describe('User Creation', () => {
    it('should create a valid user', async () => {
      const userData = { ...testUsers.validUser };
      const user = new User(userData);
      
      expect(user.username).toBe(userData.username);
      expect(user.email).toBe(userData.email);
      expect(user.preferences.articlesPerPage).toBe(50);
      expect(user.preferences.defaultView).toBe('list');
      expect(user.preferences.autoMarkAsRead).toBe(true);
    });

    it('should hash password before saving', async () => {
      const userData = { ...testUsers.validUser };
      const user = new User(userData);
      await user.save();
      
      expect(user.password).not.toBe(userData.password);
      expect(user.password.length).toBeGreaterThan(50);
    });

    it('should not hash password if not modified', async () => {
      const userData = { ...testUsers.validUser };
      const user = new User(userData);
      await user.save();
      
      const originalPassword = user.password;
      user.username = 'newusername';
      await user.save();
      
      expect(user.password).toBe(originalPassword);
    });

    it('should require username', async () => {
      const userData = { ...testUsers.validUser };
      delete userData.username;
      const user = new User(userData);
      
      await expect(user.save()).rejects.toThrow();
    });

    it('should require email', async () => {
      const userData = { ...testUsers.validUser };
      delete userData.email;
      const user = new User(userData);
      
      await expect(user.save()).rejects.toThrow();
    });

    it('should require password', async () => {
      const userData = { ...testUsers.validUser };
      delete userData.password;
      const user = new User(userData);
      
      await expect(user.save()).rejects.toThrow();
    });

    it('should enforce unique username', async () => {
      const userData1 = { ...testUsers.validUser };
      const userData2 = { ...testUsers.validUser, email: 'different@example.com' };
      
      const user1 = new User(userData1);
      await user1.save();
      
      const user2 = new User(userData2);
      await expect(user2.save()).rejects.toThrow();
    });

    it('should enforce unique email', async () => {
      const userData1 = { ...testUsers.validUser };
      const userData2 = { ...testUsers.validUser, username: 'differentuser' };
      
      const user1 = new User(userData1);
      await user1.save();
      
      const user2 = new User(userData2);
      await expect(user2.save()).rejects.toThrow();
    });

    it('should enforce minimum username length', async () => {
      const userData = { ...testUsers.validUser, username: 'ab' };
      const user = new User(userData);
      
      await expect(user.save()).rejects.toThrow();
    });

    it('should enforce minimum password length', async () => {
      const userData = { ...testUsers.validUser, password: '123' };
      const user = new User(userData);
      
      await expect(user.save()).rejects.toThrow();
    });

    it('should convert email to lowercase', async () => {
      const userData = { ...testUsers.validUser, email: 'TEST@EXAMPLE.COM' };
      const user = new User(userData);
      await user.save();
      
      expect(user.email).toBe('test@example.com');
    });
  });

  describe('User Methods', () => {
    let user;

    beforeEach(async () => {
      const userData = { ...testUsers.validUser };
      user = new User(userData);
      await user.save();
    });

    it('should compare password correctly', async () => {
      const isMatch = await user.comparePassword('password123');
      expect(isMatch).toBe(true);
    });

    it('should reject incorrect password', async () => {
      const isMatch = await user.comparePassword('wrongpassword');
      expect(isMatch).toBe(false);
    });

    it('should exclude password from JSON representation', () => {
      const userJSON = user.toJSON();
      expect(userJSON.password).toBeUndefined();
      expect(userJSON.username).toBe(user.username);
      expect(userJSON.email).toBe(user.email);
    });
  });

  describe('User Preferences', () => {
    it('should set default preferences', () => {
      const user = new User(testUsers.validUser);
      
      expect(user.preferences.articlesPerPage).toBe(50);
      expect(user.preferences.defaultView).toBe('list');
      expect(user.preferences.autoMarkAsRead).toBe(true);
    });

    it('should accept custom preferences', async () => {
      const userData = {
        ...testUsers.validUser,
        preferences: {
          articlesPerPage: 25,
          defaultView: 'expanded',
          autoMarkAsRead: false
        }
      };
      
      const user = new User(userData);
      await user.save();
      
      expect(user.preferences.articlesPerPage).toBe(25);
      expect(user.preferences.defaultView).toBe('expanded');
      expect(user.preferences.autoMarkAsRead).toBe(false);
    });

    it('should validate defaultView enum', async () => {
      const userData = {
        ...testUsers.validUser,
        preferences: {
          defaultView: 'invalid'
        }
      };
      
      const user = new User(userData);
      await expect(user.save()).rejects.toThrow();
    });
  });
});