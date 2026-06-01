<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260601132751 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE app_error_log (id INT AUTO_INCREMENT NOT NULL, status_code SMALLINT NOT NULL, url VARCHAR(500) NOT NULL, message VARCHAR(500) DEFAULT NULL, user_id INT DEFAULT NULL, user_email VARCHAR(180) DEFAULT NULL, created_at DATETIME NOT NULL, INDEX idx_error_log_created (created_at), INDEX idx_error_log_status (status_code), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP TABLE app_error_log');
    }
}
