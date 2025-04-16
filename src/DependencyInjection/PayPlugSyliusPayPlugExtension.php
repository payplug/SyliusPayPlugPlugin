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
        $xmlloader->load('gateway.xml');
    }

    public function prepend(ContainerBuilder $container): void
    {
        if (!$container->hasExtension('twig')) {
            return;
        }

        $viewsPath = dirname(__DIR__, 2) . '/templates/';
        // This adds our override in twig paths with correct namespace if there are not already overridden
        // No need for the final user to copy it
        $paths = [
            $viewsPath . 'SyliusShopBundle' => 'SyliusShop',
            $viewsPath . 'SyliusAdminBundle' => 'SyliusAdmin',
            $viewsPath . 'SyliusUiBundle' => 'SyliusUi',
        ];

        $twigConfig = $container->getExtensionConfig('twig');

        foreach ($paths as $key => $path) {
            if ($this->isPathAlreadyInConfiguration($path, $twigConfig)) {
                unset($paths[$key]);
            }
        }

        $container->prependExtensionConfig('twig', [
            'paths' => $paths,
            'form_themes' => [
                '@PayPlugSyliusPayPlugPlugin/form/form_gateway_config_row.html.twig',
                '@PayPlugSyliusPayPlugPlugin/form/sylius_checkout_select_payment_row.html.twig',
                '@PayPlugSyliusPayPlugPlugin/form/complete_info_popin.html.twig',
            ],
        ]);

        $this->prependDoctrineMigrations($container);
    }

    /**
     * Verify if a given namespace is already extended
     *
     * @param string $namespace The namespace to verify
     * @param array $configurations The given configurations
     */
    protected function isPathAlreadyInConfiguration(string $namespace, array $configurations): bool
    {
        foreach ($configurations as $configuration) {
            foreach ($configuration as $parameter => $values) {
                if ('paths' === $parameter && in_array($namespace, $values, true)) {
                    return true;
                }
            }
        }

        return false;
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
