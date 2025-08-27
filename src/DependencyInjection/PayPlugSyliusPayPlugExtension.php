<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\DependencyInjection;

use Sylius\Bundle\CoreBundle\DependencyInjection\PrependDoctrineMigrationsTrait;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;
use Symfony\Component\DependencyInjection\Loader\XmlFileLoader;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;

final class PayPlugSyliusPayPlugExtension extends Extension implements PrependExtensionInterface
{
    use PrependDoctrineMigrationsTrait;

    /**
     * @inheritdoc
     */
    public function load(array $configs, ContainerBuilder $container): void
    {
        $ymlloader = new YamlFileLoader($container, new FileLocator(dirname(__DIR__, 2) . '/config'));
        $xmlloader = new XmlFileLoader($container, new FileLocator(dirname(__DIR__, 2) . '/config/services'));

        $ymlloader->load('services.yaml');
        $xmlloader->load('client.xml');
    }

    public function prepend(ContainerBuilder $container): void
    {
        $this->prependTwigExtension($container);
        $this->prependDoctrineMigrations($container);
    }

    private function prependTwigExtension(ContainerBuilder $container): void
    {
        if (!$container->hasExtension('twig')) {
            return;
        }

        // TODO: check if still mandatory on v2
        $container->prependExtensionConfig('twig', [
            'form_themes' => [
                '@PayPlugSyliusPayPlugPlugin/form/form_gateway_config_row.html.twig',
                '@PayPlugSyliusPayPlugPlugin/form/sylius_checkout_select_payment_row.html.twig',
                '@PayPlugSyliusPayPlugPlugin/form/complete_info_popin.html.twig',
            ],
        ]);
    }

    protected function getMigrationsNamespace(): string
    {
        return 'PayPlug\SyliusPayPlugPlugin\Migrations';
    }

    protected function getMigrationsDirectory(): string
    {
        return '@PayPlugSyliusPayPlugPlugin/migrations';
    }

    protected function getNamespacesOfMigrationsExecutedBefore(): array
    {
        return [
            'Sylius\Bundle\CoreBundle\Migrations',
            'Sylius\RefundPlugin\Migrations',
        ];
    }
}
