<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Action;

use Payplug\Resource\IVerifiableAPIResource;
use Payplug\Resource\Payment;
use PayPlug\SyliusPayPlugPlugin\Action\Api\ApiAwareTrait;
use PayPlug\SyliusPayPlugPlugin\ApiClient\PayPlugApiClientInterface;
use PayPlug\SyliusPayPlugPlugin\Entity\RefundHistory;
use PayPlug\SyliusPayPlugPlugin\PaymentProcessing\RefundPaymentHandlerInterface;
use PayPlug\SyliusPayPlugPlugin\Repository\RefundHistoryRepositoryInterface;
use Payum\Core\Action\ActionInterface;
use Payum\Core\ApiAwareInterface;
use Payum\Core\Bridge\Spl\ArrayObject;
use Payum\Core\GatewayAwareInterface;
use Payum\Core\GatewayAwareTrait;
use Payum\Core\Request\Notify;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

final class NotifyAction implements ActionInterface, ApiAwareInterface, GatewayAwareInterface
{
    use GatewayAwareTrait, ApiAwareTrait;

    /** @var RefundPaymentHandlerInterface */
    private $refundPaymentHandler;

    /** @var LoggerInterface */
    private $logger;

    /** @var MessageBusInterface */
    private $commandBus;

    /** @var RefundHistoryRepositoryInterface */
    private $payplugRefundHistoryRepository;

    public function __construct(
        LoggerInterface $logger,
        RefundPaymentHandlerInterface $refundPaymentHandler,
        MessageBusInterface $commandBus,
        RefundHistoryRepositoryInterface $payplugRefundHistoryRepository
    ) {
        $this->logger = $logger;
        $this->refundPaymentHandler = $refundPaymentHandler;
        $this->commandBus = $commandBus;
        $this->payplugRefundHistoryRepository = $payplugRefundHistoryRepository;
    }

    public function execute($request): void
    {
        $details = ArrayObject::ensureArrayObject($request->getModel());

        $input = file_get_contents('php://input');

        try {
            if (!is_string($input)) {
                throw new \LogicException('Input must be of type string.');
            }
            $resource = $this->payPlugApiClient->treat($input);

            if ($resource instanceof Payment && $resource->is_paid) {
                $details['status'] = PayPlugApiClientInterface::STATUS_CAPTURED;

                return;
            }

            if ($this->isResourceIsAuthorized($resource)) {
                $details['status'] = PayPlugApiClientInterface::STATUS_AUTHORIZED;

                return;
            }

            if ($resource instanceof \Payplug\Resource\Refund) {
                $metadata = $resource->metadata;
                if (isset($metadata['refund_from_sylius'])) {
                    return;
                }

                $details['status'] = PayPlugApiClientInterface::REFUNDED;
                $refundUnits = $this->refundPaymentHandler->handle($resource, $request->getFirstModel());

                /** @var RefundHistory|null $refundHistory */
                $refundHistory = $this->payplugRefundHistoryRepository->findOneBy(['externalId' => $resource->id]);
                if ($refundHistory instanceof RefundHistory) {
                    return;
                }

                $refundHistory = new RefundHistory();
                $refundHistory
                    ->setExternalId($resource->id)
                    ->setValue($resource->amount)
                    ->setPayment($request->getFirstModel())
                ;

                $this->payplugRefundHistoryRepository->add($refundHistory);
                $this->commandBus->dispatch($refundUnits);

                return;
            }

            $this->logger->info('[PayPlug] Notify action', ['failure' => $resource->failure]);

            $details['failure'] = [
                'code' => $resource->failure->code ?? '',
                'message' => $resource->failure->message ?? '',
            ];
            $details['status'] = PayPlugApiClientInterface::FAILED;
        } catch (\Payplug\Exception\PayplugException $exception) {
            $details['status'] = PayPlugApiClientInterface::FAILED;
            $this->logger->error('[PayPlug] Notify action', ['error' => $exception->getMessage()]);
        }
    }

    public function supports($request): bool
    {
        return
            $request instanceof Notify &&
            $request->getModel() instanceof \ArrayAccess
        ;
    }

    private function isResourceIsAuthorized(IVerifiableAPIResource $resource): bool
    {
        if (!$resource instanceof Payment) {
            return false;
        }

        // Oney is reviewing the payer’s file
        if ($resource->payment_method !== null &&
            $resource->payment_method['is_pending'] === true) {
            return true;
        }

        $now = new \DateTimeImmutable();
        if ($resource->authorization !== null &&
            $resource->authorization->expires_at !== null &&
            $now < $now->setTimestamp($resource->authorization->expires_at)) {
            return true;
        }

        // Maybe other check

        return false;
    }
}
