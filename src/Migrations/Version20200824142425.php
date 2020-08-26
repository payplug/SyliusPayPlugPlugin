<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20200824142425 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Added createdAt to payplug_refund_history table.';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->abortIf($this->connection->getDatabasePlatform()->getName() !== 'mysql', 'Migration can only be executed safely on \'mysql\'.');

        $this->addSql('ALTER TABLE payplug_refund_history ADD createdAt DATETIME NOT NULL');
        $this->addSql('ALTER TABLE payplug_refund_history DROP FOREIGN KEY FK_2D7BF4D84C3A3BB');
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
        $this->abortIf($this->connection->getDatabasePlatform()->getName() !== 'mysql', 'Migration can only be executed safely on \'mysql\'.');

        $this->addSql('ALTER TABLE payplug_refund_history DROP FOREIGN KEY FK_2D7BF4D84C3A3BB');
        $this->addSql('ALTER TABLE 
          payplug_refund_history 
        ADD 
          CONSTRAINT FK_2D7BF4D84C3A3BB FOREIGN KEY (payment_id) REFERENCES sylius_payment (id)');
        $this->addSql('ALTER TABLE payplug_refund_history DROP createdAt');
    }
}
