<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\EventListener;

use Sylius\Bundle\UiBundle\Menu\Event\MenuBuilderEvent;

final class AccountMenuListener
{
    public function addAccountMenuItems(MenuBuilderEvent $event): void
    {
        $menu = $event->getMenu();

        $menu->addChild('card', [
                'route' => 'payplug_sylius_card_account_index',
            ])
            ->setAttribute('type', 'link')
            ->setLabel('payplug_sylius_payplug_plugin.ui.account.saved_cards.title')
            ->setLabelAttribute('icon', 'credit card')
        ;
    }
}
