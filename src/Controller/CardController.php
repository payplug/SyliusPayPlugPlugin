<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Controller;

use Payplug\Exception\NotFoundException;
use PayPlug\SyliusPayPlugPlugin\ApiClient\PayPlugApiClientInterface;
use PayPlug\SyliusPayPlugPlugin\Entity\Card;
use Sylius\Bundle\ResourceBundle\Doctrine\ORM\EntityRepository;
use Sylius\Component\Core\Model\CustomerInterface;
use Sylius\Component\Customer\Context\CustomerContextInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Flash\FlashBagInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

final class CardController extends AbstractController
{
    /**
     * @var CustomerContextInterface
     */
    private $customerContext;
    /**
     * @var EntityRepository
     */
    private $payplugCardRepository;
    /**
     * @var FlashBagInterface
     */
    private $flashBag;
    /**
     * @var TranslatorInterface
     */
    private $translator;
    /**
     * @var PayPlugApiClientInterface
     */
    private $payPlugApiClient;

    public function __construct(
        CustomerContextInterface $customerContext,
        EntityRepository $payplugCardRepository,
        FlashBagInterface $flashBag,
        TranslatorInterface $translator,
        PayPlugApiClientInterface $payPlugApiClient
    ) {
        $this->customerContext = $customerContext;
        $this->payplugCardRepository = $payplugCardRepository;
        $this->flashBag = $flashBag;
        $this->translator = $translator;
        $this->payPlugApiClient = $payPlugApiClient;
    }

    public function indexAction(): Response
    {
        $customer = $this->customerContext->getCustomer();

        if (!$customer instanceof CustomerInterface) {
            return $this->render('@PayPlugSyliusPayPlugPlugin/card/index.html.twig', [
                'savedCards' => [],
            ]);
        }

        $savedCards = $this->payplugCardRepository->findBy(['customer' => $customer], ['id' => 'DESC']);

        return $this->render('@PayPlugSyliusPayPlugPlugin/card/index.html.twig', [
            'savedCards' => $savedCards,
        ]);
    }

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

        if (true === $this->isCardExpired($card)) {
            $this->flashBag->add('error', $this->translator->trans('payplug_sylius_payplug_plugin.ui.account.saved_cards.deleted_error'));

            return $this->redirectToRoute('payplug_sylius_card_account_index');
        }

        $cardToken = $card->getExternalId();

        try {
            $this->payPlugApiClient->deleteCard($cardToken);
        } catch (NotFoundException $e) {
            $this->flashBag->add('error', $this->translator->trans('payplug_sylius_payplug_plugin.ui.account.saved_cards.deleted_error'));

            return $this->redirectToRoute('payplug_sylius_card_account_index');
        }

        $entityManager = $this->getDoctrine()->getManager();
        $entityManager->remove($card);
        $entityManager->flush();

        $this->flashBag->add('success', $this->translator->trans('sylius.ui.deleted'));

        return $this->redirectToRoute('payplug_sylius_card_account_index');
    }

    private function isCardExpired(Card $card): bool
    {
        $now = new \DateTime();
        $currentYear = $now->format("Y");
        $expirationYear = (string) $card->getExpirationYear();

        if (
            ($currentYear < $expirationYear) ||
            ($currentYear === $expirationYear && $now->format("n") <= (string) $card->getExpirationMonth())
        ){
            return false;
        }

        return true;
    }
}
