<?php

declare(strict_types=1);

namespace App\Tests\Architecture;

use App\Kernel;
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
            ->excluding(Selector::AllOf(Codebase::contracts(), Selector::classname('/IdType$/', regex: true)))
            ->shouldNot()
            ->dependOn()
            ->classes(
                self::doctrine(),
                self::http(),
                Codebase::layer(self::ENTITY, self::REPOSITORY),
                Selector::inNamespace('App\Briefing\Source'),
            )
            ->because('the model and contracts carry data only; Doctrine id types in contracts are the one intended exception')
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
