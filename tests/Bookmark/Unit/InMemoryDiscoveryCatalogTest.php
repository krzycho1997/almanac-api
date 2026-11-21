<?php

declare(strict_types=1);

namespace App\Tests\Bookmark\Unit;

use App\Briefing\Contract\DiscoveryCatalog;
use App\Briefing\Contract\DiscoveryId;
use App\Briefing\Contract\DiscoveryType;
use App\Briefing\Contract\DiscoveryView;
use App\Tests\Support\Bookmark\InMemoryDiscoveryCatalog;
use App\Tests\Support\Briefing\DiscoveryCatalogContractTestCase;

final class InMemoryDiscoveryCatalogTest extends DiscoveryCatalogContractTestCase
{
    private const string FALLBACK_ONLY = '8c1f3a52-6d2e-5b7a-9f41-0e6c2d8b4a17';
    private const string FIND_MANY = 'findMany';
    private const string OWN_CAESAR_TITLE = 'Julius Caesar';

    public function testPrefersItsOwnViewsAndCompletesThemWithTheFallback(): void
    {
        // Given
        $fallback = new InMemoryDiscoveryCatalog();
        $fallback->add(self::fallbackOnly(), self::caesarAsTheFallbackKnowsHim());
        $catalog = new InMemoryDiscoveryCatalog($fallback);
        $catalog->add(...self::views());

        // When
        $found = $catalog->findMany(DiscoveryId::fromString(self::CAESAR), DiscoveryId::fromString(self::FALLBACK_ONLY), DiscoveryId::fromString(self::UNKNOWN));

        // Then
        self::assertEqualsCanonicalizing([self::CAESAR, self::FALLBACK_ONLY], array_keys($found));
        self::assertSame(self::OWN_CAESAR_TITLE, $found[self::CAESAR]->title);
        self::assertEquals(self::fallbackOnly(), $found[self::FALLBACK_ONLY]);
    }

    public function testFindsAViewOnlyTheFallbackKnows(): void
    {
        // Given
        $fallback = new InMemoryDiscoveryCatalog();
        $fallback->add(self::fallbackOnly());
        $catalog = new InMemoryDiscoveryCatalog($fallback);

        // When
        $view = $catalog->find(DiscoveryId::fromString(self::FALLBACK_ONLY));

        // Then
        self::assertEquals(self::fallbackOnly(), $view);
    }

    public function testAsksTheFallbackOnlyForTheIdsItDoesNotKnow(): void
    {
        // Given
        $fallback = $this->createMock(DiscoveryCatalog::class);
        $catalog = new InMemoryDiscoveryCatalog($fallback);
        $catalog->add(...self::views());

        // Then / When
        $fallback->expects(self::once())->method(self::FIND_MANY)->with(DiscoveryId::fromString(self::FALLBACK_ONLY), DiscoveryId::fromString(self::UNKNOWN))->willReturn([]);
        $catalog->findMany(DiscoveryId::fromString(self::CAESAR), DiscoveryId::fromString(self::FALLBACK_ONLY), DiscoveryId::fromString(self::UNKNOWN));
    }

    public function testLeavesTheFallbackAloneWhenItKnowsEveryId(): void
    {
        // Given
        $fallback = $this->createMock(DiscoveryCatalog::class);
        $catalog = new InMemoryDiscoveryCatalog($fallback);
        $catalog->add(...self::views());

        // Then / When
        $fallback->expects(self::never())->method(self::FIND_MANY);
        $catalog->findMany(DiscoveryId::fromString(self::CAESAR), DiscoveryId::fromString(self::EVENT));
    }

    protected function catalogWith(DiscoveryView ...$views): DiscoveryCatalog
    {
        $catalog = new InMemoryDiscoveryCatalog();
        $catalog->add(...$views);

        return $catalog;
    }

    private static function fallbackOnly(): DiscoveryView
    {
        return new DiscoveryView(DiscoveryId::fromString(self::FALLBACK_ONLY), DiscoveryType::Event, null, 'Only in the fallback', null, null);
    }

    private static function caesarAsTheFallbackKnowsHim(): DiscoveryView
    {
        return new DiscoveryView(DiscoveryId::fromString(self::CAESAR), DiscoveryType::Person, null, 'Gaius Julius Caesar', null, null);
    }
}
