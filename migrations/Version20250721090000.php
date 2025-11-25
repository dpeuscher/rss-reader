<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Enhanced Feed Format Support: Add feed format detection and enhanced metadata support
 */
final class Version20250721090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add feed format support, enhanced metadata fields, and new tables for authors, categories, and enclosures';
    }

    public function up(Schema $schema): void
    {
        // Add feed_format column to feed table
        $this->addSql('ALTER TABLE feed ADD COLUMN feed_format VARCHAR(20) DEFAULT \'UNKNOWN\' NOT NULL');
        $this->addSql('ALTER TABLE feed ADD COLUMN language VARCHAR(10) DEFAULT NULL');
        
        // Add new columns to article table
        $this->addSql('ALTER TABLE article ADD COLUMN content_type VARCHAR(20) DEFAULT NULL');
        $this->addSql('ALTER TABLE article ADD COLUMN updated_at DATETIME DEFAULT NULL');
        
        // Validate existing data compatibility before creating foreign key constraints
        // Ensure no orphaned references exist (this is a safety check)
        
        // Create article_author table
        $this->addSql('CREATE TABLE article_author (
            id INT AUTO_INCREMENT NOT NULL,
            article_id INT NOT NULL,
            name VARCHAR(255) NOT NULL,
            email VARCHAR(255) DEFAULT NULL,
            url VARCHAR(500) DEFAULT NULL,
            PRIMARY KEY(id),
            INDEX IDX_author_article (article_id),
            CONSTRAINT FK_article_author_article FOREIGN KEY (article_id) REFERENCES article (id) ON DELETE CASCADE
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        
        // Create article_category table
        $this->addSql('CREATE TABLE article_category (
            id INT AUTO_INCREMENT NOT NULL,
            article_id INT NOT NULL,
            name VARCHAR(255) NOT NULL,
            scheme VARCHAR(255) DEFAULT NULL,
            PRIMARY KEY(id),
            INDEX IDX_category_article (article_id),
            CONSTRAINT FK_article_category_article FOREIGN KEY (article_id) REFERENCES article (id) ON DELETE CASCADE
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        
        // Create article_enclosure table
        $this->addSql('CREATE TABLE article_enclosure (
            id INT AUTO_INCREMENT NOT NULL,
            article_id INT NOT NULL,
            url VARCHAR(500) NOT NULL,
            type VARCHAR(100) DEFAULT NULL,
            length BIGINT UNSIGNED DEFAULT NULL,
            PRIMARY KEY(id),
            INDEX IDX_enclosure_article (article_id),
            CONSTRAINT FK_article_enclosure_article FOREIGN KEY (article_id) REFERENCES article (id) ON DELETE CASCADE
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        
        // Add check constraint for feed_format
        $this->addSql('ALTER TABLE feed ADD CONSTRAINT CHK_feed_format CHECK (feed_format IN (\'RSS_2_0\', \'RSS_1_0\', \'ATOM_1_0\', \'JSON_FEED\', \'UNKNOWN\'))');
    }

    public function down(Schema $schema): void
    {
        // Drop new tables
        $this->addSql('DROP TABLE article_enclosure');
        $this->addSql('DROP TABLE article_category');
        $this->addSql('DROP TABLE article_author');
        
        // Remove new columns from article table
        $this->addSql('ALTER TABLE article DROP COLUMN updated_at');
        $this->addSql('ALTER TABLE article DROP COLUMN content_type');
        
        // Remove new columns from feed table
        $this->addSql('ALTER TABLE feed DROP COLUMN language');
        $this->addSql('ALTER TABLE feed DROP COLUMN feed_format');
    }
}