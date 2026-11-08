<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Modération : dates d'inscription et de dernière connexion, jeux masqués, journal des actions.
 */
final class Version20261106180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add user created_at/last_login_at, game hidden_at and moderation_log table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE user ADD created_at DATETIME DEFAULT NULL, ADD last_login_at DATETIME DEFAULT NULL');
        $this->addSql('ALTER TABLE game ADD hidden_at DATETIME DEFAULT NULL');
        $this->addSql('CREATE TABLE moderation_log (id INT AUTO_INCREMENT NOT NULL, admin_id INT DEFAULT NULL, admin_email VARCHAR(180) NOT NULL, action VARCHAR(255) NOT NULL, target_type VARCHAR(20) NOT NULL, target_id INT DEFAULT NULL, target_label VARCHAR(255) NOT NULL, reason VARCHAR(500) DEFAULT NULL, created_at DATETIME NOT NULL, INDEX idx_moderation_log_created (created_at), INDEX idx_moderation_log_target (target_type, target_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE moderation_log');
        $this->addSql('ALTER TABLE game DROP hidden_at');
        $this->addSql('ALTER TABLE user DROP created_at, DROP last_login_at');
    }
}
