<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Controller;

use Doctrine\Persistence\ManagerRegistry;
use Payplug\Exception\NotFoundException;
use PayPlug\SyliusPayPlugPlugin\ApiClient\PayPlugApiClientFactoryInterface;
use PayPlug\SyliusPayPlugPlugin\Entity\Card;
use PayPlug\SyliusPayPlugPlugin\Entity\CardsOwnerInterface;
use Sylius\Bundle\ResourceBundle\Doctrine\ORM\EntityRepository;
use Sylius\Component\Core\Model\CustomerInterface;
use Sylius\Component\Customer\Context\CustomerContextInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

#[AsController]
final class CardController extends AbstractController
{
    public function __construct(
        private CustomerContextInterface $customerContext,
        private EntityRepository $payplugCardRepository,
        private TranslatorInterface $translator,
        private PayPlugApiClientFactoryInterface $apiClientFactory,
        private RequestStack $requestStack,
        private ManagerRegistry $managerRegistry,
    ) {
    }

    #[Route(path: '/{_locale}/account/saved-cards', name: 'payplug_sylius_card_account_index', methods: ['GET'])]
    public function indexAction(): Response
    {
        $customer = $this->customerContext->getCustomer();

        $savedCards = [];
        if ($customer instanceof CardsOwnerInterface) {
            $savedCards = $customer->getCards();
        }

        return $this->render('@PayPlugSyliusPayPlugPlugin/shop/saved_cards/index.html.twig', [
            'savedCards' => $savedCards,
        ]);
    }

    #[Route(path: '/{_locale}/account/saved-cards/delete/{id}', name: 'payplug_sylius_card_account_delete', methods: ['DELETE'])]
    public function deleteAction(int $id): Response
    {
        $customer = $this->customerContext->getCustomer();

        if (!$customer instanceof CustomerInterface) {
            return $this->redirectToRoute('sylius_shop_login');
        }

        $card = $this->payplugCardRepository->findOneBy(['id' => $id, 'customer' => $customer]);

        if (!$card instanceof Card) {
            return $this->redirectToRoute('payplug_sylius_card_account_index');
        }

        if ($this->isCardExpired($card)) {
            $this->removeCard($card);

            return $this->redirectToRoute('payplug_sylius_card_account_index');
        }

        $cardToken = $card->getExternalId();

        try {
            // Signed with the account the card was saved on, not an arbitrary CB config: PayPlug
            // card tokens are account-scoped, and since PRE-3628 a customer may hold cards from
            // several channels, each on a different account. Resolving from the ambient channel
            // context would be wrong for exactly that reason — a Sylius customer spans channels,
            // so the card's own payment method is the only correct source.
            $this->apiClientFactory->createForPaymentMethod($card->getPaymentMethod())->deleteCard($cardToken);
        } catch (NotFoundException) {
            $this->requestStack->getSession()->getFlashBag()->add('error', $this->translator->trans('payplug_sylius_payplug_plugin.ui.account.saved_cards.deleted_error'));

            return $this->redirectToRoute('payplug_sylius_card_account_index');
        }

        $this->removeCard($card);

        return $this->redirectToRoute('payplug_sylius_card_account_index');
    }

    private function removeCard(Card $card): void
    {
        $entityManager = $this->managerRegistry->getManager();
        $entityManager->remove($card);
        $entityManager->flush();

        $this->requestStack->getSession()->getFlashBag()->add('success', $this->translator->trans('payplug_sylius_payplug_plugin.ui.account.saved_cards.deleted_successfully'));
    }

    private function isCardExpired(Card $card): bool
    {
        $now = new \DateTime();
        $currentYear = $now->format('Y');
        $expirationYear = (string) $card->getExpirationYear();

        return $currentYear >= $expirationYear && !($currentYear === $expirationYear && $now->format('n') <= (string) $card->getExpirationMonth());
    }
}
