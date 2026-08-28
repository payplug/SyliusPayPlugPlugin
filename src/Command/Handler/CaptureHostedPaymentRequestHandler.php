<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Command\Handler;

use PayPlug\SyliusPayPlugPlugin\Command\CaptureHostedPaymentRequest;
use PayPlug\SyliusPayPlugPlugin\Command\PaymentCaptureFlow;
use PayPlug\SyliusPayPlugPlugin\Upc\CardDataFromPaymentMethodExtractor;
use PayPlug\SyliusPayPlugPlugin\Upc\OperationStatusFetcherInterface;
use PayPlug\SyliusPayPlugPlugin\Upc\PaymentCaptureContextBuilder;
use PayPlug\SyliusPayPlugPlugin\Upc\PaymentCaptureOutcomeApplier;
use PayPlug\SyliusPayPlugPlugin\Upc\PayplugCardPersister;
use PayPlug\SyliusPayPlugPlugin\Upc\UnifiedApiPaymentCreatorInterface;
use PayplugUnifiedCore\Dto\HostedFieldDto;
use PayplugUnifiedCore\Exceptions\ApiException;
use PayplugUnifiedCore\Exceptions\InvalidHostedFieldException;
use Psr\Log\LoggerInterface;
use Sylius\Abstraction\StateMachine\StateMachineInterface;
use Sylius\Bundle\PaymentBundle\Provider\PaymentRequestProviderInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Payment\Model\PaymentRequestInterface;
use Sylius\Component\Payment\PaymentRequestTransitions;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class CaptureHostedPaymentRequestHandler
{
    public function __construct(
        private PaymentRequestProviderInterface $paymentRequestProvider,
        private StateMachineInterface $stateMachine,
        private UnifiedApiPaymentCreatorInterface $unifiedApiPaymentCreator,
        private OperationStatusFetcherInterface $operationStatusFetcher,
        private PaymentCaptureContextBuilder $contextBuilder,
        private PaymentCaptureOutcomeApplier $outcomeApplier,
        private PayplugCardPersister $cardPersister,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(CaptureHostedPaymentRequest $captureHostedPaymentRequest): void
    {
        $paymentRequest = $this->paymentRequestProvider->provide($captureHostedPaymentRequest);
        /** @var PaymentInterface $payment */
        $payment = $paymentRequest->getPayment();
        $details = $payment->getDetails();

        try {
            $method = $this->contextBuilder->resolvePaymentMethod($payment);
            $hfToken = $this->resolveHostedFieldsToken($details);
            $amountAndCurrency = $this->contextBuilder->resolveAmountAndCurrency($payment);
            $credentials = $this->contextBuilder->resolveGatewayCredentials($method);

            $dto = $this->buildHostedFieldDto(
                $paymentRequest,
                $payment,
                $details,
                $hfToken,
                $amountAndCurrency,
                $credentials,
            );

            $this->logger->debug('[PayPlug debug] Unified API hosted payment request payload.', [
                'payload' => $dto->createPayloadBody(),
            ]);

            $output = $this->unifiedApiPaymentCreator->createPayment($dto);
        } catch (ApiException | InvalidHostedFieldException | \LogicException $e) {
            $this->outcomeApplier->failPaymentRequest($paymentRequest, $payment, $e, PaymentCaptureFlow::Hosted);

            return;
        }

        $hostedFieldsIds = $this->contextBuilder->resolveHostedFieldsIds($output->body);
        $payment->setDetails([
            ...$details,
            'hosted_fields_created_at' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            ...$hostedFieldsIds,
        ]);

        $this->outcomeApplier->applyOutcome($paymentRequest, $payment, $output);

        $saveCard = true === ($details['hosted_fields_save_card'] ?? false);
        if ($saveCard && null !== $output->aliasId) {
            $unifiedApiOperationId = $hostedFieldsIds['hosted_fields_operation_id'] ?? null;
            $fetchedCardData = null !== $unifiedApiOperationId ? $this->fetchCardDataFromUnifiedApi($unifiedApiOperationId) : [];
            $this->cardPersister->persist($output->aliasId, $payment, $method, $details, $fetchedCardData);
        }

        $this->stateMachine->apply($paymentRequest, PaymentRequestTransitions::GRAPH, PaymentRequestTransitions::TRANSITION_COMPLETE);
    }

    /** @param mixed[] $details */
    private function resolveHostedFieldsToken(array $details): string
    {
        $hfToken = $details['hosted_fields_token'] ?? null;
        if (!\is_string($hfToken) || '' === $hfToken) {
            throw new \LogicException('No hosted fields token found on the payment.');
        }

        return $hfToken;
    }

    /**
     * @param mixed[] $details
     * @param array{0: int, 1: string} $amountAndCurrency
     * @param array{0: string, 1: string} $credentials
     */
    private function buildHostedFieldDto(
        PaymentRequestInterface $paymentRequest,
        PaymentInterface $payment,
        array $details,
        string $hfToken,
        array $amountAndCurrency,
        array $credentials,
    ): HostedFieldDto {
        [$amount, $currencyCode] = $amountAndCurrency;
        [$accountId, $submerchantExternalId] = $credentials;

        $order = $payment->getOrder();

        $common = $this->contextBuilder->buildCommonFields($accountId, $amount, $currencyCode, $submerchantExternalId, $paymentRequest, $order);

        $selectedBrand = $details['hosted_fields_selected_brand'] ?? null;
        $hasSelectedBrand = \is_string($selectedBrand) && '' !== $selectedBrand;
        $fullName = $this->contextBuilder->resolveFullNameForCardDetails($order);
        $hasFullName = null !== $fullName;

        $saveCard = true === ($details['hosted_fields_save_card'] ?? false);
        $recurringMode = $saveCard ? 'ONE_CLICK' : null;

        $cardDetails = [];
        if ($hasFullName) {
            $cardDetails['fullName'] = $fullName;
        }
        if ($hasSelectedBrand) {
            $cardDetails['selectedBrand'] = $selectedBrand;
        }

        // saveFutureUsage is only requested alongside a fullName: the Unified API rejects an
        // alias-creation request (paymentMethod.saveFutureUsage: true) missing
        // paymentMethod.details.fullName, so requesting it without one would fail the entire
        // payment rather than just skip saving the card.
        /** @var array{details?: array{fullName?: string, selectedBrand?: string}, saveFutureUsage?: bool}|null $paymentMethodDetails */
        $paymentMethodDetails = match (true) {
            [] !== $cardDetails && $saveCard && $hasFullName => ['details' => $cardDetails, 'saveFutureUsage' => true],
            [] !== $cardDetails => ['details' => $cardDetails],
            default => null,
        };

        // Named arguments here (rather than positional) because HostedFieldDto's real
        // constructor interposes $recurringMode between $hfToken and $browser/$customer —
        // positional args would silently misalign.
        return new HostedFieldDto(
            $common,
            $hfToken,
            recurringMode: $recurringMode,
            browser: $this->contextBuilder->buildBrowserDto(),
            customer: $this->contextBuilder->buildCustomerDto($order),
            paymentMethod: $paymentMethodDetails,
        );
    }

    /**
     * The Unified API's alias-creation response carries only the alias id, no card metadata — the
     * card's real brand/last4/expiration only show up on the operation resource, fetched here via
     * the existing OperationStatusFetcherInterface (already used by StatusHostedPaymentRequestHandler
     * for its 3DS polling fallback) rather than a second, duplicate client. Best-effort: any
     * failure (API error, unexpected shape) is logged and swallowed rather than propagated, since
     * this only enriches the card being persisted — PayplugCardPersister::persist()'s own
     * $details-based fallback values already cover the case where this fetch fails or a field
     * turns out to be absent.
     *
     * @return array{aliasId?: string, brand?: string, last4?: string, expirationMonth?: int, expirationYear?: int}
     */
    private function fetchCardDataFromUnifiedApi(string $operationId): array
    {
        try {
            $response = $this->operationStatusFetcher->getOperation($operationId);
        } catch (ApiException $e) {
            $this->logger->error('[PayPlug][UPC] Failed to fetch operation for card metadata.', [
                'unified_api_operation_id' => $operationId,
                'error' => $e->getMessage(),
            ]);

            return [];
        }

        return CardDataFromPaymentMethodExtractor::extract($response['body']);
    }
}
