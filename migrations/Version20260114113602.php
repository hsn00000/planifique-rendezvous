<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260114113602 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE disponibilite_hebdomadaire (id INT AUTO_INCREMENT NOT NULL, jour_semaine INT NOT NULL, heure_debut TIME NOT NULL, heure_fin TIME NOT NULL, user_id INT DEFAULT NULL, INDEX IDX_97EDAC12A76ED395 (user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE disponibilite_hebdomadaire ADD CONSTRAINT FK_97EDAC12A76ED395 FOREIGN KEY (user_id) REFERENCES user (id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE disponibilite_hebdomadaire DROP FOREIGN KEY FK_97EDAC12A76ED395');
        $this->addSql('DROP TABLE disponibilite_hebdomadaire');
    }
}
