<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Controller;

use Doctrine\ORM\EntityManagerInterface;
use PayPlug\SyliusPayPlugPlugin\Validator\OneyInvalidDataRetriever;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Order\Context\CartContextInterface;
use Sylius\Component\Resource\Repository\RepositoryInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Form;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Webmozart\Assert\Assert;

final class CompleteInfoController extends AbstractController
{
    /** @var \PayPlug\SyliusPayPlugPlugin\Validator\OneyInvalidDataRetriever */
    private $invalidDataRetriever;

    public function __construct(OneyInvalidDataRetriever $invalidDataRetriever)
    {
        $this->invalidDataRetriever = $invalidDataRetriever;
    }

    public function __invoke(
        Request $request,
        CartContextInterface $cartContext,
        EntityManagerInterface $entityManager
    ): Response {
        if (!$request->isXmlHttpRequest()) {
            throw $this->createNotFoundException();
        }
        /** @var OrderInterface $order */
        $order = $cartContext->getCart();
        Assert::isInstanceOf($order, OrderInterface::class);
        $form = $this->createFormForOrder($order);

        if (Request::METHOD_GET === $request->getMethod()) {
            return $this->render('@PayPlugSyliusPayPlugPlugin/form/complete_info_popin.html.twig', [
                'form' => $form->createView()
            ]);
        }

        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $data = $form->getData();
            if (\array_key_exists('email', $data)) {
                $order->getCustomer()->setEmail($data['email']);
            }
            if (\array_key_exists('phone', $data)) {
                $order->getBillingAddress()->setPhoneNumber($data['phone']);
                $order->getShippingAddress()->setPhoneNumber($data['phone']);
            }
            if (\array_key_exists('billing_phone', $data)) {
                $order->getBillingAddress()->setPhoneNumber($data['phone']);
            }
            if (\array_key_exists('shipping_phone', $data)) {
                $order->getShippingAddress()->setPhoneNumber($data['phone']);
            }
            $entityManager->flush();
        }
        return $this->json([]);
    }

    private function createFormForOrder(OrderInterface $order): Form
    {
        $formBuilder = $this->createFormBuilder();
        $fields = $this->invalidDataRetriever->getForOrder($order);

        if (\array_key_exists('shipping_phone', $fields) && \array_key_exists('billing_phone', $fields)) {
            // Ask only one phone field
            $fields['phone'] = $fields['shipping_phone'];
            unset($fields['shipping_phone'], $fields['billing_phone']);
        }

        foreach ($fields as $field => $type) {
            $formBuilder->add($field, $type);
        }

        $formBuilder->add('submit', SubmitType::class);

        return $formBuilder->getForm();
    }
}
