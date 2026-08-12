<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Command\Handler;

use PayPlug\SyliusPayPlugPlugin\Command\BuildsCommonPaymentContextTrait;
use PayPlug\SyliusPayPlugPlugin\Command\CaptureHostedPaymentRequest;
use PayPlug\SyliusPayPlugPlugin\Entity\Card;
use PayPlug\SyliusPayPlugPlugin\Upc\OperationStatusFetcherInterface;
use PayPlug\SyliusPayPlugPlugin\Upc\OrderAddressDtoFactory;
use PayPlug\SyliusPayPlugPlugin\Upc\UnifiedApiPaymentCreatorInterface;
use PayplugUnifiedCore\Contracts\IOrderStateMutator;
use PayplugUnifiedCore\Dto\CustomerDto;
use PayplugUnifiedCore\Dto\HostedFieldDto;
use PayplugUnifiedCore\Exceptions\ApiException;
use PayplugUnifiedCore\Exceptions\InvalidHostedFieldException;
use PayplugUnifiedCore\Output\PaymentOutput;
use PayplugUnifiedCore\Utilities\Helpers\ExecCodeMapper;
use Psr\Log\LoggerInterface;
use Sylius\Abstraction\StateMachine\StateMachineInterface;
use Sylius\Bundle\CoreBundle\OrderPay\Provider\UrlProviderInterface;
use Sylius\Bundle\PaymentBundle\Provider\PaymentRequestProviderInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Core\Model\PaymentMethodInterface as CorePaymentMethodInterface;
use Sylius\Component\Payment\Model\PaymentMethodInterface;
use Sylius\Component\Payment\Model\PaymentRequestInterface;
use Sylius\Component\Payment\PaymentRequestTransitions;
use Sylius\Component\Resource\Factory\FactoryInterface;
use Sylius\Component\Resource\Repository\RepositoryInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

#[AsMessageHandler]
final class CaptureHostedPaymentRequestHandler
{
    use BuildsCommonPaymentContextTrait;

    public function __construct(
        private PaymentRequestProviderInterface $paymentRequestProvider,
        private StateMachineInterface $stateMachine,
        private UnifiedApiPaymentCreatorInterface $unifiedApiPaymentCreator,
        private OperationStatusFetcherInterface $operationStatusFetcher,
        // Builds the successUrl/cancelUrl sent to the Unified API, so a 3DS/SCA challenge returns
        // the shopper to this same /pay page afterwards.
        #[Autowire(service: 'sylius_shop.provider.order_pay.after_pay_url')] // @phpstan-ignore-line
        private UrlProviderInterface $afterPayUrlProvider,
        private UrlGeneratorInterface $urlGenerator,
        private LoggerInterface $logger,
        private RequestStack $requestStack,
        private IOrderStateMutator $orderStateMutator,
        private FactoryInterface $payplugCardFactory,
        private RepositoryInterface $payplugCardRepository,
        private OrderAddressDtoFactory $orderAddressDtoFactory,
    ) {
    }

    public function __invoke(CaptureHostedPaymentRequest $captureHostedPaymentRequest): void
    {
        $paymentRequest = $this->paymentRequestProvider->provide($captureHostedPaymentRequest);
        /** @var PaymentInterface $payment */
        $payment = $paymentRequest->getPayment();
        $details = $payment->getDetails();

        try {
            $method = $this->resolvePaymentMethod($payment);
            $hfToken = $this->resolveHostedFieldsToken($details);
            $amountAndCurrency = $this->resolveAmountAndCurrency($payment);
            $credentials = $this->resolveGatewayCredentials($method);

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
            $this->failPaymentRequest($paymentRequest, $payment, $e);

            return;
        }

        $hostedFieldsIds = $this->resolveHostedFieldsIds($output->body);
        $payment->setDetails([
            ...$details,
            'hosted_fields_created_at' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            ...$hostedFieldsIds,
        ]);

        $this->applyOutcome($paymentRequest, $payment, $output);

        $saveCard = true === ($details['hosted_fields_save_card'] ?? false);
        if ($saveCard && null !== $output->aliasId) {
            $this->persistCard($output->aliasId, $payment, $method, $details, $hostedFieldsIds['hosted_fields_operation_id'] ?? null);
        }

        $this->stateMachine->apply($paymentRequest, PaymentRequestTransitions::GRAPH, PaymentRequestTransitions::TRANSITION_COMPLETE);
    }

    private function resolvePaymentMethod(PaymentInterface $payment): PaymentMethodInterface
    {
        $method = $payment->getMethod();
        if (null === $method) {
            throw new \LogicException('Payment method is not set for the payment.');
        }

        return $method;
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
     * Extracted from the creation response body rather than PaymentOutput itself (which
     * carries no such fields) — needed by StatusHostedPaymentRequestHandler's 3DS polling
     * fallback and consistent with the ids the webhook path resolves against.
     *
     * @return array{hosted_fields_payment_id?: string, hosted_fields_operation_id?: string}
     */
    private function resolveHostedFieldsIds(string $body): array
    {
        $decoded = \json_decode($body, true);
        $paymentId = \is_array($decoded) ? ($decoded['id'] ?? null) : null;
        $operationIds = \is_array($decoded) ? ($decoded['operationIds'] ?? null) : null;
        $operationId = \is_array($operationIds) ? ($operationIds[0] ?? null) : null;

        // Each id is stored independently — a response carrying only one of the two (e.g. no
        // operationIds yet) must not also drop the other, since dropping hosted_fields_operation_id
        // silently disables persistCard()'s card-metadata enrichment below.
        $result = [];
        if (\is_string($paymentId) && '' !== $paymentId) {
            $result['hosted_fields_payment_id'] = $paymentId;
        }
        if (\is_string($operationId) && '' !== $operationId) {
            $result['hosted_fields_operation_id'] = $operationId;
        }

        return $result;
    }

    /** @return array{0: int, 1: string} */
    private function resolveAmountAndCurrency(PaymentInterface $payment): array
    {
        $amount = $payment->getAmount();
        $currencyCode = $payment->getCurrencyCode();
        if (null === $amount || null === $currencyCode) {
            throw new \LogicException('Payment amount or currency is not set.');
        }

        return [$amount, $currencyCode];
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
        $orderId = $order?->getNumber() ?? self::idToString($payment->getId());

        $common = $this->buildCommonFields($accountId, $amount, $currencyCode, $orderId, $submerchantExternalId, $paymentRequest, $order);

        $selectedBrand = $details['hosted_fields_selected_brand'] ?? null;
        $hasSelectedBrand = \is_string($selectedBrand) && '' !== $selectedBrand;
        $fullName = $order?->getBillingAddress()?->getFullName();

        $saveCard = true === ($details['hosted_fields_save_card'] ?? false);
        $recurringMode = $saveCard ? 'ONE_CLICK' : null;

        $cardDetails = [];
        if (null !== $fullName && '' !== $fullName) {
            $cardDetails['fullName'] = $fullName;
        }
        if ($hasSelectedBrand) {
            $cardDetails['selectedBrand'] = $selectedBrand;
        }

        /** @var array{details?: array{fullName?: string, selectedBrand?: string}, saveFutureUsage?: bool}|null $paymentMethodDetails */
        $paymentMethodDetails = match (true) {
            [] !== $cardDetails && $saveCard => ['details' => $cardDetails, 'saveFutureUsage' => true],
            [] !== $cardDetails => ['details' => $cardDetails],
            $saveCard => ['saveFutureUsage' => true],
            default => null,
        };

        // Named arguments here (rather than positional) because HostedFieldDto's real
        // constructor interposes $recurringMode between $hfToken and $browser/$customer —
        // positional args would silently misalign.
        return new HostedFieldDto(
            $common,
            $hfToken,
            recurringMode: $recurringMode,
            browser: $this->buildBrowserDto(),
            customer: $this->buildCustomerDto($order),
            paymentMethod: $paymentMethodDetails,
        );
    }

    private function buildCustomerDto(?OrderInterface $order): CustomerDto
    {
        $customer = $order?->getCustomer();
        if (null === $customer || null === $customer->getEmail()) {
            throw new \LogicException('Customer email is not set for the payment.');
        }

        return new CustomerDto(self::idToString($customer->getId()), $customer->getEmail());
    }

    private function failPaymentRequest(
        PaymentRequestInterface $paymentRequest,
        PaymentInterface $payment,
        \Throwable $e,
    ): void {
        $this->logger->error('[PayPlug][UPC] Hosted payment creation failed.', [
            'sylius_payment_id' => $payment->getId(),
            'error' => $e->getMessage(),
        ]);
        $paymentRequest->setResponseData(['error' => $e->getMessage()]);
        $this->stateMachine->apply($paymentRequest, PaymentRequestTransitions::GRAPH, PaymentRequestTransitions::TRANSITION_FAIL);
    }

    private function applyOutcome(
        PaymentRequestInterface $paymentRequest,
        PaymentInterface $payment,
        PaymentOutput $output,
    ): void {
        if (null !== $output->redirectHtml) {
            // The "recommended for web" 3DS-pending shape — an auto-submitting HTML form the
            // browser must render directly (see CaptureHttpResponseProvider). This is what the
            // Unified API actually returns by default; redirectUrl only appears when the request
            // explicitly set card.threeDSecure.displayMode=raw, which this plugin never does.
            $paymentRequest->setResponseData(['redirect_html' => $output->redirectHtml]);

            return;
        }

        if (null !== $output->redirectUrl) {
            $paymentRequest->setResponseData(['redirect_url' => $output->redirectUrl]);

            return;
        }

        $paymentRequest->setResponseData(['status' => $output->status]);

        // No 3DS redirect means the outcome is already known synchronously — apply it to the
        // actual Sylius Payment right away instead of waiting on the async webhook, which may
        // be delayed or, in this test environment, never arrive at all. SyliusOrderStateMutator
        // is idempotent (checks the state machine before transitioning), so it's safe to also
        // run again if/when the webhook (NotifyHostedPaymentRequestHandler) eventually shows up.
        $responseBody = \json_decode($output->body, true);
        $execCode = \is_array($responseBody) ? ($responseBody['execCode'] ?? null) : null;
        if (\is_string($execCode)) {
            $this->orderStateMutator->apply(self::idToString($payment->getId()), ExecCodeMapper::toPaymentOutcome($execCode));
        }
    }

    private function persistCard(
        string $aliasId,
        PaymentInterface $payment,
        PaymentMethodInterface $method,
        array $details,
        ?string $unifiedApiOperationId,
    ): void {
        $order = $payment->getOrder();

        $customer = $order?->getCustomer();
        if (null === $customer) {
            return;
        }

        // Payment::getMethod() is only typed to the base Payment component's PaymentMethodInterface,
        // but Card::$paymentMethod is a mandatory (non-nullable) association requiring Sylius
        // Core's narrower PaymentMethodInterface (the only kind Sylius actually wires up at
        // runtime) — bail out before building a Card at all rather than persisting one with that
        // required field left unset, which would only fail later at flush time.
        if (!$method instanceof CorePaymentMethodInterface) {
            return;
        }

        $gatewayConfig = $method->getGatewayConfig()?->getConfig() ?? [];

        $brand = $details['hosted_fields_selected_brand'] ?? null;
        $last4 = $details['hosted_fields_last4'] ?? null;
        $expirationMonth = $details['hosted_fields_expiration_month'] ?? null;
        $expirationYear = $details['hosted_fields_expiration_year'] ?? null;
        $countryCode = $details['hosted_fields_country'] ?? null;

        if (null !== $unifiedApiOperationId) {
            $fetchedCardData = $this->fetchCardDataFromUnifiedApi($unifiedApiOperationId);
            $brand = $fetchedCardData['brand'] ?? $brand;
            $last4 = $fetchedCardData['last4'] ?? $last4;
            $expirationMonth = $fetchedCardData['expirationMonth'] ?? $expirationMonth;
            $expirationYear = $fetchedCardData['expirationYear'] ?? $expirationYear;
            // No card country field exists on the operation resource (confirmed against a real
            // staging response) — $countryCode keeps relying entirely on the client-submitted
            // $details value.
        }

        /** @var Card $card */
        $card = $this->payplugCardFactory->createNew();
        $card
            ->setCustomer($customer)
            ->setExternalId($aliasId)
            ->setBrand(\is_string($brand) ? $brand : '')
            ->setLast4(\is_string($last4) ? $last4 : '')
            ->setExpirationMonth(\is_int($expirationMonth) ? $expirationMonth : 0)
            ->setExpirationYear(\is_int($expirationYear) ? $expirationYear : 0)
            ->setCountryCode(\is_string($countryCode) ? $countryCode : '')
            ->setIsLive(true === ($gatewayConfig['live'] ?? false))
            ->setPaymentMethod($method)
        ;

        $this->payplugCardRepository->add($card);
    }

    /**
     * The Unified API's alias-creation response carries only the alias id, no card metadata — the
     * card's real brand/last4/expiration only show up on the operation resource, fetched here via
     * the existing OperationStatusFetcherInterface (already used by StatusHostedPaymentRequestHandler
     * for its 3DS polling fallback) rather than a second, duplicate client — confirmed against a
     * real staging response:
     * paymentMethod.card.{network, code6x4} ("VISA", a masked PAN "424242XXXXXX4242") and
     * paymentMethod.details.{selectedBrand, validityDate} ("VISA", "2027-12" — YYYY-MM). last4
     * isn't its own field — it's derived from code6x4's last 4 characters. No card country field
     * exists anywhere on this response, so it's never enriched here — persistCard() keeps relying
     * entirely on the client-submitted $details value for it. Best-effort throughout: any failure
     * (API error, unexpected shape) is logged and swallowed rather than propagated, since this
     * only enriches the card being persisted — persistCard()'s own $details-based fallback values
     * already cover the case where this fetch fails or a field turns out to be absent.
     *
     * @return array{brand?: string, last4?: string, expirationMonth?: int, expirationYear?: int}
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

        $decoded = \json_decode($response['body'], true);
        $paymentMethod = \is_array($decoded) ? ($decoded['paymentMethod'] ?? null) : null;
        $card = \is_array($paymentMethod) ? ($paymentMethod['card'] ?? null) : null;
        $cardDetails = \is_array($paymentMethod) ? ($paymentMethod['details'] ?? null) : null;

        $result = [];

        if (\is_array($card)) {
            $network = $card['network'] ?? null;
            if (\is_string($network) && '' !== $network) {
                $result['brand'] = $network;
            }

            $code6x4 = $card['code6x4'] ?? null;
            if (\is_string($code6x4) && \strlen($code6x4) >= 4) {
                $result['last4'] = \substr($code6x4, -4);
            }
        }

        if (\is_array($cardDetails)) {
            $selectedBrand = $cardDetails['selectedBrand'] ?? null;
            if (!isset($result['brand']) && \is_string($selectedBrand) && '' !== $selectedBrand) {
                $result['brand'] = $selectedBrand;
            }

            $validityDate = $cardDetails['validityDate'] ?? null;
            if (\is_string($validityDate) && 1 === \preg_match('/^(\d{4})-(\d{2})$/', $validityDate, $matches)) {
                $result['expirationYear'] = (int) $matches[1];
                $result['expirationMonth'] = (int) $matches[2];
            }
        }

        return $result;
    }
}
