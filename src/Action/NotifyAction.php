<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Action;

use PayPlug\SyliusPayPlugPlugin\Action\Api\ApiAwareTrait;
use PayPlug\SyliusPayPlugPlugin\ApiClient\PayPlugApiClientInterface;
use Payum\Core\Action\ActionInterface;
use Payum\Core\ApiAwareInterface;
use Payum\Core\Bridge\Spl\ArrayObject;
use Payum\Core\GatewayAwareInterface;
use Payum\Core\GatewayAwareTrait;
use Payum\Core\Request\Notify;
use Psr\Log\LoggerInterface;

final class NotifyAction implements ActionInterface, ApiAwareInterface, GatewayAwareInterface
{
    use GatewayAwareTrait, ApiAwareTrait;

    /** @var LoggerInterface */
    private $logger;

    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    public function execute($request): void
    {
        $details = ArrayObject::ensureArrayObject($request->getModel());

        $input = file_get_contents('php://input');

        try {
            if (!is_string($input)) {
                throw new \LogicException('Input must be of type string.');
            }
            $resource = $this->payPlugApiClient->treat($input);

            if ($resource instanceof \Payplug\Resource\Payment && $resource->is_paid) {
                $details['status'] = PayPlugApiClientInterface::STATUS_CAPTURED;

                return;
            }

            if ($resource instanceof \Payplug\Resource\Refund && $resource->is_refunded) {
                $details['status'] = PayPlugApiClientInterface::REFUNDED;

                return;
            }

            $this->logger->info('[PayPlug] Notify action', ['failure' => $resource->failure]);

            $details['status'] = PayPlugApiClientInterface::FAILED;
        } catch (\Payplug\Exception\PayplugException $exception) {
            $details['status'] = PayPlugApiClientInterface::FAILED;

            $this->logger->error('[PayPlug] Notify action', ['error' => $exception->getMessage()]);
        }
    }

    public function supports($request): bool
    {
        return
            $request instanceof Notify &&
            $request->getModel() instanceof \ArrayAccess
        ;
    }
}
