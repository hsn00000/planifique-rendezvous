<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260114111218 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE disponibilite (id INT AUTO_INCREMENT NOT NULL, date_debut DATETIME NOT NULL, date_fin DATETIME NOT NULL, statut VARCHAR(255) NOT NULL, conseiller_id INT DEFAULT NULL, INDEX IDX_2CBACE2F1AC39A0D (conseiller_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE disponibilite ADD CONSTRAINT FK_2CBACE2F1AC39A0D FOREIGN KEY (conseiller_id) REFERENCES user (id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE disponibilite DROP FOREIGN KEY FK_2CBACE2F1AC39A0D');
        $this->addSql('DROP TABLE disponibilite');
    }
}
