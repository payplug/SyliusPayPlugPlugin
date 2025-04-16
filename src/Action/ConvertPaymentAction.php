<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Action;

use PayPlug\SyliusPayPlugPlugin\Action\Api\ApiAwareTrait;
use PayPlug\SyliusPayPlugPlugin\Creator\PayPlugPaymentDataCreator;
use Payum\Core\Action\ActionInterface;
use Payum\Core\ApiAwareInterface;
use Payum\Core\Exception\RequestNotSupportedException;
use Payum\Core\Request\Convert;
use Sylius\Component\Core\Model\PaymentInterface;

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

        $details = $this->paymentDataCreator->create($payment, $this->payPlugApiClient->getGatewayFactoryName());

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
