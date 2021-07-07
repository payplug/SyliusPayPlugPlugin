<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Handler;

use DateTimeImmutable;
use Payplug\Resource\IVerifiableAPIResource;
use Payplug\Resource\Payment;
use Payplug\Resource\PaymentAuthorization;
use PayPlug\SyliusPayPlugPlugin\ApiClient\PayPlugApiClientInterface;
use PayPlug\SyliusPayPlugPlugin\Gateway\OneyGatewayFactory;
use Psr\Log\LoggerInterface;

class PaymentNotificationHandler
{
    /** @var \Psr\Log\LoggerInterface */
    private $logger;

    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    public function treat(IVerifiableAPIResource $paymentResource, \ArrayObject $details): void
    {
        if (!$paymentResource instanceof Payment) {
            return;
        }

        if ($paymentResource->is_paid) {
            $details['status'] = PayPlugApiClientInterface::STATUS_CAPTURED;
            $details['created_at'] = $paymentResource->created_at;

            return;
        }

        if ($this->isResourceIsAuthorized($paymentResource)) {
            $details['status'] = PayPlugApiClientInterface::STATUS_AUTHORIZED;

            return;
        }

        if ($this->isRefusedOneyPayment($paymentResource)) {
            $details['status'] = PayPlugApiClientInterface::STATUS_CANCELED_BY_ONEY;

            $details['failure'] = [
                'code' => $paymentResource->failure->code ?? '',
                'message' => $paymentResource->failure->message ?? '',
            ];

            return;
        }

        $this->logger->info('[PayPlug] Notify action', ['failure' => $paymentResource->failure]);
        $details['failure'] = [
            'code' => $paymentResource->failure->code ?? '',
            'message' => $paymentResource->failure->message ?? '',
        ];
        $details['status'] = PayPlugApiClientInterface::FAILED;
    }

    private function isResourceIsAuthorized(IVerifiableAPIResource $paymentResource): bool
    {
        if (!$paymentResource instanceof Payment) {
            return false;
        }

        // Oney is reviewing the payer’s file
        if ($paymentResource->__isset('payment_method') &&
            $paymentResource->__get('payment_method') !== null &&
            $paymentResource->__get('payment_method')['is_pending'] === true) {
            return true;
        }

        $now = new DateTimeImmutable();
        if ($paymentResource->__isset('authorization') &&
            $paymentResource->__get('authorization') instanceof PaymentAuthorization &&
            $paymentResource->__get('authorization')->__get('expires_at') !== null &&
            $now < $now->setTimestamp($paymentResource->__get('authorization')->__get('expires_at'))) {
            return true;
        }

        return false;
    }

    private function isRefusedOneyPayment(IVerifiableAPIResource $paymentResource): bool
    {
        if (!$paymentResource instanceof Payment) {
            return false;
        }

        // Oney has reviewed the payer’s file and refused it
        if (!$paymentResource->is_paid &&
            $paymentResource->__isset('payment_method') &&
            $paymentResource->__get('payment_method') !== null &&
            $paymentResource->__get('payment_method')['is_pending'] === false &&
            \in_array($paymentResource->__get('payment_method')['type'], OneyGatewayFactory::PAYMENT_CHOICES, true)
        ) {
            return true;
        }

        return false;
    }
}
