<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20210410143918 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Refund history table for PayPlug';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE payplug_refund_history (
          id INT AUTO_INCREMENT NOT NULL,
          refund_payment_id INT DEFAULT NULL,
          payment_id INT DEFAULT NULL,
          externalId VARCHAR(255) DEFAULT NULL,
          value INT DEFAULT NULL,
          processed TINYINT(1) NOT NULL,
          createdAt DATETIME NOT NULL,
          UNIQUE INDEX UNIQ_2D7BF4D8E739D017 (refund_payment_id),
          INDEX IDX_2D7BF4D84C3A3BB (payment_id),
          PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET UTF8 COLLATE `UTF8_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE
          payplug_refund_history
        ADD
          CONSTRAINT FK_2D7BF4D8E739D017 FOREIGN KEY (refund_payment_id) REFERENCES sylius_refund_refund_payment (id)');
        $this->addSql('ALTER TABLE
          payplug_refund_history
        ADD
          CONSTRAINT FK_2D7BF4D84C3A3BB FOREIGN KEY (payment_id) REFERENCES sylius_payment (id) ON DELETE
        SET
          NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP TABLE payplug_refund_history');
    }
}
