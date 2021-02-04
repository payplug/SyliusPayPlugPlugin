<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\DependencyInjection;

use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;
use Symfony\Component\DependencyInjection\Loader\XmlFileLoader;

final class PayPlugSyliusPayPlugExtension extends Extension implements PrependExtensionInterface
{
    /**
     * {@inheritdoc}
     */
    public function load(array $config, ContainerBuilder $container): void
    {
        $loader = new XmlFileLoader($container, new FileLocator(dirname(__DIR__) . '/Resources/config'));

        $loader->load('services.xml');
    }

    public function prepend(ContainerBuilder $container): void
    {
        if (!$container->hasExtension('twig')) {
            return;
        }

        $viewsPath = dirname(__DIR__) . '/Resources/views/';
        // This add our override in twig paths with correct namespace. No need for final user to copy it
        $paths = [
            $viewsPath . 'SyliusShopBundle' => 'SyliusShop',
            $viewsPath . 'SyliusAdminBundle' => 'SyliusAdmin',
        ];

        $container->prependExtensionConfig('twig', [
            'paths' => $paths,
            'form_themes' => ['@PayPlugSyliusPayPlugPlugin/form/form_gateway_config_row.html.twig'],
        ]);
    }
}
