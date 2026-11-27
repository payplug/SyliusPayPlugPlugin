<?php

declare(strict_types=1);

namespace Tests\PayPlug\SyliusPayPlugPlugin\PHPUnit\Config;

use PayPlug\SyliusPayPlugPlugin\Gateway\PproPaymentMethods;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

final class PproTwigHooksConfigTest extends TestCase
{
    private const HOOKS_FILE = __DIR__ . '/../../../config/twig_hooks/admin.yaml';

    private const TEMPLATE = '@PayPlugSyliusPayPlugPlugin/admin/payment_method/form/ppro_eligibility.html.twig';

    public function testEveryPproGatewayHasTheEligibilityHook(): void
    {
        $hooks = Yaml::parseFile(self::HOOKS_FILE)['sylius_twig_hooks']['hooks'];

        foreach (array_keys(PproPaymentMethods::API_KEY_BY_FACTORY) as $factoryName) {
            foreach (['create', 'update'] as $action) {
                $hookName = sprintf('sylius_admin.payment_method.%s.content.form.sections.gateway_configuration.%s', $action, $factoryName);

                self::assertArrayHasKey($hookName, $hooks);
                self::assertSame(self::TEMPLATE, $hooks[$hookName]['ppro_eligibility']['template'] ?? null, $hookName);
            }
        }
    }

    public function testNonPproGatewaysDoNotHaveTheEligibilityHook(): void
    {
        $hooks = Yaml::parseFile(self::HOOKS_FILE)['sylius_twig_hooks']['hooks'];

        foreach (['payplug', 'payplug_oney', 'payplug_apple_pay', 'payplug_american_express'] as $factoryName) {
            foreach (['create', 'update'] as $action) {
                $hookName = sprintf('sylius_admin.payment_method.%s.content.form.sections.gateway_configuration.%s', $action, $factoryName);

                self::assertArrayNotHasKey('ppro_eligibility', $hooks[$hookName] ?? [], $hookName);
            }
        }
    }

    public function testTheScalapayAmountRangeHookIsGone(): void
    {
        self::assertStringNotContainsString('amount_range', (string) file_get_contents(self::HOOKS_FILE));
        self::assertStringNotContainsString('scalapay_amount_range', (string) file_get_contents(self::HOOKS_FILE));
    }
}
