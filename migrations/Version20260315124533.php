<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260315124533 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE training (id INT AUTO_INCREMENT NOT NULL, scheduled_at DATETIME NOT NULL, location VARCHAR(150) DEFAULT NULL, description VARCHAR(255) DEFAULT NULL, team_id INT NOT NULL, INDEX IDX_D5128A8F296CD8AE (team_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE training_attendance (id INT AUTO_INCREMENT NOT NULL, status VARCHAR(20) NOT NULL, training_id INT NOT NULL, member_id INT NOT NULL, INDEX IDX_D75DB7F7BEFD98D1 (training_id), INDEX IDX_D75DB7F77597D3FE (member_id), UNIQUE INDEX unique_training_member (training_id, member_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE training ADD CONSTRAINT FK_D5128A8F296CD8AE FOREIGN KEY (team_id) REFERENCES team (id)');
        $this->addSql('ALTER TABLE training_attendance ADD CONSTRAINT FK_D75DB7F7BEFD98D1 FOREIGN KEY (training_id) REFERENCES training (id)');
        $this->addSql('ALTER TABLE training_attendance ADD CONSTRAINT FK_D75DB7F77597D3FE FOREIGN KEY (member_id) REFERENCES member (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE training DROP FOREIGN KEY FK_D5128A8F296CD8AE');
        $this->addSql('ALTER TABLE training_attendance DROP FOREIGN KEY FK_D75DB7F7BEFD98D1');
        $this->addSql('ALTER TABLE training_attendance DROP FOREIGN KEY FK_D75DB7F77597D3FE');
        $this->addSql('DROP TABLE training');
        $this->addSql('DROP TABLE training_attendance');
    }
}
