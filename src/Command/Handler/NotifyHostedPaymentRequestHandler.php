<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Command\Handler;

use PayPlug\SyliusPayPlugPlugin\Command\NotifyHostedPaymentRequest;
use PayplugUnifiedCore\Contracts\IConfigurationRepository;
use PayplugUnifiedCore\Contracts\ILock;
use PayplugUnifiedCore\Contracts\IOrderStateMutator;
use PayplugUnifiedCore\Contracts\IPaymentRepository;
use PayplugUnifiedCore\DataValues\OperationData;
use PayplugUnifiedCore\DataValues\PaymentOutcome;
use PayplugUnifiedCore\Exceptions\InvalidNotificationException;
use PayplugUnifiedCore\Utilities\Helpers\WebhookNotificationHelper;
use Psr\Log\LoggerInterface;
use Sylius\Abstraction\StateMachine\StateMachineInterface;
use Sylius\Bundle\PaymentBundle\Provider\PaymentRequestProviderInterface;
use Sylius\Component\Payment\Model\PaymentRequestInterface;
use Sylius\Component\Payment\PaymentRequestTransitions;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class NotifyHostedPaymentRequestHandler
{
    // No admin field or other mechanism currently writes this key, so $expectedHeader below
    // always resolves to an empty string and WebhookNotificationHelper::verifySignature() accepts
    // every notification unverified — see that method's docblock for why that's intentional (no
    // merchant/account of ours has a way to configure a webhook secret at all). Kept, rather than
    // removed, so a real secret is still enforced if one ever becomes configurable. The
    // orderId/amount cross-check below is this handler's actual protection in the meantime.
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

            if (!$this->matchesPaymentRequest($paymentRequest, $operationData)) {
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

    // Split out of __invoke() to keep its own return count within SonarCloud's limit (php:S1142)
    // — both branches here mean "nothing to apply," they just differ in whether that's expected
    // (still-pending) or a problem worth logging and failing the request over (mismatch).
    private function matchesPaymentRequest(PaymentRequestInterface $paymentRequest, OperationData $operationData): bool
    {
        if (PaymentOutcome::THREE_DS_PENDING === $operationData->outcome) {
            // Not a final outcome — leave the payment request as-is and, crucially, do not
            // touch isTreated()/markTreated(): a later, final notification for this same
            // operation must still be free to apply once it arrives. Mirrors the same guard
            // in HostedFieldsWebhookNotificationHandler::treat().
            return false;
        }

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

            return false;
        }

        return true;
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
