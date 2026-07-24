<?php
declare(strict_types=1);

/**
 * Saito - The Threaded Web Forum
 *
 * @copyright Copyright (c) the Saito Project Developers
 * @link https://github.com/Schlaefer/Saito
 * @license http://opensource.org/licenses/MIT
 */

namespace Plugin\BbcodeParser\src\Lib\Http;

use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Message\RequestInterface;
use RuntimeException;

/**
 * PSR-18 network exception raised by {@see SsrfGuardedClient} when a request
 * target is refused (an internal/loopback/link-local host, or an unreachable
 * one). Modelled as a network failure so embed/embed treats it as a failed
 * fetch and the [embed] handler falls back to a plain link.
 */
class SsrfBlockedException extends RuntimeException implements NetworkExceptionInterface
{
    private RequestInterface $request;

    /**
     * @param \Psr\Http\Message\RequestInterface $request the request that was refused
     * @param string $message reason
     */
    public function __construct(RequestInterface $request, string $message)
    {
        parent::__construct($message);
        $this->request = $request;
    }

    /**
     * @inheritDoc
     */
    public function getRequest(): RequestInterface
    {
        return $this->request;
    }
}
