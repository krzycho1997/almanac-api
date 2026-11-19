<?php

declare(strict_types=1);

namespace App\Shared\RequestId;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

final readonly class RequestIdListener
{
    public const string HEADER = 'X-Request-Id';
    private const string ACCEPTED_FORMAT = '/^[A-Za-z0-9._-]{1,64}$/D';
    private const int GENERATED_BYTES = 16;
    private const int BEFORE_REQUEST_VALIDATION = 4096;

    public function __construct(
        private RequestIdHolder $holder,
    ) {
    }

    #[AsEventListener(KernelEvents::REQUEST, priority: self::BEFORE_REQUEST_VALIDATION)]
    public function assign(RequestEvent $event): void
    {
        if ($event->isMainRequest()) {
            $this->holder->set(self::acceptedOrGenerated($event->getRequest()->headers->get(self::HEADER)));
        }
    }

    #[AsEventListener(KernelEvents::RESPONSE)]
    public function expose(ResponseEvent $event): void
    {
        if ($event->isMainRequest()) {
            $this->exposeOn($event->getResponse());
        }
    }

    private function exposeOn(Response $response): void
    {
        $requestId = $this->holder->get();

        if (null === $requestId) {
            return;
        }

        $response->headers->set(self::HEADER, $requestId);
    }

    private static function acceptedOrGenerated(?string $requestId): string
    {
        if (self::isAccepted($requestId)) {
            return $requestId;
        }

        return bin2hex(random_bytes(self::GENERATED_BYTES));
    }

    /**
     * @phpstan-assert-if-true string $requestId
     */
    private static function isAccepted(?string $requestId): bool
    {
        if (null === $requestId) {
            return false;
        }

        return 1 === preg_match(self::ACCEPTED_FORMAT, $requestId);
    }
}
