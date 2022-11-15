<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Action\Admin;

use Exception;
use Psr\Log\LoggerInterface;
use Sylius\RefundPlugin\Creator\RefundUnitsCommandCreatorInterface;
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

    /** @var RefundUnitsCommandCreatorInterface */
    private $commandCreator;

    /** @var LoggerInterface */
    private $logger;

    private RequestStack $requestStack;

    public function __construct(
        MessageBusInterface $commandBus,
        RequestStack $requestStack,
        UrlGeneratorInterface $router,
        RefundUnitsCommandCreatorInterface $commandCreator,
        LoggerInterface $logger
    ) {
        $this->commandBus = $commandBus;
        $this->requestStack = $requestStack;
        $this->router = $router;
        $this->commandCreator = $commandCreator;
        $this->logger = $logger;
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
