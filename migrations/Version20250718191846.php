<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20250718191846 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add AI summarization tables and columns';
    }

    public function up(Schema $schema): void
    {
        // Create ai_article_summaries table
        $this->addSql('CREATE TABLE ai_article_summaries (
            id SERIAL PRIMARY KEY,
            article_id INTEGER NOT NULL,
            summary_text TEXT NOT NULL,
            topics JSONB,
            processing_time INTEGER,
            ai_provider VARCHAR(50) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            CONSTRAINT fk_ai_summaries_article FOREIGN KEY (article_id) REFERENCES article(id) ON DELETE CASCADE
        )');

        // Create user_ai_preferences table
        $this->addSql('CREATE TABLE user_ai_preferences (
            id SERIAL PRIMARY KEY,
            user_id INTEGER NOT NULL,
            ai_processing_enabled BOOLEAN DEFAULT false,
            consent_given_at TIMESTAMP,
            preferred_summary_length VARCHAR(20) DEFAULT \'medium\',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            CONSTRAINT fk_user_ai_prefs_user FOREIGN KEY (user_id) REFERENCES "user"(id) ON DELETE CASCADE,
            CONSTRAINT uk_user_ai_prefs_user UNIQUE (user_id)
        )');

        // Add AI-related columns to articles table
        $this->addSql('ALTER TABLE article ADD COLUMN ai_processing_status VARCHAR(20) DEFAULT \'pending\'');
        $this->addSql('ALTER TABLE article ADD COLUMN estimated_reading_time INTEGER');

        // Create performance optimization indexes
        $this->addSql('CREATE INDEX idx_ai_summaries_article_created ON ai_article_summaries(article_id, created_at)');
        $this->addSql('CREATE INDEX idx_user_ai_prefs_user_enabled ON user_ai_preferences(user_id, ai_processing_enabled)');
        $this->addSql('CREATE INDEX idx_articles_ai_status ON article(ai_processing_status)');
    }

    public function down(Schema $schema): void
    {
        // Drop indexes
        $this->addSql('DROP INDEX IF EXISTS idx_articles_ai_status');
        $this->addSql('DROP INDEX IF EXISTS idx_user_ai_prefs_user_enabled');
        $this->addSql('DROP INDEX IF EXISTS idx_ai_summaries_article_created');

        // Remove columns from articles table
        $this->addSql('ALTER TABLE article DROP COLUMN IF EXISTS estimated_reading_time');
        $this->addSql('ALTER TABLE article DROP COLUMN IF EXISTS ai_processing_status');

        // Drop tables
        $this->addSql('DROP TABLE IF EXISTS user_ai_preferences');
        $this->addSql('DROP TABLE IF EXISTS ai_article_summaries');
    }
}