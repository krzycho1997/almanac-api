<?php

declare(strict_types=1);

namespace App\Tests\Bookmark\Functional;

use App\Briefing\Contract\DiscoveryType;
use App\Tests\Support\Briefing\UpstreamMock;
use App\Tests\Support\Briefing\UpstreamMockResponseFactory;
use App\Tests\Support\FunctionalTestCase;
use App\Tests\Support\Identity\IssuesAccessTokens;
use App\Tests\Support\InteractsWithTestServices;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class BookmarkFromBriefingTest extends FunctionalTestCase
{
    use InteractsWithTestServices;
    use IssuesAccessTokens;

    private const string BRIEFING = '/api/briefings/2024-03-15';
    private const string BOOKMARKS = '/api/bookmarks';
    private const string ADA = 'ada@example.com';
    private const string CONTENT_TYPE = 'CONTENT_TYPE';
    private const string JSON = 'application/json';
    private const string DISCOVERY_ID = 'discoveryId';
    private const string APOD = 'apod';
    private const string DEATHS = 'deaths';
    private const string DATA = 'data';
    private const string DISCOVERY = 'discovery';
    private const string TYPE = 'type';
    private const string DATE = 'date';
    private const string TITLE = 'title';

    public function testBookmarksThePictureAndAPersonOfARealBriefing(): void
    {
        // Given
        self::testService(UpstreamMockResponseFactory::class)->respondWith(
            UpstreamMock::create()->onThisDayFrom('03-15')->archiveOn('2024-03-15')->apod()->fixture('apod/passthrough-2024-03-15.json'),
        );
        $this->client->request(Request::METHOD_GET, self::BRIEFING);
        $briefing = $this->json();
        [, $authorization] = self::signedIn(self::ADA);
        $this->bookmark(self::discoveryIdOf($briefing, self::APOD), $authorization);
        $this->bookmark(self::lastDeathOf($briefing), $authorization);

        // When
        $this->client->request(Request::METHOD_GET, self::BOOKMARKS, server: $authorization);

        // Then
        self::assertResponseIsSuccessful();
        $list = $this->json();
        self::assertIsArray($list[self::DATA] ?? null);
        self::assertSame(
            [[DiscoveryType::Person->value, null, 'Julius Caesar'], [DiscoveryType::Apod->value, '2024-03-15', 'Portrait of NGC 1055']],
            array_map(self::typeDateAndTitle(...), $list[self::DATA]),
        );
    }

    /**
     * @param array<string, string> $authorization
     */
    private function bookmark(string $discoveryId, array $authorization): void
    {
        $this->client->request(Request::METHOD_POST, self::BOOKMARKS, server: [self::CONTENT_TYPE => self::JSON] + $authorization, content: json_encode([self::DISCOVERY_ID => $discoveryId], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
    }

    /**
     * @param array<mixed> $briefing
     */
    private static function discoveryIdOf(array $briefing, string $section): string
    {
        self::assertIsArray($briefing[$section] ?? null);
        self::assertIsString($briefing[$section][self::DISCOVERY_ID] ?? null);

        return $briefing[$section][self::DISCOVERY_ID];
    }

    /**
     * @param array<mixed> $briefing
     */
    private static function lastDeathOf(array $briefing): string
    {
        self::assertIsArray($briefing[self::DEATHS] ?? null);
        $death = end($briefing[self::DEATHS]);
        self::assertIsArray($death);
        self::assertIsString($death[self::DISCOVERY_ID] ?? null);

        return $death[self::DISCOVERY_ID];
    }

    /**
     * @return list<mixed>
     */
    private static function typeDateAndTitle(mixed $bookmark): array
    {
        self::assertIsArray($bookmark);
        self::assertIsArray($bookmark[self::DISCOVERY] ?? null);

        return [$bookmark[self::DISCOVERY][self::TYPE] ?? null, $bookmark[self::DISCOVERY][self::DATE] ?? null, $bookmark[self::DISCOVERY][self::TITLE] ?? null];
    }

    /**
     * @return array<mixed>
     */
    private function json(): array
    {
        $decoded = json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }
}
