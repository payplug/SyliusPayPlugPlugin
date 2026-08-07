<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260806120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Added payplug_operations table backing UPC IPaymentRepository (webhook idempotency tracking).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE payplug_operations (
          id INT AUTO_INCREMENT NOT NULL,
          operation_id VARCHAR(255) NOT NULL,
          order_id VARCHAR(255) NOT NULL,
          exec_code VARCHAR(255) NOT NULL,
          outcome VARCHAR(255) NOT NULL,
          amount INT NOT NULL,
          treated TINYINT(1) NOT NULL,
          created_at DATETIME NOT NULL,
          UNIQUE INDEX UNIQ_PAYPLUG_OPERATIONS_OPERATION_ID (operation_id),
          INDEX IDX_PAYPLUG_OPERATIONS_ORDER_ID (order_id),
          PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET UTF8 COLLATE `UTF8_unicode_ci` ENGINE = InnoDB');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE payplug_operations');
    }
}
