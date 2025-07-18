<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20250718194500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add additional performance indexes for AI processing queries';
    }

    public function up(Schema $schema): void
    {
        // Composite index on articles for processing queue queries
        $this->addSql('CREATE INDEX idx_articles_status_created ON article(ai_processing_status, created_at)');
        
        // Index on ai_summaries for analytics queries
        $this->addSql('CREATE INDEX idx_ai_summaries_provider_created ON ai_article_summaries(ai_provider, created_at)');
        
        // Index on user preferences for compliance reporting
        $this->addSql('CREATE INDEX idx_user_prefs_consent_date ON user_ai_preferences(consent_given_at)');
    }

    public function down(Schema $schema): void
    {
        // Drop the additional indexes
        $this->addSql('DROP INDEX IF EXISTS idx_user_prefs_consent_date');
        $this->addSql('DROP INDEX IF EXISTS idx_ai_summaries_provider_created');
        $this->addSql('DROP INDEX IF EXISTS idx_articles_status_created');
    }
}