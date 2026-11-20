<?php

declare(strict_types=1);

namespace App\Briefing\Controller;

use App\Briefing\BriefingAggregator;
use App\Briefing\Dto\Response\BriefingResponse;
use App\Briefing\Exception\NoSourceDataException;
use App\Briefing\Location;
use App\Briefing\Mapper\BriefingResponseMapper;
use App\Briefing\ValueResolver\BriefingDateValueResolver;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\Serialize;
use Symfony\Component\HttpKernel\Attribute\ValueResolver;
use Symfony\Component\Routing\Attribute\Route;

final readonly class BriefingController
{
    public function __construct(
        private BriefingAggregator $aggregator,
        private BriefingResponseMapper $mapper,
        #[Target('defaultLocation')]
        private Location $defaultLocation,
    ) {
    }

    #[Route('/api/briefings/{date}', name: 'briefing_show', methods: [Request::METHOD_GET])]
    #[Serialize]
    public function __invoke(#[ValueResolver(BriefingDateValueResolver::class)] \DateTimeImmutable $date): BriefingResponse
    {
        $briefing = $this->aggregator->briefingFor($date, $this->defaultLocation);

        if ($briefing->hasAnyData()) {
            return $this->mapper->create($briefing);
        }

        throw NoSourceDataException::forBriefing();
    }
}
