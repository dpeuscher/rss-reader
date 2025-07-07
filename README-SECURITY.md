# Security Configuration

## Database Credentials

This project uses environment variables for database configuration. To set up:

1. Copy `.env.example` to `.env`: `cp .env.example .env`
2. Update the database credentials in `.env` with secure values
3. Never commit the `.env` file to version control

The `.env` file is automatically ignored by Git to prevent credential exposure.