module.exports = {
  testEnvironment: 'node',
  testMatch: ['**/tests/**/*noDb*.test.js', '**/tests/**/simple.test.js'],
  collectCoverageFrom: [
    'src/**/*.js',
    '!src/index.js',
    '!**/node_modules/**'
  ],
  coverageDirectory: 'coverage',
  coverageReporters: ['text', 'lcov', 'html'],
  testTimeout: 10000
};