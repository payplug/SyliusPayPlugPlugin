<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * PRE-3646 removed the "SubMerchant identifier" admin field (`hfSubMerchantId`): it belongs to the
 * UDV/MID configuration for EUR payments, not to Unified Hosted Fields, and sending it made the
 * Unified API reject refunds with `400 "Invalid parameter."`.
 *
 * The field is gone from the form and from every payload, but merchants who had configured it keep
 * the value in their persisted gateway config, where no code reads it any more. It was collected
 * through a PasswordType, so it is a credential-shaped value outliving its only purpose — purge it
 * rather than leave it sitting in the database indefinitely.
 */
final class Version20260907140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Removed the orphaned hfSubMerchantId key from persisted payplug gateway configs (PRE-3646).';
    }

    public function up(Schema $schema): void
    {
        // sylius_gateway_config.config is a Doctrine `json` column, so the key can be dropped in
        // place. JSON_CONTAINS_PATH keeps the UPDATE to rows that actually carry it, leaving every
        // other gateway's config untouched — and makes the statement a no-op on a database where
        // the field was never configured.
        $this->addSql(<<<'SQL'
            UPDATE sylius_gateway_config
            SET config = JSON_REMOVE(config, '$.hfSubMerchantId')
            WHERE factory_name = 'payplug'
              AND JSON_CONTAINS_PATH(config, 'one', '$.hfSubMerchantId')
            SQL);
    }

    public function down(Schema $schema): void
    {
        // Deliberately not reversible: the removed value is a credential this migration does not
        // retain, and no code version reads the key any more. Rolling the plugin back past
        // PRE-3646 means re-entering it in the admin form, which is also the only way to obtain it.
    }
}
