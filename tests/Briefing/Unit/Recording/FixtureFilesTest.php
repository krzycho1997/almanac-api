<?php

declare(strict_types=1);

namespace App\Tests\Briefing\Unit\Recording;

use App\Tests\Support\Briefing\Recording\Comparison;
use App\Tests\Support\Briefing\Recording\FixtureFiles;
use App\Tests\Support\Briefing\Recording\Recording;
use App\Tests\Support\Briefing\Recording\Trim;
use App\Tests\Support\Briefing\Recording\UpstreamFixture;
use PHPUnit\Framework\TestCase;

final class FixtureFilesTest extends TestCase
{
    private const string PATH = 'weather/day.json';
    private const string URL = 'https://upstream.test/day';
    private const string RECORDED = 'weather/recorded.json';
    private const array HEADERS = [
        'date' => ['Fri, 20 Nov 2026 17:27:00 GMT'],
        'content-type' => ['application/json'],
        'set-cookie' => ['GeoIP=PL:Warsaw'],
        'x-client-ip' => ['192.0.2.1'],
        'x-ratelimit-remaining' => ['593'],
    ];

    private string $root;
    private FixtureFiles $files;
    private UpstreamFixture $fixture;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/almanac-fixture-files-'.bin2hex(random_bytes(4)).'/';
        mkdir($this->root.'weather', 0o777, true);
        $this->files = new FixtureFiles($this->root);
        $this->fixture = new UpstreamFixture(self::PATH, self::URL, 200, Trim::None, Recording::Repeatable, Comparison::Exact, null);
    }

    protected function tearDown(): void
    {
        foreach (new \FilesystemIterator($this->root.'weather') as $file) {
            unlink((string) $file);
        }

        rmdir($this->root.'weather');
        rmdir($this->root);
    }

    public function testRecordsANewFixtureAndWhereItCameFrom(): void
    {
        // When
        $written = $this->files->record($this->fixture, self::json('{"temperature":1.0,"units":{}}'), 200, self::HEADERS);

        // Then
        self::assertTrue($written);
        self::assertSame("{\n    \"temperature\": 1.0,\n    \"units\": {}\n}\n", $this->files->contents(self::PATH));
        self::assertSame(FixtureFiles::encode(self::json('{"day.json":{"url":"https://upstream.test/day","status":200,"recordedOn":"2026-11-20","headers":{"content-type":"application/json","date":"Fri, 20 Nov 2026 17:27:00 GMT"}}}')), $this->files->contents(self::RECORDED));
    }

    public function testLeavesTheFilesAloneWhenOnlyVolatileFieldsOrKeyOrderChanged(): void
    {
        // Given
        $this->files->record($this->fixture, self::json('{"generationtime_ms":0.5,"a":1,"b":2}'), 200, self::HEADERS);
        $before = [$this->files->contents(self::PATH), $this->files->contents(self::RECORDED)];

        // When
        $written = $this->files->record($this->fixture, self::json('{"b":2,"generationtime_ms":0.9,"a":1}'), 200, ['date' => ['Sat, 21 Nov 2026 08:00:00 GMT']]);

        // Then
        self::assertFalse($written);
        self::assertSame($before, [$this->files->contents(self::PATH), $this->files->contents(self::RECORDED)]);
    }

    public function testRewritesADriftedFixtureAndItsRecord(): void
    {
        // Given
        $this->files->record($this->fixture, self::json('{"a":1}'), 200, self::HEADERS);

        // When
        $written = $this->files->record($this->fixture, self::json('{"a":1.0}'), 200, ['date' => ['Sat, 21 Nov 2026 08:00:00 GMT']]);

        // Then
        self::assertTrue($written);
        self::assertStringContainsString('"a": 1.0', $this->files->contents(self::PATH));
        self::assertStringContainsString('"recordedOn": "2026-11-21"', $this->files->contents(self::RECORDED));
    }

    public function testKeepsOtherRecordsWhenOneFixtureIsRecorded(): void
    {
        // Given
        $other = new UpstreamFixture('weather/night.json', 'https://upstream.test/night', 400, Trim::None, Recording::Frozen, Comparison::Exact, null);
        $this->files->record($other, self::json('{"error":true}'), 400, []);

        // When
        $this->files->record($this->fixture, self::json('{"a":1}'), 200, self::HEADERS);

        // Then
        self::assertSame(['day.json', 'night.json'], array_keys(get_object_vars($this->files->recorded('weather'))));
        self::assertStringContainsString("\"headers\": {}\n", $this->files->contents(self::RECORDED));
    }

    public function testComparesBodiesWhateverTheirKeyOrder(): void
    {
        // When
        $canonical = [FixtureFiles::canonical(self::json('{"b":{"y":1,"x":2},"a":[3]}')), FixtureFiles::canonical(self::json('{"a":[3],"b":{"x":2,"y":1}}'))];

        // Then
        self::assertSame($canonical[0], $canonical[1]);
    }

    private static function json(string $json): mixed
    {
        return json_decode($json, false, flags: \JSON_THROW_ON_ERROR);
    }
}
