<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260126000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add delaiFinModification field to evenement table for limiting modification/cancellation time';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify to your needs
        $this->addSql('ALTER TABLE evenement ADD delai_fin_modification INT DEFAULT 24 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify to your needs
        $this->addSql('ALTER TABLE evenement DROP delai_fin_modification');
    }
}
