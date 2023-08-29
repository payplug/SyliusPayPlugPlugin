<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Action\Admin;

use Exception;
use Psr\Log\LoggerInterface;
use Sylius\RefundPlugin\Creator\RefundUnitsCommandCreatorInterface;
use Sylius\RefundPlugin\Creator\RequestCommandCreatorInterface;
use Sylius\RefundPlugin\Exception\InvalidRefundAmount;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class RefundUnitsAction
{
    /** @var MessageBusInterface */
    private $commandBus;

    /** @var UrlGeneratorInterface */
    private $router;

    /** @var LoggerInterface */
    private $logger;

    public function __construct(
        MessageBusInterface $commandBus,
        private RequestStack $requestStack,
        UrlGeneratorInterface $router,
        private RequestCommandCreatorInterface | RefundUnitsCommandCreatorInterface $commandCreator,
        LoggerInterface $logger
    ) {
        $this->commandBus = $commandBus;
        $this->router = $router;
        $this->logger = $logger;

        if ($this->commandCreator instanceof RefundUnitsCommandCreatorInterface) {
            trigger_deprecation('sylius/refund-plugin', '1.4', sprintf('Passing an instance of %s as constructor argument for %s is deprecated as of Sylius Refund Plugin 1.4 and will be removed in 2.0. Pass an instance of %s instead.', RefundUnitsCommandCreatorInterface::class, self::class, RequestCommandCreatorInterface::class));
        }
    }

    public function __invoke(Request $request): Response
    {
        try {
            $this->commandBus->dispatch($this->commandCreator->fromRequest($request));

            $this->requestStack->getSession()->getFlashBag()->add('success', 'sylius_refund.units_successfully_refunded');
        } catch (InvalidRefundAmount $exception) {
            $this->requestStack->getSession()->getFlashBag()->add('error', $exception->getMessage());

            $this->logger->error($exception->getMessage());
        } catch (HandlerFailedException $exception) {
            /** @var Exception $previousException */
            $previousException = $exception->getPrevious();

            $this->requestStack->getSession()->getFlashBag()->add('error', $previousException->getMessage());

            $this->logger->error($previousException->getMessage());
        }

        return new RedirectResponse($this->router->generate(
            'sylius_refund_order_refunds_list',
            ['orderNumber' => $request->attributes->get('orderNumber')]
        ));
    }
}
