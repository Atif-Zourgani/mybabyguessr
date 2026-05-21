<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260521171843 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE guess (id INT AUTO_INCREMENT NOT NULL, player_name VARCHAR(100) NOT NULL, player_email VARCHAR(255) DEFAULT NULL, guess_gender VARCHAR(255) DEFAULT NULL, guess_name VARCHAR(100) DEFAULT NULL, guess_date DATE DEFAULT NULL, guess_weight INT DEFAULT NULL, guess_height INT DEFAULT NULL, name_hints_used SMALLINT DEFAULT 0 NOT NULL, created_at DATETIME NOT NULL, game_id INT NOT NULL, INDEX IDX_32D30F96E48FD905 (game_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE guess ADD CONSTRAINT FK_32D30F96E48FD905 FOREIGN KEY (game_id) REFERENCES game (id)');
        $this->addSql('ALTER TABLE game ADD show_guesses TINYINT DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE guess DROP FOREIGN KEY FK_32D30F96E48FD905');
        $this->addSql('DROP TABLE guess');
        $this->addSql('ALTER TABLE game DROP show_guesses');
    }
}
