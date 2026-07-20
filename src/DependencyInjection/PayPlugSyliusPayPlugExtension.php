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
        $this->prependMonologExtension($container);
        $this->prependSpikeDoctrineMapping($container);
    }

    /**
     * PRE-3469 spike only — registers src/Spike/Entity as a plain (non-Sylius-resource)
     * Doctrine mapping so the spike's integration test can persist PayplugOperation for real.
     * Not a `sylius_resource` on purpose: that would pull in grids/forms/routes this throwaway
     * entity has no use for. Restricted to the `test` environment on purpose too — this is
     * test-only scaffolding, it must never register Doctrine metadata for a throwaway entity in
     * prod. Remove this method along with src/Spike/ once the spike is closed.
     */
    private function prependSpikeDoctrineMapping(ContainerBuilder $container): void
    {
        if (!$container->hasExtension('doctrine') || 'test' !== $container->getParameter('kernel.environment')) {
            return;
        }

        $container->prependExtensionConfig('doctrine', [
            'orm' => [
                'mappings' => [
                    'PayPlugSyliusPayPlugPluginSpike' => [
                        'type' => 'attribute',
                        'dir' => dirname(__DIR__) . '/Spike/Entity',
                        'prefix' => 'PayPlug\SyliusPayPlugPlugin\Spike\Entity',
                        'is_bundle' => false,
                    ],
                ],
            ],
        ]);
    }

    private function prependTwigExtension(ContainerBuilder $container): void
    {
        if (!$container->hasExtension('twig')) {
            return;
        }

        $container->prependExtensionConfig('twig', [
            'form_themes' => [
                '@PayPlugSyliusPayPlugPlugin/form/form_gateway_config_row.html.twig',
                '@PayPlugSyliusPayPlugPlugin/form/sylius_checkout_select_payment_row.html.twig',
            ],
        ]);
    }

    public function prependMonologExtension(ContainerBuilder $container): void
    {
        if ($container->hasExtension('monolog')) {
            $container->prependExtensionConfig('monolog', [
                'channels' => ['payplug'],
            ]);
        }
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
