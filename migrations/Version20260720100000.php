<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * PRE-3469 spike only: schema for the throwaway PayplugOperation entity (src/Spike/Entity).
 * A real migration — not a manual step or a SchemaTool call from the test — because the
 * mapping is registered whenever kernel.environment=test (see
 * PayPlugSyliusPayPlugExtension::prependSpikeDoctrineMapping()), which includes routine
 * `sylius:fixtures:load` runs on a fresh checkout, not just the spike's own integration test.
 * Without this migration, `sylius:fixtures:load` fails for anyone setting up the test
 * application from scratch — found by testing this on a clean database, not by reasoning about
 * it. Guarded to APP_ENV=test in both directions so a real merchant deployment never gets this
 * table created (up) or, if it somehow did, never gets it dropped outside test either (down) —
 * a normal plugin migration otherwise runs unconditionally, including in production. Drop this
 * migration together with src/Spike/ once the spike is closed.
 */
final class Version20260720100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'PRE-3469 spike: add payplug_operation table for the throwaway PayplugOperation entity.';
    }

    public function up(Schema $schema): void
    {
        $this->skipIf(
            'test' !== ($_SERVER['APP_ENV'] ?? $_ENV['APP_ENV'] ?? null),
            'PRE-3469 spike-only schema, not applied outside the test environment.',
        );

        $this->addSql('CREATE TABLE payplug_operation (
          id INT AUTO_INCREMENT NOT NULL,
          operation_id VARCHAR(255) NOT NULL,
          exec_code VARCHAR(255) NOT NULL,
          outcome VARCHAR(255) NOT NULL,
          amount INT NOT NULL,
          order_id VARCHAR(255) NOT NULL,
          treated TINYINT(1) NOT NULL,
          treated_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\',
          created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
          UNIQUE INDEX payplug_operation_id_unique (operation_id),
          INDEX payplug_operation_order_id_idx (order_id),
          PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET UTF8 COLLATE `UTF8_unicode_ci` ENGINE = InnoDB');
    }

    public function down(Schema $schema): void
    {
        $this->skipIf(
            'test' !== ($_SERVER['APP_ENV'] ?? $_ENV['APP_ENV'] ?? null),
            'PRE-3469 spike-only schema, not applied outside the test environment.',
        );

        $this->addSql('DROP TABLE payplug_operation');
    }
}
