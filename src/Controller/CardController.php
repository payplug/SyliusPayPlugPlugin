<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Controller;

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

    public function __construct(
        CustomerContextInterface $customerContext,
        EntityRepository $payplugCardRepository,
        FlashBagInterface $flashBag,
        TranslatorInterface $translator
    ) {
        $this->customerContext = $customerContext;
        $this->payplugCardRepository = $payplugCardRepository;
        $this->flashBag = $flashBag;
        $this->translator = $translator;
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

        return $this->render('@PayPlugSyliusPayPlugPlugin/card/index.html.twig', ['savedCards' => $savedCards]);
    }

    public function deleteAction(int $id): Response
    {
        //TODO
        return $this->redirectToRoute('payplug_sylius_card_account_index');
    }
}
