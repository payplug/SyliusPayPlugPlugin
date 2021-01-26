<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Creator;

use Sylius\Component\Core\Model\PaymentMethodInterface;
use Sylius\Component\Payment\Repository\PaymentMethodRepositoryInterface;
use Sylius\RefundPlugin\Calculator\UnitRefundTotalCalculatorInterface;
use Sylius\RefundPlugin\Command\RefundUnits;
use Sylius\RefundPlugin\Creator\RefundUnitsCommandCreatorInterface;
use Sylius\RefundPlugin\Exception\InvalidRefundAmountException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\Translation\TranslatorInterface;
use Webmozart\Assert\Assert;

class RefundUnitsCommandCreatorDecorator implements RefundUnitsCommandCreatorInterface
{
    private const MINIMUM_REFUND_AMOUNT = 0.10;

    /** @var \Sylius\RefundPlugin\Creator\RefundUnitsCommandCreatorInterface */
    private $decorated;

    /** @var UnitRefundTotalCalculatorInterface */
    private $unitRefundTotalCalculator;

    /** @var \Sylius\Component\Payment\Repository\PaymentMethodRepositoryInterface */
    private $paymentMethodRepository;

    /** @var \Symfony\Contracts\Translation\TranslatorInterface */
    private $translator;

    public function __construct(
        RefundUnitsCommandCreatorInterface $decorated,
        UnitRefundTotalCalculatorInterface $unitRefundTotalCalculator,
        PaymentMethodRepositoryInterface $paymentMethodRepository,
        TranslatorInterface $translator
    ) {
        $this->unitRefundTotalCalculator = $unitRefundTotalCalculator;
        $this->decorated = $decorated;
        $this->paymentMethodRepository = $paymentMethodRepository;
        $this->translator = $translator;
    }

    public function fromRequest(Request $request): RefundUnits
    {
        $units = $this->filterEmptyRefundUnits($request->request->get('sylius_refund_units', []));
        $shipments = $this->filterEmptyRefundUnits($request->request->get('sylius_refund_shipments', []));

        if (count($units) === 0 && count($shipments) === 0) {
            throw InvalidRefundAmountException::withValidationConstraint(
                $this->translator->trans('sylius_refund.at_least_one_unit_should_be_selected_to_refund')
            );
        }

        /** @var int $paymentMethodId */
        $paymentMethodId = $request->request->get('sylius_refund_payment_method');

        /** @var PaymentMethodInterface $paymentMethod */
        $paymentMethod = $this->paymentMethodRepository->find($paymentMethodId);

        /** @var \Payum\Core\Model\GatewayConfigInterface $gateway */
        $gateway = $paymentMethod->getGatewayConfig();

        if ($gateway->getFactoryName() !== \PayPlug\SyliusPayPlugPlugin\Gateway\PayPlugGatewayFactory::FACTORY_NAME &&
            $gateway->getFactoryName() !== \PayPlug\SyliusPayPlugPlugin\Gateway\OneyGatewayFactory::FACTORY_NAME) {
            return $this->decorated->fromRequest($request);
        }

        $totalRefundRequest = $this->getTotalRefundAmount($units, $shipments);

        if ($totalRefundRequest < self::MINIMUM_REFUND_AMOUNT) {
            throw InvalidRefundAmountException::withValidationConstraint(
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
}
