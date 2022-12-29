<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Action\Admin;

use Doctrine\Persistence\ObjectRepository;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Repository\OrderRepositoryInterface;
use Sylius\RefundPlugin\Entity\RefundPaymentInterface;
use Sylius\RefundPlugin\Event\RefundPaymentGenerated;
use Sylius\RefundPlugin\Provider\RelatedPaymentIdProviderInterface;
use Sylius\RefundPlugin\StateResolver\RefundPaymentCompletedStateApplierInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Throwable;

final class CompleteRefundPaymentAction
{
    private const COMPLETED_STATE = 'completed';

    /** @var ObjectRepository */
    private $refundPaymentRepository;

    /** @var RefundPaymentCompletedStateApplierInterface */
    private $refundPaymentCompletedStateApplier;

    /** @var RouterInterface */
    private $router;

    /** @var OrderRepositoryInterface */
    private $orderRepository;

    /** @var MessageBusInterface */
    private $messageBus;

    /** @var RelatedPaymentIdProviderInterface */
    private $relatedPaymentIdProvider;

    /** @var \Symfony\Contracts\Translation\TranslatorInterface */
    private $translator;

    private RequestStack $requestStack;

    public function __construct(
        RequestStack $requestStack,
        ObjectRepository $refundPaymentInterface,
        OrderRepositoryInterface $orderRepository,
        RefundPaymentCompletedStateApplierInterface $refundPaymentCompletedStateApplier,
        RouterInterface $router,
        MessageBusInterface $messageBus,
        RelatedPaymentIdProviderInterface $relatedPaymentIdProvider,
        TranslatorInterface $translator
    ) {
        $this->requestStack = $requestStack;
        $this->refundPaymentRepository = $refundPaymentInterface;
        $this->refundPaymentCompletedStateApplier = $refundPaymentCompletedStateApplier;
        $this->router = $router;
        $this->orderRepository = $orderRepository;
        $this->messageBus = $messageBus;
        $this->relatedPaymentIdProvider = $relatedPaymentIdProvider;
        $this->translator = $translator;
    }

    public function __invoke(string $orderNumber, string $id): Response
    {
        /** @var RefundPaymentInterface $refundPayment */
        $refundPayment = $this->refundPaymentRepository->find($id);

        /** @var OrderInterface $order */
        $order = $this->orderRepository->findOneByNumber($orderNumber);

        try {
            $this->messageBus->dispatch(new RefundPaymentGenerated(
                $refundPayment->getId(),
                $refundPayment->getOrder()->getNumber() ?? '',
                $refundPayment->getAmount(),
                $refundPayment->getCurrencyCode(),
                $refundPayment->getPaymentMethod()->getId(),
                $this->relatedPaymentIdProvider->getForRefundPayment($refundPayment)
            ));

            if (self::COMPLETED_STATE !== $refundPayment->getState()) {
                $this->refundPaymentCompletedStateApplier->apply($refundPayment);
            }
            $this->requestStack->getSession()->getFlashBag()->add('success', 'sylius_refund.refund_payment_completed');
        } catch (Throwable $throwable) {
            $this->requestStack->getSession()->getFlashBag()->add('error', $this->translator->trans('payplug_sylius_payplug_plugin.ui.impossible_to_refund_this_payment'));
        }

        return new RedirectResponse($this->router->generate(
            'sylius_admin_order_show',
            ['id' => $order->getId()]
        ));
    }
}
