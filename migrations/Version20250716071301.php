<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20250716071301 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add cache_duration column to feed table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE feed ADD cache_duration INT DEFAULT 900 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE feed DROP cache_duration');
    }
}