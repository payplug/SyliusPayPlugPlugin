<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Action;

use PayPlug\SyliusPayPlugPlugin\Action\Api\ApiAwareTrait;
use PayPlug\SyliusPayPlugPlugin\Creator\PayPlugPaymentDataCreator;
use PayPlug\SyliusPayPlugPlugin\Gateway\AmericanExpressGatewayFactory;
use PayPlug\SyliusPayPlugPlugin\Gateway\BancontactGatewayFactory;
use PayPlug\SyliusPayPlugPlugin\Gateway\OneyGatewayFactory;
use PayPlug\SyliusPayPlugPlugin\Gateway\PayPlugGatewayFactory;
use Payum\Core\Action\ActionInterface;
use Payum\Core\ApiAwareInterface;
use Payum\Core\Exception\RequestNotSupportedException;
use Payum\Core\Request\Convert;
use Sylius\Component\Core\Model\PaymentInterface;
use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * @deprecated Dead Payum wiring — this action is never executed.
 *
 * The `payum.action` tags declared below do reach the compiled container, but no Payum gateway is
 * ever registered for the `payplug*` factory names: the only `payum.gateway_factory_builder` tags
 * live in config/services/gateway.xml, which PayPlugSyliusPayPlugExtension::load() does not load.
 * With no gateway to attach to, the tagged action is collected and then never run.
 *
 * The payment payload is now built by {@see \PayPlug\SyliusPayPlugPlugin\Creator\PayPlugPaymentDataCreator},
 * called directly from {@see \PayPlug\SyliusPayPlugPlugin\Command\Handler\CapturePaymentRequestHandler}.
 * Kept only as an opt-in escape hatch for host applications importing gateway.xml themselves.
 * Will be removed permanently in early 2027.
 */
#[AutoconfigureTag(
    name: 'payum.action',
    attributes: [
        'factory' => PayPlugGatewayFactory::FACTORY_NAME,
        'alias' => 'payum.action.convert_payment',
    ],
)]
#[AutoconfigureTag(
    name: 'payum.action',
    attributes: [
        'factory' => OneyGatewayFactory::FACTORY_NAME,
        'alias' => 'payum.action.convert_payment',
    ],
)]
#[AutoconfigureTag(
    name: 'payum.action',
    attributes: [
        'factory' => BancontactGatewayFactory::FACTORY_NAME,
        'alias' => 'payum.action.convert_payment',
    ],
)]
#[AutoconfigureTag(
    name: 'payum.action',
    attributes: [
        'factory' => AmericanExpressGatewayFactory::FACTORY_NAME,
        'alias' => 'payum.action.convert_payment',
    ],
)]
#[Autoconfigure(public: true)]
final class ConvertPaymentAction implements ActionInterface, ApiAwareInterface
{
    use ApiAwareTrait;

    public function __construct(private PayPlugPaymentDataCreator $paymentDataCreator)
    {
    }

    public function execute($request): void
    {
        RequestNotSupportedException::assertSupports($this, $request);

        /** @var PaymentInterface $payment */
        $payment = $request->getSource();

        $details = $this->paymentDataCreator->create($payment);

        $request->setResult((array) $details);
    }

    public function supports($request): bool
    {
        return
            $request instanceof Convert &&
            $request->getSource() instanceof PaymentInterface &&
            'array' === $request->getTo();
    }
}
