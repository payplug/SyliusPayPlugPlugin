<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Controller;

use Doctrine\ORM\EntityManagerInterface;
use PayPlug\SyliusPayPlugPlugin\Model\OneyCompleteInfoDTO;
use PayPlug\SyliusPayPlugPlugin\Validator\OneyInvalidDataRetriever;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Order\Context\CartContextInterface;
use Sylius\Component\Resource\Repository\RepositoryInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Validator\Constraints\NotBlank;
use Webmozart\Assert\Assert;

final class CompleteInfoController extends AbstractController
{
    /** @var \PayPlug\SyliusPayPlugPlugin\Validator\OneyInvalidDataRetriever */
    private $invalidDataRetriever;

    /** @var \Sylius\Component\Resource\Repository\RepositoryInterface */
    private $customerRepository;

    public function __construct(
        OneyInvalidDataRetriever $invalidDataRetriever,
        RepositoryInterface $customerRepository
    ) {
        $this->invalidDataRetriever = $invalidDataRetriever;
        $this->customerRepository = $customerRepository;
    }

    public function __invoke(
        Request $request,
        CartContextInterface $cartContext,
        EntityManagerInterface $entityManager
    ): Response {
        if (!$request->isXmlHttpRequest()) {
            throw $this->createNotFoundException();
        }

        $order = $cartContext->getCart();
        Assert::isInstanceOf($order, OrderInterface::class);
        $data = $this->invalidDataRetriever->getForOrder($order);
        $form = $this->createFormForOrder($order, $data);

        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            /** @var OneyCompleteInfoDTO $completeInfo */
            $completeInfo = $form->getData();
            if (\array_key_exists('email', $data)) {
                $this->handleEmail($order, $completeInfo->email);
            }

            if (\array_key_exists('billing_phone', $data)) {
                Assert::notNull($order->getBillingAddress());
                $order->getBillingAddress()->setPhoneNumber($completeInfo->phone);
            }

            if (\array_key_exists('shipping_phone', $data)) {
                Assert::notNull($order->getShippingAddress());
                $order->getShippingAddress()->setPhoneNumber($completeInfo->phone);
            }
            $entityManager->flush();

            return $this->json([]);
        }

        return $this->render('@PayPlugSyliusPayPlugPlugin/form/complete_info_popin.html.twig', [
            'form' => $form->createView(),
        ]);
    }

    private function createFormForOrder(OrderInterface $order, array $fields): FormInterface
    {
        $completeInfo = new OneyCompleteInfoDTO();
        Assert::notNull($order->getShippingAddress());
        $completeInfo->countryCode = (string) $order->getShippingAddress()->getCountryCode();

        if (\array_key_exists('shipping_phone', $fields) || \array_key_exists('billing_phone', $fields)) {
            // Ask only one phone field
            $fields['phone'] = $fields['shipping_phone'] ?? $fields['billing_phone'];
            unset($fields['shipping_phone'], $fields['billing_phone']);
        }

        $formBuilder = $this->createFormBuilder($completeInfo);
        foreach ($fields as $field => $type) {
            $formBuilder->add($field, $type, ['required' => true, 'constraints' => [new NotBlank()]]);
        }

        $formBuilder->add('submit', SubmitType::class);

        return $formBuilder->getForm();
    }

    private function handleEmail(OrderInterface $order, string $email): void
    {
        $customer = $this->customerRepository->findOneBy(['email' => $email]);

        if (null !== $customer) {
            $order->setCustomer($customer);

            return;
        }

        Assert::notNull($order->getCustomer());
        $order->getCustomer()->setEmail($email);
    }
}
