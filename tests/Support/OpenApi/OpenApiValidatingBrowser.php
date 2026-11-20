<?php

declare(strict_types=1);

namespace App\Tests\Support\OpenApi;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\BrowserKit\Response as BrowserResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\RouterInterface;

final class OpenApiValidatingBrowser extends KernelBrowser
{
    private const string ROUTE = '_route';
    private const string ROUTER = 'router';

    /**
     * @param Response $response
     */
    protected function filterResponse(object $response): BrowserResponse
    {
        $sent = parent::filterResponse($response);
        $request = $this->getRequest();
        new OpenApiContract(OpenApiDocument::instance())->assertHonoured($request, self::asSent($response, $sent), $this->routePath($request));

        return $sent;
    }

    private static function asSent(Response $response, BrowserResponse $sent): Response
    {
        return new Response($sent->getContent(), $response->getStatusCode(), $response->headers->all());
    }

    private function routePath(Request $request): ?string
    {
        $name = $request->attributes->get(self::ROUTE);

        if (\is_string($name)) {
            return $this->router()->getRouteCollection()->get($name)?->getPath();
        }

        return null;
    }

    private function router(): RouterInterface
    {
        return $this->getContainer()->get(self::ROUTER);
    }
}
