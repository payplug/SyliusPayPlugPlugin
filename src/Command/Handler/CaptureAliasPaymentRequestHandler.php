<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Command\Handler;

use PayPlug\SyliusPayPlugPlugin\Command\BuildsCommonPaymentContextTrait;
use PayPlug\SyliusPayPlugPlugin\Command\CaptureAliasPaymentRequest;
use PayPlug\SyliusPayPlugPlugin\Command\ResolvesSelectedCardTrait;
use PayPlug\SyliusPayPlugPlugin\Upc\OrderAddressDtoFactory;
use PayPlug\SyliusPayPlugPlugin\Upc\UnifiedApiPaymentCreatorInterface;
use PayplugUnifiedCore\Contracts\IOrderStateMutator;
use PayplugUnifiedCore\Dto\CustomerDto;
use PayplugUnifiedCore\Dto\PaymentDto;
use PayplugUnifiedCore\Exceptions\ApiException;
use PayplugUnifiedCore\Exceptions\InvalidPaymentException;
use PayplugUnifiedCore\Utilities\Helpers\ExecCodeMapper;
use Psr\Log\LoggerInterface;
use Sylius\Abstraction\StateMachine\StateMachineInterface;
use Sylius\Bundle\CoreBundle\OrderPay\Provider\UrlProviderInterface;
use Sylius\Bundle\PaymentBundle\Provider\PaymentRequestProviderInterface;
use Sylius\Component\Payment\PaymentRequestTransitions;
use Sylius\Component\Resource\Repository\RepositoryInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Pays with an already-created alias (a saved Card selected at checkout) instead of a
 * hosted-fields token — the sibling capture path to CaptureHostedPaymentRequestHandler, dispatched
 * by CaptureHostedPaymentRequestCommandProvider when the customer picked a saved card.
 */
#[AsMessageHandler]
final class CaptureAliasPaymentRequestHandler
{
    use BuildsCommonPaymentContextTrait;
    use ResolvesSelectedCardTrait;

    public function __construct(
        private PaymentRequestProviderInterface $paymentRequestProvider,
        private StateMachineInterface $stateMachine,
        private UnifiedApiPaymentCreatorInterface $unifiedApiPaymentCreator,
        private RequestStack $requestStack,
        private RepositoryInterface $payplugCardRepository,
        private LoggerInterface $logger,
        private IOrderStateMutator $orderStateMutator,
        private UrlGeneratorInterface $urlGenerator,
        // Builds the successUrl/cancelUrl sent to the Unified API, so a 3DS/SCA challenge returns
        // the shopper to this same /pay page afterwards.
        #[Autowire(service: 'sylius_shop.provider.order_pay.after_pay_url')] // @phpstan-ignore-line
        private UrlProviderInterface $afterPayUrlProvider,
        private OrderAddressDtoFactory $orderAddressDtoFactory,
    ) {
    }

    public function __invoke(CaptureAliasPaymentRequest $captureAliasPaymentRequest): void
    {
        $paymentRequest = $this->paymentRequestProvider->provide($captureAliasPaymentRequest);
        /** @var \Sylius\Component\Core\Model\PaymentInterface $payment */
        $payment = $paymentRequest->getPayment();
        $method = $payment->getMethod();
        if (null === $method) {
            throw new \LogicException('Payment method is not set for the payment.');
        }

        $card = $this->resolveSelectedCard();
        if (null === $card) {
            throw new \LogicException('No saved card alias selected for the payment.');
        }

        $amount = $payment->getAmount();
        $currencyCode = $payment->getCurrencyCode();
        if (null === $amount || null === $currencyCode) {
            throw new \LogicException('Payment amount or currency is not set.');
        }

        [$accountId, $submerchantExternalId] = $this->resolveGatewayCredentials($method);

        try {
            $order = $payment->getOrder();

            if ($card->getCustomer() !== $order?->getCustomer() || $card->getPaymentMethod() !== $method) {
                throw new \LogicException('Selected card does not belong to the paying customer or payment method.');
            }

            // $order is provably non-null here: Card::getCustomer() never returns null, so the
            // check above already threw if $order (and thus $order?->getCustomer()) were null.
            $orderId = $order->getNumber() ?? self::idToString($payment->getId());

            $common = $this->buildCommonFields($accountId, $amount, $currencyCode, $orderId, $submerchantExternalId, $paymentRequest, $order);

            $customer = $order->getCustomer();
            if (null === $customer->getEmail()) {
                throw new \LogicException('Customer email is not set for the payment.');
            }
            $customerDto = new CustomerDto(self::idToString($customer->getId()), $customer->getEmail());

            $browserDto = $this->buildBrowserDto();

            $fullName = $order->getBillingAddress()?->getFullName();
            $paymentMethod = null !== $fullName && '' !== $fullName
                ? ['details' => ['fullName' => $fullName]]
                : null;

            $dto = new PaymentDto($common, $card->getExternalId(), 'ONE_CLICK', $browserDto, $customerDto, $paymentMethod);

            $output = $this->unifiedApiPaymentCreator->createPayment($dto);
        } catch (ApiException | InvalidPaymentException | \LogicException $e) {
            $this->logger->error('[PayPlug][UPC] Alias payment creation failed.', [
                'sylius_payment_id' => $payment->getId(),
                'error' => $e->getMessage(),
            ]);
            $paymentRequest->setResponseData(['error' => $e->getMessage()]);
            $this->stateMachine->apply($paymentRequest, PaymentRequestTransitions::GRAPH, PaymentRequestTransitions::TRANSITION_FAIL);

            return;
        }

        $payment->setDetails([
            ...$payment->getDetails(),
            'alias_id' => $card->getExternalId(),
            'alias_payment_created_at' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
        ]);

        if (null !== $output->redirectHtml) {
            $paymentRequest->setResponseData(['redirect_html' => $output->redirectHtml]);
        } elseif (null !== $output->redirectUrl) {
            $paymentRequest->setResponseData(['redirect_url' => $output->redirectUrl]);
        } else {
            $paymentRequest->setResponseData(['status' => $output->status]);

            $responseBody = \json_decode($output->body, true);
            $execCode = \is_array($responseBody) ? ($responseBody['execCode'] ?? null) : null;
            if (\is_string($execCode)) {
                $this->orderStateMutator->apply(self::idToString($payment->getId()), ExecCodeMapper::toPaymentOutcome($execCode));
            }
        }

        $this->stateMachine->apply($paymentRequest, PaymentRequestTransitions::GRAPH, PaymentRequestTransitions::TRANSITION_COMPLETE);
    }
}
