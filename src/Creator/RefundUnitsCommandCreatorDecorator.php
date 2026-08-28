<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Creator;

use PayPlug\SyliusPayPlugPlugin\ApiClient\PayPlugApiClientInterface;
use PayPlug\SyliusPayPlugPlugin\Gateway\OneyGatewayFactory;
use PayPlug\SyliusPayPlugPlugin\Gateway\PayPlugGatewayFactory;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Core\Model\PaymentMethodInterface;
use Sylius\Component\Core\Repository\OrderRepositoryInterface;
use Sylius\Component\Payment\Repository\PaymentMethodRepositoryInterface;
use Sylius\RefundPlugin\Command\RefundUnits;
use Sylius\RefundPlugin\Converter\Request\RequestToRefundUnitsConverterInterface;
use Sylius\RefundPlugin\Creator\RequestCommandCreatorInterface;
use Sylius\RefundPlugin\Exception\InvalidRefundAmount;
use Sylius\RefundPlugin\Model\UnitRefundInterface;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\AutowireDecorated;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\Translation\TranslatorInterface;
use Webmozart\Assert\Assert;

#[AsDecorator('sylius_refund.creator.request_command')]
class RefundUnitsCommandCreatorDecorator implements RequestCommandCreatorInterface
{
    private const MINIMUM_REFUND_AMOUNT = 10;

    private const SUPPORTED_METHODS = [
        PayPlugGatewayFactory::FACTORY_NAME,
        OneyGatewayFactory::FACTORY_NAME,
    ];

    public function __construct(
        #[AutowireDecorated]
        private RequestCommandCreatorInterface $decorated,
        #[Autowire('@sylius_refund.converter.request_to_refund_units')]
        private RequestToRefundUnitsConverterInterface $requestToRefundUnitsConverter,
        private PaymentMethodRepositoryInterface $paymentMethodRepository,
        private OrderRepositoryInterface $orderRepository,
        private TranslatorInterface $translator,
        #[Autowire('@payplug_sylius_payplug_plugin.api_client.oney')]
        private PayPlugApiClientInterface $oneyClient,
    ) {
    }

    /**
     * @return RefundUnits
     */
    public function fromRequest(Request $request): object
    {
        Assert::true($request->attributes->has('orderNumber'), 'Refunded order number not provided');
        $units = $this->requestToRefundUnitsConverter->convert($request);
        if ([] === $units) {
            throw InvalidRefundAmount::withValidationConstraint('sylius_refund.at_least_one_unit_should_be_selected_to_refund');
        }

        $paymentMethodId = $request->request->getInt('sylius_refund_payment_method');
        if (0 === $paymentMethodId) {
            return $this->decorated->fromRequest($request); // @phpstan-ignore-line
        }

        /** @var PaymentMethodInterface $paymentMethod */
        $paymentMethod = $this->paymentMethodRepository->find($paymentMethodId);
        $gateway = $paymentMethod->getGatewayConfig();
        if (!\in_array($gateway?->getFactoryName(), self::SUPPORTED_METHODS, true)) {
            return $this->decorated->fromRequest($request); // @phpstan-ignore-line
        }

        if (OneyGatewayFactory::FACTORY_NAME === $gateway->getFactoryName()) {
            $orderNumber = $request->attributes->get('orderNumber');
            Assert::string($orderNumber);

            /** @var OrderInterface|null $order */
            $order = $this->orderRepository->findOneByNumber($orderNumber);
            Assert::isInstanceOf($order, OrderInterface::class);

            $this->canOneyRefundBeMade($order);
        }

        $totalRefundRequest = $this->getTotalRefundAmount($units);
        if ($totalRefundRequest < self::MINIMUM_REFUND_AMOUNT) {
            throw InvalidRefundAmount::withValidationConstraint($this->translator->trans('payplug_sylius_payplug_plugin.ui.refund_minimum_amount_requirement_not_met'));
        }

        return $this->decorated->fromRequest($request); // @phpstan-ignore-line
    }

    /**
     * @param array<UnitRefundInterface> $units
     */
    private function getTotalRefundAmount(array $units): int
    {
        $total = 0;

        foreach ($units as $unit) {
            $total += $unit->total();
        }

        return $total;
    }

    private function canOneyRefundBeMade(OrderInterface $order): void
    {
        $lastPayment = $order->getLastPayment(PaymentInterface::STATE_COMPLETED);
        Assert::isInstanceOf($lastPayment, PaymentInterface::class);

        $data = $this->oneyClient->retrieve($lastPayment->getDetails()['payment_id']);

        $now = new \DateTime();

        if (
            $now->getTimestamp() < $data->refundable_until &&
            $now->getTimestamp() > $data->refundable_after
        ) {
            return;
        }

        throw InvalidRefundAmount::withValidationConstraint($this->translator->trans('payplug_sylius_payplug_plugin.ui.oney_transaction_less_than_forty_eight_hours'));
    }
}
