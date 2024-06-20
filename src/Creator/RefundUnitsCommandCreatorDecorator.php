<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Creator;

use PayPlug\SyliusPayPlugPlugin\ApiClient\PayPlugApiClientInterface;
use PayPlug\SyliusPayPlugPlugin\Gateway\OneyGatewayFactory;
use PayPlug\SyliusPayPlugPlugin\Gateway\PayPlugGatewayFactory;
use Payum\Core\Model\GatewayConfigInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Core\Model\PaymentMethodInterface;
use Sylius\Component\Core\Repository\OrderRepositoryInterface;
use Sylius\Component\Payment\Repository\PaymentMethodRepositoryInterface;
use Sylius\RefundPlugin\Command\RefundUnits;
use Sylius\RefundPlugin\Converter\RefundUnitsConverterInterface;
use Sylius\RefundPlugin\Converter\Request\RequestToRefundUnitsConverterInterface;
use Sylius\RefundPlugin\Creator\RequestCommandCreatorInterface;
use Sylius\RefundPlugin\Exception\InvalidRefundAmount;
use Sylius\RefundPlugin\Model\OrderItemUnitRefund;
use Sylius\RefundPlugin\Model\RefundType;
use Sylius\RefundPlugin\Model\ShipmentRefund;
use Sylius\RefundPlugin\Model\UnitRefundInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\Translation\TranslatorInterface;
use Webmozart\Assert\Assert;

class RefundUnitsCommandCreatorDecorator implements RequestCommandCreatorInterface
{
    private const MINIMUM_REFUND_AMOUNT = 10;

    /** @var RequestCommandCreatorInterface */
    private $decorated;

    /** @var PaymentMethodRepositoryInterface */
    private $paymentMethodRepository;

    /** @var OrderRepositoryInterface */
    private $orderRepository;

    /** @var TranslatorInterface */
    private $translator;

    /** @var PayPlugApiClientInterface */
    private $oneyClient;

    public function __construct(
        RequestCommandCreatorInterface $decorated,
        private RequestToRefundUnitsConverterInterface | RefundUnitsConverterInterface $requestToRefundUnitsConverter,
        PaymentMethodRepositoryInterface $paymentMethodRepository,
        OrderRepositoryInterface $orderRepository,
        TranslatorInterface $translator,
        PayPlugApiClientInterface $oneyClient
    ) {
        $this->decorated = $decorated;
        $this->paymentMethodRepository = $paymentMethodRepository;
        $this->orderRepository = $orderRepository;
        $this->translator = $translator;
        $this->oneyClient = $oneyClient;
    }

    public function fromRequest(Request $request): RefundUnits
    {
        Assert::true($request->attributes->has('orderNumber'), 'Refunded order number not provided');

        if ($this->requestToRefundUnitsConverter instanceof RefundUnitsConverterInterface) {
            /** @phpstan-ignore-next-line */
            $units = $this->requestToRefundUnitsConverter->convert(
                $request->request->has('sylius_refund_units') ? $request->request->all()['sylius_refund_units'] : [],
                /* @phpstan-ignore-next-line */
                RefundType::orderItemUnit(),
                OrderItemUnitRefund::class,
            );

            /** @phpstan-ignore-next-line */
            $shipments = $this->requestToRefundUnitsConverter->convert(
                $request->request->has('sylius_refund_shipments') ? $request->request->all()['sylius_refund_shipments'] : [],
                /* @phpstan-ignore-next-line */
                RefundType::shipment(),
                ShipmentRefund::class,
            );

            $units = array_merge($units, $shipments);
        } else {
            $units = $this->requestToRefundUnitsConverter->convert($request);
        }

        if (0 === count($units)) {
            throw InvalidRefundAmount::withValidationConstraint('sylius_refund.at_least_one_unit_should_be_selected_to_refund');
        }

        /** @var int $paymentMethodId */
        $paymentMethodId = $request->request->get('sylius_refund_payment_method');

        /** @var PaymentMethodInterface $paymentMethod */
        $paymentMethod = $this->paymentMethodRepository->find($paymentMethodId);

        /** @var GatewayConfigInterface $gateway */
        $gateway = $paymentMethod->getGatewayConfig();

        if (PayPlugGatewayFactory::FACTORY_NAME !== $gateway->getFactoryName() &&
            OneyGatewayFactory::FACTORY_NAME !== $gateway->getFactoryName()) {
            return $this->decorated->fromRequest($request); /** @phpstan-ignore-line */
        }

        if (OneyGatewayFactory::FACTORY_NAME === $gateway->getFactoryName()) {
            $orderNumber = $request->get('orderNumber');
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

        return $this->decorated->fromRequest($request); /** @phpstan-ignore-line */
    }

    private function getTotalRefundAmount(array $units): int
    {
        $total = 0;

        foreach ($units as $unit) {
            $total += $this->getAmount($unit);
        }

        return $total;
    }

    private function getAmount(UnitRefundInterface $unit): int
    {
        return $unit->total();
    }

    private function canOneyRefundBeMade(OrderInterface $order): void
    {
        $lastPayment = $order->getLastPayment(PaymentInterface::STATE_COMPLETED);
        Assert::isInstanceOf($lastPayment, PaymentInterface::class);

        $data = $this->oneyClient->retrieve($lastPayment->getDetails()['payment_id']);

        $now = new \DateTime();

        if ($now->getTimestamp() < $data->refundable_until &&
            $now->getTimestamp() > $data->refundable_after) {
            return;
        }

        throw InvalidRefundAmount::withValidationConstraint($this->translator->trans('payplug_sylius_payplug_plugin.ui.oney_transaction_less_than_forty_eight_hours'));
    }
}
