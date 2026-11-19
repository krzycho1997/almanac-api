<?php

declare(strict_types=1);

use App\Kernel;

require dirname(__DIR__, 2).'/tests/bootstrap.php';

$kernel = new Kernel('dev', true);
$kernel->boot();

return $kernel->getContainer()->get('doctrine')->getManager();
