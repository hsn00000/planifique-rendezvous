<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260115094825 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE rendez_vous ADD client_nom VARCHAR(255) NOT NULL, ADD client_prenom VARCHAR(255) NOT NULL, ADD client_email VARCHAR(255) NOT NULL, ADD client_telephone VARCHAR(20) DEFAULT NULL, ADD commentaire LONGTEXT DEFAULT NULL, DROP nom, DROP prenom, DROP email, DROP telephone, DROP type_lieu, DROP adresse, CHANGE evenement_id evenement_id INT NOT NULL, CHANGE conseiller_id conseiller_id INT NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE rendez_vous ADD nom VARCHAR(255) NOT NULL, ADD prenom VARCHAR(255) NOT NULL, ADD email VARCHAR(255) NOT NULL, ADD telephone VARCHAR(20) NOT NULL, ADD type_lieu VARCHAR(20) NOT NULL, ADD adresse VARCHAR(255) DEFAULT NULL, DROP client_nom, DROP client_prenom, DROP client_email, DROP client_telephone, DROP commentaire, CHANGE conseiller_id conseiller_id INT DEFAULT NULL, CHANGE evenement_id evenement_id INT DEFAULT NULL');
    }
}
