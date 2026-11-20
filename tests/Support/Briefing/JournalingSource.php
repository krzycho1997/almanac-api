<?php

declare(strict_types=1);

namespace App\Tests\Support\Briefing;

use App\Briefing\Location;
use App\Briefing\Model\SourcePayload;
use App\Briefing\Source\PendingFetch;
use App\Briefing\Source\Source;
use App\Briefing\Source\SourceClient;
use App\Briefing\SourceName;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Request;

final readonly class JournalingSource implements Source, SourceClient
{
    use KeepsFinalSnapshotsByDate;

    private const string URL = 'https://upstream.test/resource';
    private const string BODY = '{}';
    private const string REQUEST = 'request ';
    private const string SETTLE = 'settle ';

    public function __construct(
        private SourceName $name,
        private CallJournal $journal,
        private ClockInterface $clock,
    ) {
    }

    public function name(): SourceName
    {
        return $this->name;
    }

    public function client(): SourceClient
    {
        return $this;
    }

    public function covers(\DateTimeImmutable $date): bool
    {
        return true;
    }

    public function request(\DateTimeImmutable $date, Location $location): PendingFetch
    {
        $this->journal->record(self::REQUEST.$this->name->value);
        $response = new MockHttpClient(new MockResponse(self::BODY))->request(Request::METHOD_GET, self::URL);

        return new PendingFetch([$response], $this->settle(...), $this->clock);
    }

    private function settle(): SourcePayload
    {
        $this->journal->record(self::SETTLE.$this->name->value);

        return SourcePayloads::of($this->name);
    }
}
