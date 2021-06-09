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
use Sylius\RefundPlugin\Calculator\UnitRefundTotalCalculatorInterface;
use Sylius\RefundPlugin\Command\RefundUnits;
use Sylius\RefundPlugin\Creator\RefundUnitsCommandCreatorInterface;
use Sylius\RefundPlugin\Exception\InvalidRefundAmount;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\Translation\TranslatorInterface;
use Webmozart\Assert\Assert;

class RefundUnitsCommandCreatorDecorator implements RefundUnitsCommandCreatorInterface
{
    private const MINIMUM_REFUND_AMOUNT = 0.10;

    /** @var RefundUnitsCommandCreatorInterface */
    private $decorated;

    /** @var UnitRefundTotalCalculatorInterface */
    private $unitRefundTotalCalculator;

    /** @var PaymentMethodRepositoryInterface */
    private $paymentMethodRepository;

    /** @var OrderRepositoryInterface */
    private $orderRepository;

    /** @var TranslatorInterface */
    private $translator;

    /** @var PayPlugApiClientInterface */
    private $oneyClient;

    public function __construct(
        RefundUnitsCommandCreatorInterface $decorated,
        UnitRefundTotalCalculatorInterface $unitRefundTotalCalculator,
        PaymentMethodRepositoryInterface $paymentMethodRepository,
        OrderRepositoryInterface $orderRepository,
        TranslatorInterface $translator,
        PayPlugApiClientInterface $oneyClient
    ) {
        $this->unitRefundTotalCalculator = $unitRefundTotalCalculator;
        $this->decorated = $decorated;
        $this->paymentMethodRepository = $paymentMethodRepository;
        $this->orderRepository = $orderRepository;
        $this->translator = $translator;
        $this->oneyClient = $oneyClient;
    }

    public function fromRequest(Request $request): RefundUnits
    {
        Assert::true($request->attributes->has('orderNumber'), 'Refunded order number not provided');

        $units = $this->filterEmptyRefundUnits(
            $request->request->has('sylius_refund_units') ? $request->request->all()['sylius_refund_units'] : []
        );
        $shipments = $this->filterEmptyRefundUnits(
            $request->request->has('sylius_refund_shipments') ? $request->request->all()['sylius_refund_shipments'] : []
        );

        if (count($units) === 0 && count($shipments) === 0) {
            throw InvalidRefundAmount::withValidationConstraint(
                $this->translator->trans('sylius_refund.at_least_one_unit_should_be_selected_to_refund')
            );
        }

        /** @var int $paymentMethodId */
        $paymentMethodId = $request->request->get('sylius_refund_payment_method');

        /** @var PaymentMethodInterface $paymentMethod */
        $paymentMethod = $this->paymentMethodRepository->find($paymentMethodId);

        /** @var GatewayConfigInterface $gateway */
        $gateway = $paymentMethod->getGatewayConfig();

        if ($gateway->getFactoryName() !== PayPlugGatewayFactory::FACTORY_NAME &&
            $gateway->getFactoryName() !== OneyGatewayFactory::FACTORY_NAME) {
            return $this->decorated->fromRequest($request);
        }

        if ($gateway->getFactoryName() === OneyGatewayFactory::FACTORY_NAME) {
            /** @var OrderInterface|null $order */
            $order = $this->orderRepository->findOneByNumber($request->get('orderNumber'));
            Assert::isInstanceOf($order, OrderInterface::class);

            $this->canOneyRefundBeMade($order);
        }

        $totalRefundRequest = $this->getTotalRefundAmount($units, $shipments);

        if ($totalRefundRequest < self::MINIMUM_REFUND_AMOUNT) {
            throw InvalidRefundAmount::withValidationConstraint(
                $this->translator->trans('payplug_sylius_payplug_plugin.ui.refund_minimum_amount_requirement_not_met')
            );
        }

        return $this->decorated->fromRequest($request);
    }

    private function getTotalRefundAmount(array $units, array $shipments): float
    {
        $total = 0;

        foreach ($units as $unit) {
            $total += $this->getAmount($unit) ?? 0;
        }

        foreach ($shipments as $unit) {
            $total += $this->getAmount($unit) ?? 0;
        }

        return $total;
    }

    private function filterEmptyRefundUnits(array $units): array
    {
        return array_filter($units, function (array $refundUnit): bool {
            return
                (isset($refundUnit['amount']) && $refundUnit['amount'] !== '')
                || isset($refundUnit['full'])
                ;
        });
    }

    private function getAmount(array $unit): ?float
    {
        if (isset($unit['full'])) {
            return null;
        }

        Assert::keyExists($unit, 'amount');

        return (float) $unit['amount'];
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

        throw InvalidRefundAmount::withValidationConstraint(
            $this->translator->trans('payplug_sylius_payplug_plugin.ui.oney_transaction_less_than_forty_eight_hours')
        );
    }
}
