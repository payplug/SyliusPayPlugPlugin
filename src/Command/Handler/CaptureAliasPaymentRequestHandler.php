<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Command\Handler;

use PayPlug\SyliusPayPlugPlugin\Command\CaptureAliasPaymentRequest;
use PayPlug\SyliusPayPlugPlugin\Command\PaymentCaptureFlow;
use PayPlug\SyliusPayPlugPlugin\Entity\Card;
use PayPlug\SyliusPayPlugPlugin\Resolver\SelectedCardResolver;
use PayPlug\SyliusPayPlugPlugin\Upc\PaymentCaptureContextBuilder;
use PayPlug\SyliusPayPlugPlugin\Upc\PaymentCaptureOutcomeApplier;
use PayPlug\SyliusPayPlugPlugin\Upc\UnifiedApiPaymentCreatorInterface;
use PayplugUnifiedCore\Dto\CommonFieldsDto;
use PayplugUnifiedCore\Dto\PaymentDto;
use PayplugUnifiedCore\Exceptions\ApiException;
use PayplugUnifiedCore\Exceptions\InvalidPaymentException;
use Sylius\Abstraction\StateMachine\StateMachineInterface;
use Sylius\Bundle\PaymentBundle\Provider\PaymentRequestProviderInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Payment\Model\PaymentMethodInterface;
use Sylius\Component\Payment\PaymentRequestTransitions;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Pays with an already-created alias (a saved Card selected at checkout) instead of a
 * hosted-fields token — the sibling capture path to CaptureHostedPaymentRequestHandler, dispatched
 * by CaptureHostedPaymentRequestCommandProvider when the customer picked a saved card.
 */
#[AsMessageHandler]
final class CaptureAliasPaymentRequestHandler
{
    public function __construct(
        private PaymentRequestProviderInterface $paymentRequestProvider,
        private StateMachineInterface $stateMachine,
        private UnifiedApiPaymentCreatorInterface $unifiedApiPaymentCreator,
        private SelectedCardResolver $selectedCardResolver,
        private PaymentCaptureContextBuilder $contextBuilder,
        private PaymentCaptureOutcomeApplier $outcomeApplier,
    ) {
    }

    public function __invoke(CaptureAliasPaymentRequest $captureAliasPaymentRequest): void
    {
        $paymentRequest = $this->paymentRequestProvider->provide($captureAliasPaymentRequest);
        /** @var PaymentInterface $payment */
        $payment = $paymentRequest->getPayment();

        try {
            $method = $this->contextBuilder->resolvePaymentMethod($payment);

            $card = $this->selectedCardResolver->resolve();
            if (null === $card) {
                throw new \LogicException('No saved card alias selected for the payment.');
            }
            [$amount, $currencyCode] = $this->contextBuilder->resolveAmountAndCurrency($payment);
            [$accountId, $submerchantExternalId] = $this->contextBuilder->resolveGatewayCredentials($method);

            $order = $this->assertCardBelongsToOrder($card, $payment->getOrder(), $method);
            $common = $this->contextBuilder->buildCommonFields($accountId, $amount, $currencyCode, $submerchantExternalId, $paymentRequest, $order);
            $dto = $this->buildPaymentDto($common, $card, $order);

            $output = $this->unifiedApiPaymentCreator->createPayment($dto);
        } catch (ApiException | InvalidPaymentException | \LogicException $e) {
            $this->outcomeApplier->failPaymentRequest($paymentRequest, $payment, $e, PaymentCaptureFlow::Alias);

            return;
        }

        $payment->setDetails([
            ...$payment->getDetails(),
            'alias_id' => $card->getExternalId(),
            'alias_payment_created_at' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            ...$this->contextBuilder->resolveHostedFieldsIds($output->body),
        ]);

        $this->outcomeApplier->applyOutcome($paymentRequest, $payment, $output);

        $this->stateMachine->apply($paymentRequest, PaymentRequestTransitions::GRAPH, PaymentRequestTransitions::TRANSITION_COMPLETE);
    }

    private function assertCardBelongsToOrder(
        Card $card,
        ?OrderInterface $order,
        PaymentMethodInterface $method,
    ): OrderInterface {
        if (null === $order || $card->getCustomer() !== $order->getCustomer() || $card->getPaymentMethod() !== $method) {
            throw new \LogicException('Selected card does not belong to the paying customer or payment method.');
        }

        return $order;
    }

    private function buildPaymentDto(CommonFieldsDto $common, Card $card, OrderInterface $order): PaymentDto
    {
        $customerDto = $this->contextBuilder->buildCustomerDto($order);
        $browserDto = $this->contextBuilder->buildBrowserDto();

        $fullName = $this->contextBuilder->resolveFullNameForCardDetails($order);
        $paymentMethod = null !== $fullName
            ? ['details' => ['fullName' => $fullName]]
            : null;

        return new PaymentDto($common, $card->getExternalId(), 'ONE_CLICK', $browserDto, $customerDto, $paymentMethod);
    }
}
