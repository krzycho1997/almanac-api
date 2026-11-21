<?php

declare(strict_types=1);

namespace App\Tests\Architecture;

use App\Briefing\Contract\DiscoveryId;
use App\Briefing\Discovery\DiscoveryExtractor;
use App\Kernel;
use App\Shared\Contract\UidIdType;
use PHPat\Selector\Selector;
use PHPat\Selector\SelectorInterface;
use PHPat\Test\Attributes\TestRule;
use PHPat\Test\Builder\Rule;
use PHPat\Test\PHPat;

final class LayerTest
{
    private const string ENTITY = 'Entity';
    private const string REPOSITORY = 'Repository';

    #[TestRule]
    public function edgeOnlyTranslatesInputAndOutput(): Rule
    {
        return PHPat::rule()
            ->classes(self::edge())
            ->excluding(Selector::classname('App\Identity\Security\AccessTokenHandler'))
            ->shouldNot()
            ->dependOn()
            ->classes(
                Codebase::layer(self::REPOSITORY),
                self::doctrine(),
                self::httpClient(),
                Selector::classname('/^App\\\\Briefing\\\\Source\\\\.+Client$/', regex: true),
            )
            ->because('controllers, commands, handlers, resolvers, security adapters and HTTP listeners call services, not storage or upstream clients')
            ->nonIgnorable();
    }

    #[TestRule]
    public function servicesAndExceptionsDoNotKnowHttp(): Rule
    {
        return PHPat::rule()
            ->classes(
                Codebase::moduleRoot(),
                Selector::inNamespace('App\Briefing\CircuitBreaker'),
                Selector::inNamespace('App\Briefing\Discovery'),
                Codebase::layer('Exception'),
            )
            ->shouldNot()
            ->dependOn()
            ->classes(
                self::http(),
                self::edge(),
                Codebase::layer('Mapper', 'Dto\Request', 'Dto\Response'),
            )
            ->because('HTTP status codes come from framework.exceptions and response bodies from mappers')
            ->nonIgnorable();
    }

    #[TestRule]
    public function contractsDependOnlyOnContracts(): Rule
    {
        return PHPat::rule()
            ->classes(Codebase::contracts())
            ->canOnly()
            ->dependOn()
            ->classes(Codebase::outsideApp(), Codebase::contracts())
            ->because('a contract that exposes an internal class would open the module boundary')
            ->nonIgnorable();
    }

    #[TestRule]
    public function modelAndContractsStayPure(): Rule
    {
        return PHPat::rule()
            ->classes(Codebase::contracts(), Selector::inNamespace('App\Briefing\Model'))
            ->excluding(Selector::AllOf(Codebase::contracts(), Selector::AnyOf(Selector::classname(UidIdType::class), Selector::extends(UidIdType::class))))
            ->shouldNot()
            ->dependOn()
            ->classes(
                self::doctrine(),
                self::http(),
                Codebase::layer(self::ENTITY, self::REPOSITORY),
                Selector::inNamespace('App\Briefing\Source'),
            )
            ->because('the model and contracts carry data only; Doctrine id types built on UidIdType are the one intended exception')
            ->nonIgnorable();
    }

    #[TestRule]
    public function entitiesStayBelowServices(): Rule
    {
        return PHPat::rule()
            ->classes(Codebase::layer(self::ENTITY))
            ->shouldNot()
            ->dependOn()
            ->classes(
                self::http(),
                self::edge(),
                self::services(),
                Codebase::layer('Mapper', 'Dto', 'Source'),
            )
            ->because('entities are the storage model that services work with, so they know neither the services nor the edge')
            ->nonIgnorable();
    }

    /**
     * @return iterable<string, Rule>
     */
    #[TestRule]
    public function sourceInteriorsStayPrivate(): iterable
    {
        foreach (Codebase::sources() as $source) {
            $interior = Selector::inNamespace("App\\Briefing\\Source\\{$source}");

            yield ".{$source}" => PHPat::rule()
                ->classes(Selector::inNamespace('App'))
                ->excluding(Selector::inNamespace('App\Tests'), Selector::inNamespace('App\PHPStan'), $interior)
                ->shouldNot()
                ->dependOn()
                ->classes($interior)
                ->because("the rest of the code reaches {$source} only through Source, SourceClient and the internal model")
                ->nonIgnorable();
        }
    }

    #[TestRule]
    public function sourceInteriorsKnowNoWiring(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('/^App\\\\Briefing\\\\Source\\\\[^\\\\]+$/', regex: true))
            ->excluding(Selector::classname('/Source$/', regex: true))
            ->shouldNot()
            ->dependOn()
            ->classes(
                Selector::classname('App\Briefing\SourceName'),
                Selector::classname('App\Briefing\Source\Source'),
                Codebase::layer(self::ENTITY, self::REPOSITORY),
                Selector::inNamespace('App\Briefing\CircuitBreaker'),
            )
            ->because('a client fetches and maps; only its Source definition names it, and storage and the breaker belong to the aggregator')
            ->nonIgnorable();
    }

    #[TestRule]
    public function responseDtosOwnTheirTypes(): Rule
    {
        return PHPat::rule()
            ->classes(Codebase::layer('Dto\\Response'))
            ->shouldNot()
            ->dependOn()
            ->classes(
                Codebase::moduleRoot(),
                Codebase::layer('Model', self::ENTITY, self::REPOSITORY),
                Selector::inNamespace('App\Briefing\Source'),
            )
            ->because('the API contract changes only when the mapper says so, not when a domain enum or the model is renamed')
            ->nonIgnorable();
    }

    #[TestRule]
    public function modelCarriesReadonlyData(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('App\Briefing\Model'))
            ->excluding(Selector::isInterface(), Selector::isEnum())
            ->should()
            ->beReadonly()
            ->because('the internal model is stored in snapshots and read back, so it never changes after mapping')
            ->nonIgnorable();
    }

    #[TestRule]
    public function onlyTheExtractorMintsDiscoveryIds(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('App'))
            ->excluding(Selector::inNamespace('App\Tests'), Selector::inNamespace('App\PHPStan'), Selector::classname(DiscoveryExtractor::class))
            ->shouldNot()
            ->construct()
            ->classes(Selector::classname(DiscoveryId::class))
            ->because('a discovery id is the UUIDv5 of its key, so only the owner of the keys and the namespace makes one; others parse it with fromString()')
            ->nonIgnorable();
    }

    #[TestRule]
    public function classesAreFinal(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('App'))
            ->excluding(
                Selector::inNamespace('App\Tests'),
                Selector::inNamespace('App\PHPStan'),
                Selector::classname(Kernel::class),
                Codebase::layer(self::ENTITY),
                Selector::isAbstract(),
                Selector::isInterface(),
            )
            ->should()
            ->beFinal()
            ->because('classes are closed for inheritance unless Doctrine or an abstraction needs it')
            ->nonIgnorable();
    }

    private static function edge(): SelectorInterface
    {
        return Codebase::layer('Controller', 'Command', 'MessageHandler', 'ValueResolver', 'Security', 'ErrorHandler', 'RequestId');
    }

    private static function services(): SelectorInterface
    {
        return Selector::AllOf(Codebase::moduleRoot(), Selector::classname('/(?:Aggregator|Issuer|Prefetcher|Service)$/', regex: true));
    }

    private static function doctrine(): SelectorInterface
    {
        return Selector::AnyOf(Selector::inNamespace('Doctrine'), Selector::inNamespace('Symfony\Bridge\Doctrine'));
    }

    private static function http(): SelectorInterface
    {
        return Selector::AnyOf(
            Selector::inNamespace('Symfony\Component\HttpFoundation'),
            Selector::inNamespace('Symfony\Component\HttpKernel'),
            self::httpClient(),
        );
    }

    private static function httpClient(): SelectorInterface
    {
        return Selector::AnyOf(
            Selector::inNamespace('Symfony\Component\HttpClient'),
            Selector::inNamespace('Symfony\Contracts\HttpClient'),
        );
    }
}
