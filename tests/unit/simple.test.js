// Simple test to validate Jest setup
describe('Basic Test Suite', () => {
  it('should run basic tests', () => {
    expect(1 + 1).toBe(2);
  });

  it('should handle async operations', async () => {
    const promise = new Promise(resolve => {
      setTimeout(() => resolve('test'), 10);
    });
    
    const result = await promise;
    expect(result).toBe('test');
  });

  it('should validate environment', () => {
    expect(process.env.NODE_ENV).toBeDefined();
  });
});