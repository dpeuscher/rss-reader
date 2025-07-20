<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20250720015800 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add RSS Feed Health Monitoring system - FeedHealthLog entity and Feed health fields';
    }

    public function up(Schema $schema): void
    {
        // Add health monitoring fields to feed table
        $this->addSql('ALTER TABLE feed ADD health_status VARCHAR(20) DEFAULT \'healthy\' NOT NULL');
        $this->addSql('ALTER TABLE feed ADD last_health_check TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE feed ADD consecutive_failures INT DEFAULT 0 NOT NULL');

        // Create feed_health_log table
        $this->addSql('CREATE TABLE feed_health_log (
            id SERIAL NOT NULL,
            feed_id INT NOT NULL,
            status VARCHAR(20) NOT NULL,
            response_time INT DEFAULT NULL,
            http_status_code INT DEFAULT NULL,
            error_message TEXT DEFAULT NULL,
            checked_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            consecutive_failures INT DEFAULT NULL,
            PRIMARY KEY(id)
        )');

        // Add foreign key constraint
        $this->addSql('ALTER TABLE feed_health_log ADD CONSTRAINT FK_FEED_HEALTH_LOG_FEED_ID 
                       FOREIGN KEY (feed_id) REFERENCES feed (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');

        // Create index for performance
        $this->addSql('CREATE INDEX IDX_FEED_HEALTH_LOG_FEED_ID ON feed_health_log (feed_id)');
        $this->addSql('CREATE INDEX IDX_FEED_HEALTH_LOG_CHECKED_AT ON feed_health_log (checked_at)');
        $this->addSql('CREATE INDEX IDX_FEED_HEALTH_LOG_STATUS ON feed_health_log (status)');
    }

    public function down(Schema $schema): void
    {
        // Drop feed_health_log table
        $this->addSql('DROP TABLE feed_health_log');

        // Remove health monitoring fields from feed table
        $this->addSql('ALTER TABLE feed DROP COLUMN health_status');
        $this->addSql('ALTER TABLE feed DROP COLUMN last_health_check');
        $this->addSql('ALTER TABLE feed DROP COLUMN consecutive_failures');
    }
}