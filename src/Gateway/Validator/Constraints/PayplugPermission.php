<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Gateway\Validator\Constraints;

use PayPlug\SyliusPayPlugPlugin\Const\Permission;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\Exception\ConstraintDefinitionException;

class PayplugPermission extends Constraint
{
    public string $permission;

    public string $message = 'payplug_sylius_payplug_plugin.permission.error';

    public function __construct(string $feature, ?string $message = null, ?array $groups = null, mixed $payload = null)
    {
        parent::__construct([], $groups, $payload);

        if (!Permission::isPermission($feature)) {
            throw new ConstraintDefinitionException(sprintf('The "%s" constraint requires the "feature" option to be set.', static::class));
        }
        $this->permission = $feature;
        $this->message = $message ?? $this->message;
    }
}
