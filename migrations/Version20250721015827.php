<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20250721015827 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add AI features to Article and UserArticle entities';
    }

    public function up(Schema $schema): void
    {
        // Add AI fields to Article entity
        $this->addSql('ALTER TABLE article ADD ai_summary TEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE article ADD ai_categories JSON DEFAULT NULL');
        $this->addSql('ALTER TABLE article ADD ai_score DOUBLE PRECISION DEFAULT NULL');
        $this->addSql('ALTER TABLE article ADD ai_reading_time INTEGER DEFAULT NULL');
        $this->addSql('ALTER TABLE article ADD ai_processed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');

        // Add AI fields to UserArticle entity
        $this->addSql('ALTER TABLE user_article ADD personalization_score DOUBLE PRECISION DEFAULT NULL');
        $this->addSql('ALTER TABLE user_article ADD interaction_data JSON DEFAULT NULL');

        // Add indexes for performance
        $this->addSql('CREATE INDEX IDX_AI_SCORE ON article (ai_score)');
        $this->addSql('CREATE INDEX IDX_AI_PROCESSED ON article (ai_processed_at)');
        $this->addSql('CREATE INDEX IDX_PERSONALIZATION_SCORE ON user_article (personalization_score)');
    }

    public function down(Schema $schema): void
    {
        // Remove indexes
        $this->addSql('DROP INDEX IDX_AI_SCORE');
        $this->addSql('DROP INDEX IDX_AI_PROCESSED');
        $this->addSql('DROP INDEX IDX_PERSONALIZATION_SCORE');

        // Remove AI fields from Article entity
        $this->addSql('ALTER TABLE article DROP ai_summary');
        $this->addSql('ALTER TABLE article DROP ai_categories');
        $this->addSql('ALTER TABLE article DROP ai_score');
        $this->addSql('ALTER TABLE article DROP ai_reading_time');
        $this->addSql('ALTER TABLE article DROP ai_processed_at');

        // Remove AI fields from UserArticle entity
        $this->addSql('ALTER TABLE user_article DROP personalization_score');
        $this->addSql('ALTER TABLE user_article DROP interaction_data');
    }
}