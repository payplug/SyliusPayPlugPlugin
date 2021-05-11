<?php

namespace PayPlug\SyliusPayPlugPlugin\StateMachine\Transition;

interface OrderPaymentTransitions
{
    public const GRAPH = 'payplug_sylius_order_payment';

    public const TRANSITION_REQUEST_PAYMENT = 'request_payment';
}
