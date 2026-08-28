<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\EventListener;

use Sylius\Bundle\UiBundle\Menu\Event\MenuBuilderEvent;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

#[AsEventListener(event: 'sylius.menu.shop.account', method: 'addAccountMenuItems')]
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
            ->setLabelAttribute('icon', 'tabler:credit-card')
        ;
    }
}
