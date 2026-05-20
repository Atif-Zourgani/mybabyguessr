<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260519194927 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create game table — Baby Guessr entity (token, categories, answers, status)';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE game (id INT AUTO_INCREMENT NOT NULL, token VARCHAR(64) NOT NULL, title VARCHAR(100) DEFAULT NULL, image VARCHAR(255) DEFAULT NULL, status VARCHAR(255) NOT NULL, due_date DATE DEFAULT NULL, guess_gender TINYINT NOT NULL, guess_name TINYINT NOT NULL, guess_date TINYINT NOT NULL, guess_weight TINYINT NOT NULL, guess_height TINYINT NOT NULL, name_mode VARCHAR(255) DEFAULT NULL, answer_gender VARCHAR(255) DEFAULT NULL, answer_name VARCHAR(100) DEFAULT NULL, answer_date DATE DEFAULT NULL, answer_weight INT DEFAULT NULL, answer_height INT DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, user_id INT NOT NULL, UNIQUE INDEX UNIQ_232B318C5F37A13B (token), INDEX IDX_232B318CA76ED395 (user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE game ADD CONSTRAINT FK_232B318CA76ED395 FOREIGN KEY (user_id) REFERENCES user (id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE game DROP FOREIGN KEY FK_232B318CA76ED395');
        $this->addSql('DROP TABLE game');
    }
}
