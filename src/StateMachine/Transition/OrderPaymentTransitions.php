<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\StateMachine\Transition;

use Sylius\Component\Core\OrderPaymentTransitions as BaseOrderPaymentTransitions;

interface OrderPaymentTransitions extends BaseOrderPaymentTransitions
{
    public const TRANSITION_ONEY_REQUEST_PAYMENT = 'oney_request_payment';
}
