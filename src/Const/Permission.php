<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Const;

/**
 * Permission list that payplug can return
 */
final class Permission
{
    public const USE_LIVE_MODE = 'use_live_mode';
    public const CAN_SAVE_CARD = 'can_save_cards';
    public const CAN_CREATE_DEFERRED_PAYMENT = 'can_create_deferred_payment';
    public const CAN_USE_INTEGRATED_PAYMENTS = 'can_use_integrated_payments';
    public const CAN_CREATE_INSTALLMENT_PLAN = 'can_create_installment_plan';
    public const CAN_USE_ONEY = 'can_use_oney';

    public static function getAll(): array
    {
        return [
            self::USE_LIVE_MODE,
            self::CAN_SAVE_CARD,
            self::CAN_CREATE_DEFERRED_PAYMENT,
            self::CAN_USE_INTEGRATED_PAYMENTS,
            self::CAN_CREATE_INSTALLMENT_PLAN,
            self::CAN_USE_ONEY,
        ];
    }

    public static function isPermission(string $permission): bool
    {
        return in_array($permission, self::getAll(), true);
    }
}
