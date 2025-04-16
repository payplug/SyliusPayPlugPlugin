<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Action;

use ArrayAccess;
use LogicException;
use Payplug\Exception\PayplugException;
use PayPlug\SyliusPayPlugPlugin\Action\Api\ApiAwareTrait;
use PayPlug\SyliusPayPlugPlugin\ApiClient\PayPlugApiClientInterface;
use PayPlug\SyliusPayPlugPlugin\Gateway\AmericanExpressGatewayFactory;
use PayPlug\SyliusPayPlugPlugin\Gateway\BancontactGatewayFactory;
use PayPlug\SyliusPayPlugPlugin\Gateway\OneyGatewayFactory;
use PayPlug\SyliusPayPlugPlugin\Gateway\PayPlugGatewayFactory;
use PayPlug\SyliusPayPlugPlugin\Handler\PaymentNotificationHandler;
use PayPlug\SyliusPayPlugPlugin\Handler\RefundNotificationHandler;
use Payum\Core\Action\ActionInterface;
use Payum\Core\ApiAwareInterface;
use Payum\Core\Bridge\Spl\ArrayObject;
use Payum\Core\GatewayAwareInterface;
use Payum\Core\GatewayAwareTrait;
use Payum\Core\Request\Notify;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsAlias(id: 'payplug_sylius_payplug_plugin.action.notify', public: true)]
#[AutoconfigureTag(
    name: 'payum.action',
    attributes: [
        'factory' => PayPlugGatewayFactory::FACTORY_NAME,
        'alias' => 'payum.action.capture',
    ],
)]
#[AutoconfigureTag(
    name: 'payum.action',
    attributes: [
        'factory' => OneyGatewayFactory::FACTORY_NAME,
        'alias' => 'payum.action.capture',
    ],
)]
#[AutoconfigureTag(
    name: 'payum.action',
    attributes: [
        'factory' => BancontactGatewayFactory::FACTORY_NAME,
        'alias' => 'payum.action.capture',
    ],
)]
#[AutoconfigureTag(
    name: 'payum.action',
    attributes: [
        'factory' => AmericanExpressGatewayFactory::FACTORY_NAME,
        'alias' => 'payum.action.capture',
    ],
)]
final class NotifyAction implements ActionInterface, ApiAwareInterface, GatewayAwareInterface
{
    use GatewayAwareTrait;
    use ApiAwareTrait;

    public function __construct(
        #[Autowire('@monolog.logger.payum')]
        private LoggerInterface $logger,
        private PaymentNotificationHandler $paymentNotificationHandler,
        private RefundNotificationHandler $refundNotificationHandler,
    ) {
    }

    public function execute($request): void
    {
        // Put notification asleep to prevent double processing while user is redirected manually
        sleep(10);
        $details = ArrayObject::ensureArrayObject($request->getModel());

        $input = file_get_contents('php://input');

        try {
            if (!is_string($input)) {
                throw new LogicException('Input must be of type string.');
            }
            $resource = $this->payPlugApiClient->treat($input);

            $this->paymentNotificationHandler->treat($request->getFirstModel(), $resource, $details);
            $this->refundNotificationHandler->treat($request->getFirstModel(), $resource, $details);
        } catch (PayplugException $exception) {
            $details['status'] = PayPlugApiClientInterface::FAILED;
            $this->logger->error('[PayPlug] Notify action', ['error' => $exception->getMessage()]);
        }
    }

    public function supports($request): bool
    {
        return
            $request instanceof Notify &&
            $request->getModel() instanceof ArrayAccess;
    }
}
