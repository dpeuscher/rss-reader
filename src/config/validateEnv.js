const requiredEnvVars = [
  'MONGODB_URI',
  'JWT_SECRET'
];

const optionalEnvVars = {
  PORT: '3000',
  NODE_ENV: 'development'
};

function validateEnvironmentVariables() {
  const missing = [];
  const warnings = [];

  // Check required environment variables
  for (const envVar of requiredEnvVars) {
    if (!process.env[envVar]) {
      missing.push(envVar);
    }
  }

  // Set defaults for optional environment variables
  for (const [envVar, defaultValue] of Object.entries(optionalEnvVars)) {
    if (!process.env[envVar]) {
      process.env[envVar] = defaultValue;
      warnings.push(`${envVar} not set, using default: ${defaultValue}`);
    }
  }

  // Validate JWT_SECRET strength (if provided)
  if (process.env.JWT_SECRET && process.env.JWT_SECRET.length < 32) {
    warnings.push('JWT_SECRET should be at least 32 characters long for security');
  }

  // Validate PORT is a number
  if (process.env.PORT && isNaN(parseInt(process.env.PORT))) {
    missing.push('PORT must be a valid number');
  }

  // Validate NODE_ENV
  const validNodeEnvs = ['development', 'production', 'test'];
  if (process.env.NODE_ENV && !validNodeEnvs.includes(process.env.NODE_ENV)) {
    warnings.push(`NODE_ENV should be one of: ${validNodeEnvs.join(', ')}`);
  }

  // Log warnings
  if (warnings.length > 0) {
    console.warn('Environment Variable Warnings:');
    warnings.forEach(warning => console.warn(`  - ${warning}`));
  }

  // Throw error if required variables are missing
  if (missing.length > 0) {
    throw new Error(`Missing required environment variables: ${missing.join(', ')}`);
  }

  console.log('Environment variables validated successfully');
}

module.exports = { validateEnvironmentVariables };