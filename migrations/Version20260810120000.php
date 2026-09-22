<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260810120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Added PayPlug UPC operation entity.';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE payplug_upc_operation (
          id INT AUTO_INCREMENT NOT NULL,
          order_id VARCHAR(255) NOT NULL,
          operation_id VARCHAR(255) NOT NULL,
          exec_code VARCHAR(255) NOT NULL,
          outcome VARCHAR(255) NOT NULL,
          amount INT NOT NULL,
          treated TINYINT(1) NOT NULL,
          created_at DATETIME NOT NULL,
          UNIQUE INDEX UNIQ_payplug_upc_operation_operation_id (operation_id),
          INDEX idx_payplug_upc_operation_order_id (order_id),
          PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET UTF8 COLLATE `UTF8_unicode_ci` ENGINE = InnoDB');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP TABLE payplug_upc_operation');
    }
}
