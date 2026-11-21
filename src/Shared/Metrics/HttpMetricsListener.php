<?php

declare(strict_types=1);

namespace App\Shared\Metrics;

use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;

final readonly class HttpMetricsListener
{
    private const string ROUTE = '_route';
    private const string UNMATCHED = 'unmatched';
    private const string OTHER = 'other';
    private const string CODE_CLASS = '%dxx';
    private const int CODES_PER_CLASS = 100;
    private const string STARTED_AT = 'REQUEST_TIME_FLOAT';
    private const string SECONDS = 'U.u';
    private const array METHODS = [
        Request::METHOD_GET,
        Request::METHOD_HEAD,
        Request::METHOD_POST,
        Request::METHOD_PUT,
        Request::METHOD_PATCH,
        Request::METHOD_DELETE,
        Request::METHOD_OPTIONS,
    ];
    private const array CODES = [
        Response::HTTP_OK,
        Response::HTTP_CREATED,
        Response::HTTP_NO_CONTENT,
        Response::HTTP_NOT_MODIFIED,
        Response::HTTP_BAD_REQUEST,
        Response::HTTP_UNAUTHORIZED,
        Response::HTTP_FORBIDDEN,
        Response::HTTP_NOT_FOUND,
        Response::HTTP_METHOD_NOT_ALLOWED,
        Response::HTTP_NOT_ACCEPTABLE,
        Response::HTTP_CONFLICT,
        Response::HTTP_REQUEST_ENTITY_TOO_LARGE,
        Response::HTTP_UNSUPPORTED_MEDIA_TYPE,
        Response::HTTP_UNPROCESSABLE_ENTITY,
        Response::HTTP_TOO_MANY_REQUESTS,
        Response::HTTP_INTERNAL_SERVER_ERROR,
        Response::HTTP_BAD_GATEWAY,
        Response::HTTP_SERVICE_UNAVAILABLE,
    ];

    public function __construct(
        private Metrics $metrics,
        #[Autowire(service: 'app.metrics.clock')]
        private ClockInterface $clock,
    ) {
    }

    #[AsEventListener(KernelEvents::TERMINATE)]
    public function record(TerminateEvent $event): void
    {
        $request = $event->getRequest();

        $this->metrics->increment(Metric::HttpRequests, self::labelsOf($request, $event->getResponse()));
        $this->metrics->observe(Metric::HttpRequestDuration, $this->secondsSince($request), [MetricLabel::Route->value => self::routeOf($request)]);
    }

    /**
     * @return array<string, string>
     */
    private static function labelsOf(Request $request, Response $response): array
    {
        return [
            MetricLabel::Route->value => self::routeOf($request),
            MetricLabel::Method->value => self::methodOf($request),
            MetricLabel::Code->value => self::codeOf($response),
        ];
    }

    private static function routeOf(Request $request): string
    {
        $route = $request->attributes->get(self::ROUTE);

        if (\is_string($route)) {
            return $route;
        }

        return self::UNMATCHED;
    }

    private static function methodOf(Request $request): string
    {
        $method = $request->getMethod();

        if (\in_array($method, self::METHODS, true)) {
            return $method;
        }

        return self::OTHER;
    }

    private static function codeOf(Response $response): string
    {
        $code = $response->getStatusCode();

        if (\in_array($code, self::CODES, true)) {
            return (string) $code;
        }

        return \sprintf(self::CODE_CLASS, intdiv($code, self::CODES_PER_CLASS));
    }

    private function secondsSince(Request $request): float
    {
        $startedAt = (float) $request->server->getString(self::STARTED_AT);

        return max(0.0, (float) $this->clock->now()->format(self::SECONDS) - $startedAt);
    }
}
