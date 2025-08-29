<?php

namespace PayPlug\SyliusPayPlugPlugin\Validator;

use Doctrine\ORM\EntityManagerInterface;
use PayPlug\SyliusPayPlugPlugin\Const\Permission;
use PayPlug\SyliusPayPlugPlugin\Gateway\AmericanExpressGatewayFactory;
use PayPlug\SyliusPayPlugPlugin\Gateway\ApplePayGatewayFactory;
use PayPlug\SyliusPayPlugPlugin\Gateway\BancontactGatewayFactory;
use PayPlug\SyliusPayPlugPlugin\Gateway\OneyGatewayFactory;
use PayPlug\SyliusPayPlugPlugin\Gateway\PayPlugGatewayFactory;
use PayPlug\SyliusPayPlugPlugin\Gateway\Validator\Constraints\IsCanSavePaymentMethod;
use PayPlug\SyliusPayPlugPlugin\Gateway\Validator\Constraints\IsOneyEnabled;
use PayPlug\SyliusPayPlugPlugin\Gateway\Validator\Constraints\PayplugPermission;
use Sylius\Component\Core\Model\PaymentMethodInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Validator\ConstraintViolationListInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Validate if the payment method is well configured.
 * If not, it adds errors in flash-bag AND disable the payment method.
 */
class PaymentMethodValidator
{
    public function __construct(
        private RequestStack $requestStack,
        private ValidatorInterface $validator,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function process(PaymentMethodInterface $paymentMethod): void
    {
        if (null === $paymentMethod->getGatewayConfig()) {
            return;
        }

        $errors = match ($paymentMethod->getGatewayConfig()->getFactoryName()) {
            PayPlugGatewayFactory::FACTORY_NAME => $this->processPayplug($paymentMethod),
            OneyGatewayFactory::FACTORY_NAME => $this->processOney($paymentMethod),
            BancontactGatewayFactory::FACTORY_NAME => $this->processBancontact($paymentMethod),
            AmericanExpressGatewayFactory::FACTORY_NAME => $this->processAmex($paymentMethod),
            ApplePayGatewayFactory::FACTORY_NAME => $this->processApplePay($paymentMethod),
            default => throw new \InvalidArgumentException("Unsupported payment method"),
        };

        foreach ($errors as $error) {
            $this->requestStack->getSession()->getFlashBag()->add('error', $error->getMessage());
        }
        if (0 !== count($errors)) {
            $paymentMethod->disable();
        }
        $this->entityManager->flush();
    }

    private function processPayplug(PaymentMethodInterface $paymentMethod): ConstraintViolationListInterface
    {
        $config = $paymentMethod->getGatewayConfig()?->getConfig() ?? [];
        $constraintList = [new IsCanSavePaymentMethod()];

        if (true === $config[PayPlugGatewayFactory::ONE_CLICK]) {
            $constraintList[] = new PayplugPermission(Permission::CAN_SAVE_CARD);
        }
        if (true === $config[PayPlugGatewayFactory::DEFERRED_CAPTURE]) {
            $constraintList[] = new PayplugPermission(Permission::CAN_CREATE_DEFERRED_PAYMENT);
        }
        if (true === $config[PayPlugGatewayFactory::INTEGRATED_PAYMENT]) {
            $constraintList[] = new PayplugPermission(Permission::CAN_USE_INTEGRATED_PAYMENTS);
        }

        return $this->validator->validate($paymentMethod, $constraintList);
    }

    private function processOney(PaymentMethodInterface $paymentMethod): ConstraintViolationListInterface
    {
        $constraintList = [new IsOneyEnabled()];
        return $this->validator->validate($paymentMethod, $constraintList);
    }

    private function processBancontact(PaymentMethodInterface $paymentMethod): ConstraintViolationListInterface
    {
        $constraintList = [new IsCanSavePaymentMethod()];
        return $this->validator->validate($paymentMethod, $constraintList);

    }

    private function processAmex(PaymentMethodInterface $paymentMethod): ConstraintViolationListInterface
    {
        $constraintList = [new IsCanSavePaymentMethod()];
        return $this->validator->validate($paymentMethod, $constraintList);
    }

    private function processApplePay(PaymentMethodInterface $paymentMethod): ConstraintViolationListInterface
    {
        $constraintList = [new IsCanSavePaymentMethod()];
        return $this->validator->validate($paymentMethod, $constraintList);
    }
}
