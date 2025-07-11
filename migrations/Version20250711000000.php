<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20250711000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create initial database schema with user registration functionality';
    }

    public function up(Schema $schema): void
    {
        // Create user table
        $this->addSql('CREATE TABLE user (
            id INT AUTO_INCREMENT NOT NULL,
            email VARCHAR(180) NOT NULL,
            roles JSON NOT NULL,
            password VARCHAR(255) NOT NULL,
            PRIMARY KEY(id),
            UNIQUE KEY UNIQ_8D93D649E7927C74 (email),
            KEY idx_user_email (email)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB');
        
        // Create category table
        $this->addSql('CREATE TABLE category (
            id INT AUTO_INCREMENT NOT NULL,
            user_id INT NOT NULL,
            name VARCHAR(255) NOT NULL,
            PRIMARY KEY(id),
            KEY IDX_64C19C1A76ED395 (user_id),
            CONSTRAINT FK_64C19C1A76ED395 FOREIGN KEY (user_id) REFERENCES user (id) ON DELETE CASCADE
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB');
        
        // Create feed table
        $this->addSql('CREATE TABLE feed (
            id INT AUTO_INCREMENT NOT NULL,
            title VARCHAR(255) NOT NULL,
            url VARCHAR(1024) NOT NULL,
            description TEXT,
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB');
        
        // Create subscription table
        $this->addSql('CREATE TABLE subscription (
            id INT AUTO_INCREMENT NOT NULL,
            user_id INT NOT NULL,
            feed_id INT NOT NULL,
            category_id INT DEFAULT NULL,
            PRIMARY KEY(id),
            KEY IDX_A3C664D3A76ED395 (user_id),
            KEY IDX_A3C664D351A5BC03 (feed_id),
            KEY IDX_A3C664D312469DE2 (category_id),
            CONSTRAINT FK_A3C664D3A76ED395 FOREIGN KEY (user_id) REFERENCES user (id) ON DELETE CASCADE,
            CONSTRAINT FK_A3C664D351A5BC03 FOREIGN KEY (feed_id) REFERENCES feed (id) ON DELETE CASCADE,
            CONSTRAINT FK_A3C664D312469DE2 FOREIGN KEY (category_id) REFERENCES category (id) ON DELETE SET NULL
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB');
        
        // Create article table
        $this->addSql('CREATE TABLE article (
            id INT AUTO_INCREMENT NOT NULL,
            feed_id INT NOT NULL,
            title VARCHAR(255) NOT NULL,
            content TEXT,
            url VARCHAR(1024),
            published_at DATETIME DEFAULT NULL,
            guid VARCHAR(255),
            PRIMARY KEY(id),
            KEY IDX_23A0E6651A5BC03 (feed_id),
            KEY IDX_23A0E663B13EC43 (published_at),
            CONSTRAINT FK_23A0E6651A5BC03 FOREIGN KEY (feed_id) REFERENCES feed (id) ON DELETE CASCADE
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB');
        
        // Create user_article table
        $this->addSql('CREATE TABLE user_article (
            id INT AUTO_INCREMENT NOT NULL,
            user_id INT NOT NULL,
            article_id INT NOT NULL,
            is_read TINYINT(1) DEFAULT 0,
            is_starred TINYINT(1) DEFAULT 0,
            PRIMARY KEY(id),
            KEY IDX_F516D959A76ED395 (user_id),
            KEY IDX_F516D9597294869C (article_id),
            UNIQUE KEY UNIQ_F516D959A76ED3957294869C (user_id, article_id),
            CONSTRAINT FK_F516D959A76ED395 FOREIGN KEY (user_id) REFERENCES user (id) ON DELETE CASCADE,
            CONSTRAINT FK_F516D9597294869C FOREIGN KEY (article_id) REFERENCES article (id) ON DELETE CASCADE
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS user_article');
        $this->addSql('DROP TABLE IF EXISTS article');
        $this->addSql('DROP TABLE IF EXISTS subscription');
        $this->addSql('DROP TABLE IF EXISTS feed');
        $this->addSql('DROP TABLE IF EXISTS category');
        $this->addSql('DROP TABLE IF EXISTS user');
    }
}