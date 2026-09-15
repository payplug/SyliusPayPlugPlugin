<?php

declare(strict_types=1);

namespace Tests\PayPlug\SyliusPayPlugPlugin\PHPUnit\Support;

use Payplug\Core\IHttpRequest;

/**
 * Stands in for cURL inside the payplug-php SDK, via the public static `HttpClient::$REQUEST_HANDLER`
 * seam the SDK provides for this purpose. Lets a test drive code paths that call the SDK's static
 * API (`Authentication::createClientIdAndSecret()` and friends) without a network round trip.
 *
 * Every queued body is returned in turn; the last one repeats once the queue is exhausted, so a
 * caller that makes several identical calls need only queue one response.
 */
final class FakePayplugHttpRequest implements IHttpRequest
{
    /** @var list<string> */
    private array $bodies;

    private int $callCount = 0;

    /**
     * @param list<string> $bodies raw response bodies, in the order the SDK will request them
     */
    public function __construct(array $bodies, private int $httpStatus = 200)
    {
        $this->bodies = [] === $bodies ? ['{}'] : $bodies;
    }

    public function setopt($option, $value)
    {
        return true;
    }

    public function exec()
    {
        $body = $this->bodies[$this->callCount] ?? $this->bodies[\count($this->bodies) - 1];
        ++$this->callCount;

        return $body;
    }

    public function getinfo($option)
    {
        return $this->httpStatus;
    }

    public function close()
    {
    }

    public function error()
    {
        return '';
    }

    public function errno()
    {
        return 0;
    }

    public function callCount(): int
    {
        return $this->callCount;
    }
}
