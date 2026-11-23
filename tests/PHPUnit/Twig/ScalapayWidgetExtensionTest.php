<?php

declare(strict_types=1);

namespace Tests\PayPlug\SyliusPayPlugPlugin\PHPUnit\Twig;

use PayPlug\SyliusPayPlugPlugin\Twig\ScalapayWidgetExtension;
use PayPlug\SyliusPayPlugPlugin\Twig\ScalapayWidgetRuntime;
use PHPUnit\Framework\TestCase;
use Twig\Extension\RuntimeExtensionInterface;

final class ScalapayWidgetExtensionTest extends TestCase
{
    public function testRegistersTheWidgetFunctionsOnTheLazyRuntime(): void
    {
        $functions = [];
        foreach ((new ScalapayWidgetExtension())->getFunctions() as $function) {
            $functions[$function->getName()] = $function->getCallable();
        }

        self::assertSame([
            'payplug_scalapay_product_widget' => [ScalapayWidgetRuntime::class, 'forProduct'],
            'payplug_scalapay_cart_widget' => [ScalapayWidgetRuntime::class, 'forCart'],
            'payplug_scalapay_checkout_widget' => [ScalapayWidgetRuntime::class, 'forCheckout'],
            'payplug_scalapay_widget_script_required' => [ScalapayWidgetRuntime::class, 'isScriptRequired'],
        ], $functions);
    }

    public function testEveryFunctionTargetsAMethodOfTheRuntime(): void
    {
        self::assertTrue(is_subclass_of(ScalapayWidgetRuntime::class, RuntimeExtensionInterface::class));

        foreach ((new ScalapayWidgetExtension())->getFunctions() as $function) {
            $callable = $function->getCallable();

            self::assertIsArray($callable, $function->getName());
            self::assertTrue(method_exists($callable[0], $callable[1]), $function->getName());
        }
    }
}
