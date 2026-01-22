<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260124000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add cancelToken field to rendez_vous table for client cancellation/modification';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify to your needs
        $this->addSql('ALTER TABLE rendez_vous ADD cancel_token VARCHAR(64) DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_65E8AA0A7C74B4 ON rendez_vous (cancel_token)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify to your needs
        $this->addSql('DROP INDEX UNIQ_65E8AA0A7C74B4 ON rendez_vous');
        $this->addSql('ALTER TABLE rendez_vous DROP cancel_token');
    }
}
