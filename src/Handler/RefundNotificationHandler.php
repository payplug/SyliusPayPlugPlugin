<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Handler;

use Payplug\Resource\IVerifiableAPIResource;
use Payplug\Resource\Refund;
use PayPlug\SyliusPayPlugPlugin\ApiClient\PayPlugApiClientInterface;
use PayPlug\SyliusPayPlugPlugin\Entity\RefundHistory;
use PayPlug\SyliusPayPlugPlugin\PaymentProcessing\RefundPaymentHandlerInterface;
use PayPlug\SyliusPayPlugPlugin\Repository\RefundHistoryRepositoryInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Symfony\Component\Messenger\MessageBusInterface;

class RefundNotificationHandler
{
    /** @var \PayPlug\SyliusPayPlugPlugin\Repository\RefundHistoryRepositoryInterface */
    private $payplugRefundHistoryRepository;

    /** @var \PayPlug\SyliusPayPlugPlugin\PaymentProcessing\RefundPaymentHandlerInterface */
    private $refundPaymentHandler;

    /** @var \Symfony\Component\Messenger\MessageBusInterface */
    private $commandBus;

    public function __construct(
        RefundHistoryRepositoryInterface $payplugRefundHistoryRepository,
        RefundPaymentHandlerInterface $refundPaymentHandler,
        MessageBusInterface $commandBus
    ) {
        $this->payplugRefundHistoryRepository = $payplugRefundHistoryRepository;
        $this->refundPaymentHandler = $refundPaymentHandler;
        $this->commandBus = $commandBus;
    }

    public function treat(PaymentInterface $payment, IVerifiableAPIResource $refundResource, \ArrayObject $details): void
    {
        if (!$refundResource instanceof Refund) {
            return;
        }

        $metadata = $refundResource->metadata;
        if (isset($metadata['refund_from_sylius'])) {
            return;
        }

        $details['status'] = PayPlugApiClientInterface::REFUNDED;
        $refundUnits = $this->refundPaymentHandler->handle($refundResource, $payment);

        /** @var RefundHistory|null $refundHistory */
        $refundHistory = $this->payplugRefundHistoryRepository->findOneBy(['externalId' => $refundResource->id]);
        if ($refundHistory instanceof RefundHistory) {
            return;
        }

        $refundHistory = new RefundHistory();
        $refundHistory
            ->setExternalId($refundResource->id)
            ->setValue($refundResource->amount)
            ->setPayment($payment)
        ;

        $this->payplugRefundHistoryRepository->add($refundHistory);
        $this->commandBus->dispatch($refundUnits);
    }
}
