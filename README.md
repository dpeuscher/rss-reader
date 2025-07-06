# RSS Reader

A Symfony 7.1 RSS Reader application that allows users to manage RSS feeds and articles.

## Requirements

- PHP 8.2 or higher
- Composer
- MySQL/PostgreSQL database

## Getting Started

This repository contains a Symfony 7.1 RSS Reader application.

### Installation

1. Clone the repository
2. Navigate to the `rss-reader` directory
3. Install dependencies:
   ```bash
   composer install
   ```
4. Configure your database in `.env.local`
5. Run database migrations:
   ```bash
   php bin/console doctrine:migrations:migrate
   ```
6. Clear cache:
   ```bash
   php bin/console cache:clear
   ```

### Post-Deployment Steps

After upgrading or deploying:

1. **Clear cache**: `php bin/console cache:clear`
2. **Update database**: `php bin/console doctrine:migrations:migrate`
3. **Install/update assets**: `php bin/console assets:install`

## License

This project is licensed under the MIT License.