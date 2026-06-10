<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260610143940 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE INDEX idx_game_slug ON game (slug)');
        $this->addSql('CREATE INDEX idx_game_status ON game (status)');
        $this->addSql('CREATE INDEX idx_game_created_at ON game (created_at)');
        $this->addSql('CREATE INDEX idx_game_updated_at ON game (updated_at)');
        $this->addSql('CREATE INDEX idx_game_user_status ON game (user_id, status)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP INDEX idx_game_slug ON game');
        $this->addSql('DROP INDEX idx_game_status ON game');
        $this->addSql('DROP INDEX idx_game_created_at ON game');
        $this->addSql('DROP INDEX idx_game_updated_at ON game');
        $this->addSql('DROP INDEX idx_game_user_status ON game');
    }
}
