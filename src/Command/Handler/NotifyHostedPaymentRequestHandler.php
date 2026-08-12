<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Command\Handler;

use PayPlug\SyliusPayPlugPlugin\Command\NotifyHostedPaymentRequest;
use PayplugUnifiedCore\Contracts\IConfigurationRepository;
use PayplugUnifiedCore\Contracts\ILock;
use PayplugUnifiedCore\Contracts\IOrderStateMutator;
use PayplugUnifiedCore\Contracts\IPaymentRepository;
use PayplugUnifiedCore\Exceptions\InvalidNotificationException;
use PayplugUnifiedCore\Utilities\Helpers\WebhookNotificationHelper;
use Psr\Log\LoggerInterface;
use Sylius\Abstraction\StateMachine\StateMachineInterface;
use Sylius\Bundle\PaymentBundle\Provider\PaymentRequestProviderInterface;
use Sylius\Component\Payment\PaymentRequestTransitions;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class NotifyHostedPaymentRequestHandler
{
    // Never currently written by anything in this codebase — no admin form field, OAuth-flow
    // side-effect, or other mechanism calls IConfigurationRepository::set() for this key. Until
    // one does (TBD with the backend team: unclear whether PayPlug returns this during OAuth
    // token exchange or requires separate back-office/admin-field setup), $expectedHeader below
    // always resolves to an empty string and WebhookNotificationHelper::verifySignature() will
    // reject every real webhook notification.
    private const CONFIG_KEY_WEBHOOK_AUTHORIZATION_HEADER = 'payplug_webhook_authorization_header';

    public function __construct(
        private PaymentRequestProviderInterface $paymentRequestProvider,
        private StateMachineInterface $stateMachine,
        private ILock $lock,
        private IPaymentRepository $paymentRepository,
        private IOrderStateMutator $orderStateMutator,
        private IConfigurationRepository $configurationRepository,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(NotifyHostedPaymentRequest $notifyHostedPaymentRequest): void
    {
        $paymentRequest = $this->paymentRequestProvider->provide($notifyHostedPaymentRequest);
        $payload = $paymentRequest->getPayload();
        $rawBody = $payload['http_request']['content'] ?? null; // @phpstan-ignore-line
        $rawHeaders = $payload['http_request']['headers'] ?? null; // @phpstan-ignore-line
        if (!\is_string($rawBody) || !\is_array($rawHeaders)) {
            throw new \LogicException('Invalid UPC notification payload.');
        }
        $headers = $this->flattenHeaders($rawHeaders); // @phpstan-ignore-line

        $lockKey = 'payplug_upc_notify_' . \hash('sha256', $rawBody);
        if (!$this->lock->acquire($lockKey, 30)) {
            // Another delivery of the same webhook is already being processed; tell PayPlug it
            // succeeded so it does not keep retrying.
            $this->stateMachine->apply($paymentRequest, PaymentRequestTransitions::GRAPH, PaymentRequestTransitions::TRANSITION_COMPLETE);

            return;
        }

        try {
            $expectedHeader = $this->configurationRepository->get(self::CONFIG_KEY_WEBHOOK_AUTHORIZATION_HEADER) ?? '';
            $operationData = WebhookNotificationHelper::parse($headers, $rawBody, $expectedHeader);

            $expectedOrderId = (string) $paymentRequest->getPayment()->getId(); // @phpstan-ignore-line
            $expectedAmount = $paymentRequest->getPayment()->getAmount();
            if ($operationData->orderId !== $expectedOrderId || $operationData->amount !== $expectedAmount) {
                $this->logger->error('[PayPlug][UPC] Webhook notification does not match the payment request it was received for.', [
                    'expected_order_id' => $expectedOrderId,
                    'received_order_id' => $operationData->orderId,
                    'expected_amount' => $expectedAmount,
                    'received_amount' => $operationData->amount,
                ]);
                $paymentRequest->setResponseData(['error' => 'Webhook notification does not match the expected payment.']);
                $this->stateMachine->apply($paymentRequest, PaymentRequestTransitions::GRAPH, PaymentRequestTransitions::TRANSITION_FAIL);

                return;
            }

            if ($this->paymentRepository->isTreated($operationData->operationId)) {
                return;
            }

            $this->paymentRepository->save($operationData);
            $this->orderStateMutator->apply($operationData->orderId, $operationData->outcome);
            $this->paymentRepository->markTreated($operationData->operationId);

            $this->stateMachine->apply($paymentRequest, PaymentRequestTransitions::GRAPH, PaymentRequestTransitions::TRANSITION_COMPLETE);
        } catch (InvalidNotificationException $e) {
            $this->logger->error('[PayPlug][UPC] Rejected webhook notification.', ['error' => $e->getMessage()]);
            $paymentRequest->setResponseData(['error' => $e->getMessage()]);
            $this->stateMachine->apply($paymentRequest, PaymentRequestTransitions::GRAPH, PaymentRequestTransitions::TRANSITION_FAIL);
        } finally {
            $this->lock->release($lockKey);
        }
    }

    /**
     * @param array<string, array<int, string>> $rawHeaders
     *
     * @return array<string, string>
     */
    private function flattenHeaders(array $rawHeaders): array
    {
        $headers = [];
        foreach ($rawHeaders as $name => $values) {
            $headers[$name] = $values[0] ?? '';
        }

        return $headers;
    }
}
