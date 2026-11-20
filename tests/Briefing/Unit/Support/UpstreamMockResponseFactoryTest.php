<?php

declare(strict_types=1);

namespace App\Tests\Briefing\Unit\Support;

use App\Tests\Support\Briefing\UpstreamMock;
use App\Tests\Support\Briefing\UpstreamMockResponseFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class UpstreamMockResponseFactoryTest extends TestCase
{
    private const string NASA = 'https://api.nasa.test/';
    private const string WIKIPEDIA = 'https://wikipedia.test/';

    public function testCountsThePlannedResponsesNobodyRequested(): void
    {
        // Given
        $factory = new UpstreamMockResponseFactory([['upstream' => 'nasa', 'baseUri' => self::NASA], ['upstream' => 'wikipedia', 'baseUri' => self::WIKIPEDIA]]);
        $factory->respondWith(UpstreamMock::create()->apod()->apod()->onThisDay());

        // When
        $factory(Request::METHOD_GET, self::NASA.'planetary/apod', []);

        // Then
        self::assertSame(['nasa' => 1, 'wikipedia' => 1], $factory->unserved());
    }

    public function testReportsNothingOnceEveryPlannedResponseWasRequested(): void
    {
        // Given
        $factory = new UpstreamMockResponseFactory([['upstream' => 'nasa', 'baseUri' => self::NASA]]);
        $factory->respondWith(UpstreamMock::create()->apod());

        // When
        $factory(Request::METHOD_GET, self::NASA.'planetary/apod', []);

        // Then
        self::assertSame([], $factory->unserved());
    }
}
