<?php

declare(strict_types=1);

namespace App\Identity\Security;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\ErrorHandler\ErrorRenderer\ErrorRendererInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\Authentication\AuthenticationFailureHandlerInterface;
use Symfony\Component\Security\Http\EntryPoint\AuthenticationEntryPointInterface;

final readonly class ApiAuthenticationResponder implements AuthenticationEntryPointInterface, AuthenticationFailureHandlerInterface
{
    private const string MISSING_TOKEN_CHALLENGE = 'Bearer';
    private const string INVALID_TOKEN_CHALLENGE = 'Bearer error="invalid_token"';

    public function __construct(
        #[Autowire(service: 'error_renderer')]
        private ErrorRendererInterface $errorRenderer,
    ) {
    }

    public function start(Request $request, ?AuthenticationException $authException = null): Response
    {
        return $this->unauthorized(self::MISSING_TOKEN_CHALLENGE);
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): Response
    {
        return $this->unauthorized(self::INVALID_TOKEN_CHALLENGE);
    }

    private function unauthorized(string $challenge): Response
    {
        $problem = $this->errorRenderer->render(new UnauthorizedHttpException($challenge, Response::$statusTexts[Response::HTTP_UNAUTHORIZED]));

        return new Response($problem->getAsString(), $problem->getStatusCode(), $problem->getHeaders());
    }
}
