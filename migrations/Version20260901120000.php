<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Closes a TOCTOU race in PayplugCardPersister::persist(): its findOneBy-then-add dedup guard is
 * reachable from two independent paths for the same alias (the synchronous frictionless capture
 * and the async webhook), so without a DB-level constraint a race between them could create two
 * Card rows for one alias/liveness pair.
 */
final class Version20260901120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Added a unique constraint on payplug_cards (external_id, is_live) to prevent duplicate card aliases under a race.';
    }

    public function up(Schema $schema): void
    {
        // The race this constraint closes has been reachable since the payplug_cards table's
        // introduction, so an existing merchant database may already carry duplicate
        // (external_id, is_live) rows — remove all but the lowest-id row per pair first, or the
        // CREATE UNIQUE INDEX below fails outright on any DB where that already happened.
        $this->addSql('DELETE t1 FROM payplug_cards t1 INNER JOIN payplug_cards t2 ON t1.external_id = t2.external_id AND t1.is_live = t2.is_live AND t1.id > t2.id');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_payplug_cards_external_id_is_live ON payplug_cards (external_id, is_live)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX UNIQ_payplug_cards_external_id_is_live ON payplug_cards');
    }
}
