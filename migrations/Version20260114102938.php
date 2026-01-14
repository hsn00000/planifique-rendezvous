<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260114102938 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE rendez_vous (id INT AUTO_INCREMENT NOT NULL, nom VARCHAR(255) NOT NULL, prenom VARCHAR(255) NOT NULL, email VARCHAR(255) NOT NULL, telephone VARCHAR(20) NOT NULL, date_debut DATETIME NOT NULL, type_lieu VARCHAR(20) NOT NULL, adresse VARCHAR(255) DEFAULT NULL, evenement_id INT DEFAULT NULL, conseiller_id INT DEFAULT NULL, INDEX IDX_65E8AA0AFD02F13 (evenement_id), INDEX IDX_65E8AA0A1AC39A0D (conseiller_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE rendez_vous ADD CONSTRAINT FK_65E8AA0AFD02F13 FOREIGN KEY (evenement_id) REFERENCES evenement (id)');
        $this->addSql('ALTER TABLE rendez_vous ADD CONSTRAINT FK_65E8AA0A1AC39A0D FOREIGN KEY (conseiller_id) REFERENCES user (id)');
        $this->addSql('ALTER TABLE evenement DROP date_debut, DROP visio_url, CHANGE groupe_id groupe_id INT NOT NULL, CHANGE is_round_robin is_round_robin TINYINT DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE rendez_vous DROP FOREIGN KEY FK_65E8AA0AFD02F13');
        $this->addSql('ALTER TABLE rendez_vous DROP FOREIGN KEY FK_65E8AA0A1AC39A0D');
        $this->addSql('DROP TABLE rendez_vous');
        $this->addSql('ALTER TABLE evenement ADD date_debut DATETIME NOT NULL, ADD visio_url VARCHAR(255) DEFAULT NULL, CHANGE is_round_robin is_round_robin TINYINT NOT NULL, CHANGE groupe_id groupe_id INT DEFAULT NULL');
    }
}
