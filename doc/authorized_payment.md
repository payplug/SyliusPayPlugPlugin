# Authorized Payment

This feature allow merchant to deferred the capture of the payment. 
The payment is authorized and the capture can be done later.

> [!IMPORTANT]
> The authorized payment feature is only available for the "PayPlug" payment gateway.

## Activation

On the payment method configuration, you can enable the deferred capture feature.

![admin_deferred_capture_feature.png](images/admin_deferred_capture_feature.png)

## Trigger the capture

### Periodically

An authorized payment is valid for 7 days.
You can trigger the capture of the authorized payment by running the following command:

```bash
$ bin/console payplug:capture-authorized-payments --days=6
```

It will capture all authorized payments that are older than 6 days.

> [!TIP]
> You can add this command to a cron job to automate the capture of the authorized payments.

### Programmatically

An authorized payment is in state `AUTHORIZED`. 
A capture trigger is placed on the complete transition for such payments.

```yaml
winzou_state_machine:
    sylius_payment:
        callbacks:
            before:
                payplug_sylius_payplug_plugin_complete:
                    on: ["complete"]
                    do: ["@payplug_sylius_payplug_plugin.payment_processing.capture", "process"]
                    args: ["object"]
```
> [!NOTE]
> This configuration is already added by the plugin.

### With Winzou State Machine

For example, if you want to trigger the capture when an order is shipped, you can create a callback on the `sylius_order_shipping` state machine.

File: `config/packages/winzou_state_machine.yaml`

```yaml 
winzou_state_machine:
    sylius_order_shipping:
        callbacks:
            before:
                app_ensure_capture_payment:
                    on: ["ship"]
                    do: ['@App\StateMachine\CaptureOrderProcessor', "process"]
                    args: ["object"]
```

File : `src/StateMachine/CaptureOrderProcessor.php`

```php
<?php

declare(strict_types=1);

namespace App\StateMachine;

use SM\Factory\Factory;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Payment\PaymentTransitions;
use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;

#[Autoconfigure(public: true)] // make the service public to be callable by winzou_state_machine
class CaptureOrderProcessor
{
    public function __construct(private Factory $stateMachineFactory) {}
    
    public function process(OrderInterface $order): void
    {
        $payment = $order->getLastPayment(PaymentInterface::STATE_AUTHORIZED);
        if (null === $payment) {
            // No payment in authorized state, nothing to do here
            return;
        }

        $this->stateMachineFactory
            ->get($payment, PaymentTransitions::GRAPH)
            ->apply(PaymentTransitions::TRANSITION_COMPLETE);

        if (PaymentInterface::STATE_COMPLETED !== $payment->getState()) {
            throw new \LogicException('Oh no! Payment capture failed 💸');
        }
    }
}
```

### With Symfony Workflow (default in Sylius 2)

If you are using Symfony Workflow, you can create a custom action to capture the payment, with a transition listener.

File: `src/StateMachine/CaptureOrderProcessor.php`

```php
<?php

declare(strict_types=1);

namespace App\StateMachine;

use Sylius\Abstraction\StateMachine\StateMachineInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Core\OrderShippingTransitions;
use Sylius\Component\Payment\PaymentTransitions;
use Symfony\Component\Workflow\Attribute\AsTransitionListener;
use Symfony\Component\Workflow\Event\TransitionEvent;

final class CaptureOrderProcessor
{
    public function __construct(private StateMachineInterface $stateMachine)
    {
    }

    #[AsTransitionListener(
        workflow: OrderShippingTransitions::GRAPH,
        transition: OrderShippingTransitions::TRANSITION_SHIP,
    )]
    public function onShip(TransitionEvent $event): void
    {
        $order = $event->getSubject();
        if (!$order instanceof OrderInterface) {
            throw new \LogicException('Expected an instance of OrderInterface');
        }

        $payment = $order->getLastPayment(PaymentInterface::STATE_AUTHORIZED);
        if (null === $payment) {
            // No payment in authorized state, nothing to do here
            return;
        }

        $this->stateMachine->apply($payment, PaymentTransitions::GRAPH, PaymentTransitions::TRANSITION_COMPLETE);
        if (PaymentInterface::STATE_COMPLETED !== $payment->getState()) {
            throw new \LogicException('Oh no! Payment capture failed 💸');
        }
    }
}
```
