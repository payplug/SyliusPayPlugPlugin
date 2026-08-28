<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20210823123914 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Added PayPlug card entity.';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE payplug_cards (
          id INT AUTO_INCREMENT NOT NULL,
          external_id VARCHAR(255) NOT NULL,
          last4 VARCHAR(255) NOT NULL,
          country VARCHAR(255) NOT NULL,
          exp_month INT NOT NULL,
          exp_year INT NOT NULL,
          brand VARCHAR(255) NOT NULL,
          is_live TINYINT(1) NOT NULL,
          PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET UTF8 COLLATE `UTF8_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE payplug_cards ADD customer_id INT NOT NULL');
        $this->addSql('ALTER TABLE
          payplug_cards
        ADD
          CONSTRAINT FK_5A9F79629395C3F3 FOREIGN KEY (customer_id) REFERENCES sylius_customer (id)');
        $this->addSql('CREATE INDEX IDX_5A9F79629395C3F3 ON payplug_cards (customer_id)');
        $this->addSql('ALTER TABLE payplug_cards ADD paymentMethod_id INT NOT NULL');
        $this->addSql('ALTER TABLE
          payplug_cards
        ADD
          CONSTRAINT FK_5A9F7962F57FBCCC FOREIGN KEY (paymentMethod_id) REFERENCES sylius_payment_method (id)');
        $this->addSql('CREATE INDEX IDX_5A9F7962F57FBCCC ON payplug_cards (paymentMethod_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE payplug_cards DROP FOREIGN KEY FK_5A9F79629395C3F3');
        $this->addSql('DROP INDEX IDX_5A9F79629395C3F3 ON payplug_cards');
        $this->addSql('ALTER TABLE payplug_cards DROP FOREIGN KEY FK_5A9F7962F57FBCCC');
        $this->addSql('DROP INDEX IDX_5A9F7962F57FBCCC ON payplug_cards');

        $this->addSql('DROP TABLE payplug_cards');
    }
}
