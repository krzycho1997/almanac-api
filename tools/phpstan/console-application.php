<?php

declare(strict_types=1);

use App\Kernel;
use Symfony\Bundle\FrameworkBundle\Console\Application;

require dirname(__DIR__, 2).'/tests/bootstrap.php';

return new Application(new Kernel('dev', true));
