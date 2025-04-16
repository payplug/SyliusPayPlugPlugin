<?php

declare(strict_types=1);

namespace Tests\PayPlug\SyliusPayPlugPlugin\Behat\Context\Setup;

use Behat\Behat\Context\Context;
use Doctrine\Persistence\ObjectManager;
use PayPlug\SyliusPayPlugPlugin\Gateway\OneyGatewayFactory;
use PayPlug\SyliusPayPlugPlugin\Gateway\PayPlugGatewayFactory;
use Sylius\Behat\Service\SharedStorageInterface;
use Sylius\Bundle\CoreBundle\Fixture\Factory\ExampleFactoryInterface;
use Sylius\Component\Core\Model\PaymentMethodInterface;
use Sylius\Component\Core\Repository\PaymentMethodRepositoryInterface;
use Sylius\Component\Resource\Factory\FactoryInterface;

final class PayPlugContext implements Context
{
    /** @var SharedStorageInterface */
    private $sharedStorage;

    /** @var PaymentMethodRepositoryInterface */
    private $paymentMethodRepository;

    /** @var ExampleFactoryInterface */
    private $paymentMethodExampleFactory;

    /** @var FactoryInterface */
    private $paymentMethodTranslationFactory;

    /** @var ObjectManager */
    private $paymentMethodManager;

    public function __construct(
        SharedStorageInterface $sharedStorage,
        PaymentMethodRepositoryInterface $paymentMethodRepository,
        ExampleFactoryInterface $paymentMethodExampleFactory,
        FactoryInterface $paymentMethodTranslationFactory,
        ObjectManager $paymentMethodManager,
    ) {
        $this->sharedStorage = $sharedStorage;
        $this->paymentMethodRepository = $paymentMethodRepository;
        $this->paymentMethodExampleFactory = $paymentMethodExampleFactory;
        $this->paymentMethodTranslationFactory = $paymentMethodTranslationFactory;
        $this->paymentMethodManager = $paymentMethodManager;
    }

    /**
     * @Given the store has a payment method :paymentMethodName with a code :paymentMethodCode and PayPlug payment gateway
     */
    public function theStoreHasAPaymentMethodWithACodeAndPayPlugPaymentGateway(
        string $paymentMethodName,
        string $paymentMethodCode,
    ): void {
        $paymentMethod = $this->createPaymentMethodPayPlug(
            $paymentMethodName,
            $paymentMethodCode,
            PayPlugGatewayFactory::FACTORY_NAME,
            'PayPlug',
        );

        $paymentMethod->getGatewayConfig()->setConfig([
            'secretKey' => 'test',
            'payum.http_client' => '@payplug_sylius_payplug_plugin.api_client.payplug',
        ]);

        $this->paymentMethodManager->flush();
    }

    /**
     * @Given the store has a payment method :paymentMethodName with a code :paymentMethodCode and Oney payment gateway
     */
    public function theStoreHasAPaymentMethodWithACodeAndOneyPaymentGateway(
        string $paymentMethodName,
        string $paymentMethodCode,
    ): void {
        $paymentMethod = $this->createPaymentMethodPayPlug(
            $paymentMethodName,
            $paymentMethodCode,
            OneyGatewayFactory::FACTORY_NAME,
            'Oney',
        );

        $paymentMethod->getGatewayConfig()->setConfig([
            'secretKey' => 'test',
            'payum.http_client' => '@payplug_sylius_payplug_plugin.api_client.payplug',
        ]);

        $this->paymentMethodManager->flush();
    }

    /**
     * @Given the store has a payment method :paymentMethodName with a code :paymentMethodCode other than PayPlug payment gateway
     */
    public function theStoreHasAPaymentMethodWithACodeOtherThanPayplugPaymentGateway(
        string $paymentMethodName,
        string $paymentMethodCode,
    ): void {
        $this->createPaymentMethodPayPlug(
            $paymentMethodName,
            $paymentMethodCode,
            $paymentMethodCode,
            '',
        );

        $this->paymentMethodManager->flush();
    }

    private function createPaymentMethodPayPlug(
        string $name,
        string $code,
        string $factoryName,
        string $description = '',
        bool $addForCurrentChannel = true,
        int $position = null,
    ): PaymentMethodInterface {
        /** @var PaymentMethodInterface $paymentMethod */
        $paymentMethod = $this->paymentMethodExampleFactory->create([
            'name' => ucfirst($name),
            'code' => $code,
            'description' => $description,
            'gatewayName' => $factoryName,
            'gatewayFactory' => $factoryName,
            'enabled' => true,
            'channels' => ($addForCurrentChannel && $this->sharedStorage->has('channel')) ? [$this->sharedStorage->get('channel')] : [],
        ]);

        if (null !== $position) {
            $paymentMethod->setPosition($position);
        }

        $this->sharedStorage->set('payment_method', $paymentMethod);
        $this->paymentMethodRepository->add($paymentMethod);

        return $paymentMethod;
    }
}
