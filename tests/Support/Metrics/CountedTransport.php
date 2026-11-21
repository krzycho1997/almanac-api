<?php

declare(strict_types=1);

namespace App\Tests\Support\Metrics;

use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\Receiver\MessageCountAwareInterface;
use Symfony\Component\Messenger\Transport\TransportInterface;

final readonly class CountedTransport implements TransportInterface, MessageCountAwareInterface
{
    public function __construct(
        private int $messages,
    ) {
    }

    public function get(): iterable
    {
        return [];
    }

    public function ack(Envelope $envelope): void
    {
    }

    public function reject(Envelope $envelope): void
    {
    }

    public function send(Envelope $envelope): Envelope
    {
        return $envelope;
    }

    public function getMessageCount(): int
    {
        return $this->messages;
    }
}
