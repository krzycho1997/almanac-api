<?php

declare(strict_types=1);

namespace App\Shared\Metrics;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Transport\Receiver\MessageCountAwareInterface;
use Symfony\Component\Messenger\Transport\TransportInterface;

final readonly class ScrapedMetrics implements MetricsExporter
{
    public function __construct(
        private PrometheusMetrics $metrics,
        #[Autowire(service: 'messenger.transport.failed')]
        private TransportInterface $failed,
    ) {
    }

    public function render(): string
    {
        $this->countFailedMessages();

        return $this->metrics->render();
    }

    private function countFailedMessages(): void
    {
        if ($this->failed instanceof MessageCountAwareInterface) {
            $this->metrics->gauge(Metric::MessengerFailedMessages, $this->failed->getMessageCount(), []);
        }
    }
}
