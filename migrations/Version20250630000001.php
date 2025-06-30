<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20250630000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create RSS Reader database schema';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE users (
            id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, 
            email VARCHAR(180) NOT NULL, 
            roles CLOB NOT NULL, 
            password VARCHAR(255) NOT NULL, 
            name VARCHAR(255) NOT NULL, 
            created_at DATETIME NOT NULL
        )');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_1483A5E9E7927C74 ON users (email)');
        
        $this->addSql('CREATE TABLE feeds (
            id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, 
            title VARCHAR(255) NOT NULL, 
            url VARCHAR(500) NOT NULL, 
            link VARCHAR(500) DEFAULT NULL, 
            description CLOB DEFAULT NULL, 
            last_fetched DATETIME DEFAULT NULL, 
            last_modified DATETIME DEFAULT NULL, 
            etag VARCHAR(255) DEFAULT NULL, 
            active BOOLEAN NOT NULL, 
            created_at DATETIME NOT NULL
        )');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_52899853C4663E4 ON feeds (url)');
        
        $this->addSql('CREATE TABLE articles (
            id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, 
            feed_id INTEGER NOT NULL, 
            title VARCHAR(500) NOT NULL, 
            guid VARCHAR(1000) NOT NULL, 
            link VARCHAR(1000) DEFAULT NULL, 
            description CLOB DEFAULT NULL, 
            content CLOB DEFAULT NULL, 
            author VARCHAR(255) DEFAULT NULL, 
            published_at DATETIME DEFAULT NULL, 
            created_at DATETIME NOT NULL, 
            CONSTRAINT FK_BFDD316851A5BC03 FOREIGN KEY (feed_id) REFERENCES feeds (id) NOT DEFERRABLE INITIALLY IMMEDIATE
        )');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_BFDD31682B6FCFB2 ON articles (guid)');
        $this->addSql('CREATE INDEX IDX_BFDD316851A5BC03 ON articles (feed_id)');
        $this->addSql('CREATE INDEX idx_guid ON articles (guid)');
        $this->addSql('CREATE INDEX idx_published_at ON articles (published_at)');
        
        $this->addSql('CREATE TABLE subscriptions (
            id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, 
            user_id INTEGER NOT NULL, 
            feed_id INTEGER NOT NULL, 
            title VARCHAR(255) DEFAULT NULL, 
            created_at DATETIME NOT NULL, 
            CONSTRAINT FK_4778A01A76ED395 FOREIGN KEY (user_id) REFERENCES users (id) NOT DEFERRABLE INITIALLY IMMEDIATE, 
            CONSTRAINT FK_4778A0151A5BC03 FOREIGN KEY (feed_id) REFERENCES feeds (id) NOT DEFERRABLE INITIALLY IMMEDIATE
        )');
        $this->addSql('CREATE INDEX IDX_4778A01A76ED395 ON subscriptions (user_id)');
        $this->addSql('CREATE INDEX IDX_4778A0151A5BC03 ON subscriptions (feed_id)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_4778A01A76ED39551A5BC03 ON subscriptions (user_id, feed_id)');
        
        $this->addSql('CREATE TABLE read_articles (
            id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, 
            user_id INTEGER NOT NULL, 
            article_id INTEGER NOT NULL, 
            read_at DATETIME NOT NULL, 
            CONSTRAINT FK_9C3C8E7BA76ED395 FOREIGN KEY (user_id) REFERENCES users (id) NOT DEFERRABLE INITIALLY IMMEDIATE, 
            CONSTRAINT FK_9C3C8E7B7294869C FOREIGN KEY (article_id) REFERENCES articles (id) NOT DEFERRABLE INITIALLY IMMEDIATE
        )');
        $this->addSql('CREATE INDEX IDX_9C3C8E7BA76ED395 ON read_articles (user_id)');
        $this->addSql('CREATE INDEX IDX_9C3C8E7B7294869C ON read_articles (article_id)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_9C3C8E7BA76ED3957294869C ON read_articles (user_id, article_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE read_articles');
        $this->addSql('DROP TABLE subscriptions');
        $this->addSql('DROP TABLE articles');
        $this->addSql('DROP TABLE feeds');
        $this->addSql('DROP TABLE users');
    }
}