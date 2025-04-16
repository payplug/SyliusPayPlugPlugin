<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Action;

use ArrayAccess;
use LogicException;
use Payplug\Exception\PayplugException;
use PayPlug\SyliusPayPlugPlugin\Action\Api\ApiAwareTrait;
use PayPlug\SyliusPayPlugPlugin\ApiClient\PayPlugApiClientInterface;
use PayPlug\SyliusPayPlugPlugin\Handler\PaymentNotificationHandler;
use PayPlug\SyliusPayPlugPlugin\Handler\RefundNotificationHandler;
use Payum\Core\Action\ActionInterface;
use Payum\Core\ApiAwareInterface;
use Payum\Core\Bridge\Spl\ArrayObject;
use Payum\Core\GatewayAwareInterface;
use Payum\Core\GatewayAwareTrait;
use Payum\Core\Request\Notify;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

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
