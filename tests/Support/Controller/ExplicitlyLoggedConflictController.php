<?php

declare(strict_types=1);

namespace App\Tests\Support\Controller;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class ExplicitlyLoggedConflictController
{
    #[Route('/_test/explicitly-logged-conflict', name: 'test_explicitly_logged_conflict', methods: [Request::METHOD_GET])]
    public function __invoke(): Response
    {
        throw new ExplicitlyLoggedConflict();
    }
}
