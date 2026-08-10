<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Command\Handler;

use Payplug\Resource\Payment;
use PayPlug\SyliusPayPlugPlugin\ApiClient\PayPlugApiClientFactoryInterface;
use PayPlug\SyliusPayPlugPlugin\Command\NotifyPaymentRequest;
use PayPlug\SyliusPayPlugPlugin\Gateway\UhfGatewayFactory;
use PayPlug\SyliusPayPlugPlugin\Handler\PaymentNotificationHandler;
use PayPlug\SyliusPayPlugPlugin\Handler\RefundNotificationHandler;
use PayPlug\SyliusPayPlugPlugin\PaymentProcessing\PaymentTransitionApplier;
use PayplugUnifiedCore\Contracts\IConfigurationRepository;
use PayplugUnifiedCore\Contracts\ILock;
use PayplugUnifiedCore\Contracts\IOrderStateMutator;
use PayplugUnifiedCore\Contracts\IPaymentRepository;
use PayplugUnifiedCore\Exceptions\InvalidNotificationException;
use PayplugUnifiedCore\Utilities\Helpers\WebhookNotificationHelper;
use Psr\Log\LoggerInterface;
use Sylius\Abstraction\StateMachine\StateMachineInterface;
use Sylius\Bundle\PaymentBundle\Provider\PaymentRequestProviderInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Payment\Model\PaymentRequestInterface;
use Sylius\Component\Payment\PaymentRequestTransitions;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
class NotifyPaymentRequestHandler
{
    private const LOCK_TTL_SECONDS = 30;

    public function __construct(
        private PaymentRequestProviderInterface $paymentRequestProvider,
        private StateMachineInterface $stateMachine,
        private PayPlugApiClientFactoryInterface $apiClientFactory,
        private PaymentNotificationHandler $paymentNotificationHandler,
        private RefundNotificationHandler $refundNotificationHandler,
        private PaymentTransitionApplier $paymentTransitionApplier,
        private ILock $lock,
        private IPaymentRepository $paymentRepository,
        private IOrderStateMutator $orderStateMutator,
        private IConfigurationRepository $configurationRepository,
        private LoggerInterface $logger,
    ) {}

    public function __invoke(NotifyPaymentRequest $notifyPaymentRequest): void
    {
        $paymentRequest = $this->paymentRequestProvider->provide($notifyPaymentRequest);
        /** @var PaymentInterface $payment */
        $payment = $paymentRequest->getPayment();

        $method = $payment->getMethod();
        if (null !== $method && UhfGatewayFactory::FACTORY_NAME === $method->getGatewayConfig()?->getFactoryName()) {
            $this->notifyViaUnifiedApi($paymentRequest);

            return;
        }

        try {
            $payload = $paymentRequest->getPayload();
            $content = $payload['http_request']['content'] ?? null; // @phpstan-ignore-line
            if (!is_string($content) || '' === $content) {
                throw new \LogicException('Invalid PayPlug notification payload.');
            }

            $method = $payment->getMethod();
            if (null === $method) {
                throw new \LogicException('Payment method is not set for the payment.');
            }

            $client = $this->apiClientFactory->createForPaymentMethod($method);
            $resource = $client->treat($content);

            if ($resource instanceof Payment && $payment->getState() === PaymentInterface::STATE_COMPLETED) {
                // If the payment is already completed, we do not need to update it again
                $this->stateMachine->apply(
                    $paymentRequest,
                    PaymentRequestTransitions::GRAPH,
                    PaymentRequestTransitions::TRANSITION_COMPLETE,
                );

                return;
            }

            $details = new \ArrayObject($payment->getDetails());
            $this->paymentNotificationHandler->treat($payment, $resource, $details);
            $this->refundNotificationHandler->treat($payment, $resource, $details);

            $payment->setDetails($details->getArrayCopy());
            if ($resource instanceof Payment) {
                $this->paymentTransitionApplier->apply($payment);
            }

            $this->stateMachine->apply(
                $paymentRequest,
                PaymentRequestTransitions::GRAPH,
                PaymentRequestTransitions::TRANSITION_COMPLETE,
            );
        } catch (\Throwable $e) {
            $paymentRequest->setResponseData([
                'error' => $e->getMessage(),
            ]);
            $this->stateMachine->apply(
                $paymentRequest,
                PaymentRequestTransitions::GRAPH,
                PaymentRequestTransitions::TRANSITION_FAIL,
            );
        }
    }

    /**
     * Verifies, persists, and applies a UPC Unified API webhook notification. ILock guards against
     * PayPlug retrying the same notification while a first attempt is still in flight; IPaymentRepository's
     * isTreated()/markTreated() guard against re-applying an outcome that already succeeded (the lock alone
     * only prevents concurrent processing, not a later, sequential retry after the first attempt released it).
     */
    private function notifyViaUnifiedApi(PaymentRequestInterface $paymentRequest): void
    {
        $payload = $paymentRequest->getPayload();
        $rawBody = $payload['http_request']['content'] ?? null; // @phpstan-ignore-line
        $rawHeaders = $payload['http_request']['headers'] ?? []; // @phpstan-ignore-line

        if (!\is_string($rawBody) || '' === $rawBody) {
            $this->failNotifyRequest($paymentRequest, 'Invalid UHF notification payload: empty body.');

            return;
        }

        $headers = [];
        foreach ((array) $rawHeaders as $name => $values) {
            $headers[(string) $name] = \is_array($values) ? (string) ($values[0] ?? '') : (string) $values; // @phpstan-ignore-line
        }

        $expectedAuthorizationHeader = $this->configurationRepository->get('payplug_webhook_authorization_header') ?? '';

        try {
            $operationData = WebhookNotificationHelper::parse($headers, $rawBody, $expectedAuthorizationHeader);
        } catch (InvalidNotificationException $exception) {
            $this->logger->error('[PayPlug] UHF webhook notification rejected.', ['error' => $exception->getMessage()]);
            $this->failNotifyRequest($paymentRequest, $exception->getMessage());

            return;
        }

        $lockKey = 'payplug_uhf_operation_' . $operationData->operationId;

        if (!$this->lock->acquire($lockKey, self::LOCK_TTL_SECONDS)) {
            // Another request for the same operation is already being processed concurrently;
            // nothing more to do here — the request that holds the lock will finish the job.
            $this->stateMachine->apply(
                $paymentRequest,
                PaymentRequestTransitions::GRAPH,
                PaymentRequestTransitions::TRANSITION_COMPLETE,
            );

            return;
        }

        try {
            if (!$this->paymentRepository->isTreated($operationData->operationId)) {
                $this->paymentRepository->save($operationData);
                $this->orderStateMutator->apply($operationData->orderId, $operationData->outcome);
                $this->paymentRepository->markTreated($operationData->operationId);
            }
        } finally {
            $this->lock->release($lockKey);
        }

        $this->stateMachine->apply(
            $paymentRequest,
            PaymentRequestTransitions::GRAPH,
            PaymentRequestTransitions::TRANSITION_COMPLETE,
        );
    }

    private function failNotifyRequest(PaymentRequestInterface $paymentRequest, string $message): void
    {
        $paymentRequest->setResponseData(['error' => $message]);
        $this->stateMachine->apply(
            $paymentRequest,
            PaymentRequestTransitions::GRAPH,
            PaymentRequestTransitions::TRANSITION_FAIL,
        );
    }
}
