<?php

declare(strict_types=1);

namespace Tests\PayPlug\SyliusPayPlugPlugin\Behat\Page\Shop\Payum;

use Behat\Mink\Session;
use FriendsOfBehat\PageObjectExtension\Page\Page;
use Payum\Core\Security\TokenInterface;
use Sylius\Component\Resource\Repository\RepositoryInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

final class PaymentPage extends Page implements PaymentPageInterface
{
    /** @var RepositoryInterface */
    private $securityTokenRepository;

    /** @var KernelBrowser */
    private $client;

    public function __construct(
        Session $session,
        $parameters,
        RepositoryInterface $securityTokenRepository,
        KernelBrowser $client,
    ) {
        parent::__construct($session, $parameters);

        $this->securityTokenRepository = $securityTokenRepository;
        $this->client = $client;
    }

    public function capture(array $parameters = []): void
    {
        $captureToken = $this->findToken();

        $url = $captureToken->getTargetUrl();

        if (count($parameters) > 0) {
            $url .= '?' . http_build_query($parameters);
        }

        $this->getDriver()->visit($url);
    }

    public function notify(array $postData): void
    {
        $notifyToken = $this->findToken('notify');

        $this->client->request('POST', $notifyToken->getTargetUrl(), $postData);
    }

    protected function getUrl(array $urlParameters = []): string
    {
        return '';
    }

    private function findToken(string $type = 'capture'): TokenInterface
    {
        $tokens = [];
        /** @var TokenInterface $token */
        foreach ($this->securityTokenRepository->findAll() as $token) {
            if (strpos($token->getTargetUrl(), $type)) {
                $tokens[] = $token;
            }
        }
        if (count($tokens) > 0) {
            return end($tokens);
        }

        throw new \RuntimeException('Cannot find capture token, check if you are after proper checkout steps');
    }
}
