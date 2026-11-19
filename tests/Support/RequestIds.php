<?php

declare(strict_types=1);

namespace App\Tests\Support;

final class RequestIds
{
    public const string GENERATED = '/^[0-9a-f]{32}$/';
    public const string FROM_NGINX = 'f794e093b6e0487b3f796f9d39b2b120';
    public const string FROM_CLIENT = 'client-id_1.2';
}
