<?php

declare(strict_types=1);

namespace App\Tests\Briefing\Unit\DependencyInjection;

use App\Briefing\DependencyInjection\OneSourcePerNamePass;
use App\Briefing\Exception\SourceDefinitionException;
use App\Briefing\Source\Apod\ApodSource;
use App\Briefing\Source\OnThisDay\OnThisDaySource;
use App\Briefing\Source\Source;
use App\Briefing\Source\Weather\WeatherSource;
use App\Tests\Support\Briefing\AnotherApodSource;
use App\Tests\Support\Briefing\NamelessSource;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ChildDefinition;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Exception\InvalidArgumentException;

final class OneSourcePerNamePassTest extends TestCase
{
    public function testAcceptsOneDefinitionForEveryName(): void
    {
        // Given
        $container = self::containerWith(ApodSource::class, OnThisDaySource::class, WeatherSource::class);

        // Then / When
        $this->expectNotToPerformAssertions();
        new OneSourcePerNamePass()->process($container);
    }

    public function testRejectsANameWithoutADefinition(): void
    {
        // Given
        $container = self::containerWith(ApodSource::class, WeatherSource::class);

        // Then / When
        $this->expectExceptionObject(new SourceDefinitionException('Source name "on_this_day" needs exactly one App\Briefing\Source\Source service, found 0: [].'));
        new OneSourcePerNamePass()->process($container);
    }

    public function testRejectsANameWithTwoDefinitions(): void
    {
        // Given
        $container = self::containerWith(AnotherApodSource::class, OnThisDaySource::class, WeatherSource::class, ApodSource::class);

        // Then / When
        $this->expectExceptionObject(new SourceDefinitionException('Source name "apod" needs exactly one App\Briefing\Source\Source service, found 2: [App\Briefing\Source\Apod\ApodSource, App\Tests\Support\Briefing\AnotherApodSource].'));
        new OneSourcePerNamePass()->process($container);
    }

    public function testNamesBothServicesWhenOneClassIsRegisteredTwice(): void
    {
        // Given
        $container = self::containerWith(ApodSource::class, OnThisDaySource::class, WeatherSource::class);
        $container->setDefinition('second_apod', new Definition(ApodSource::class))->addTag(Source::class);

        // Then / When
        $this->expectExceptionObject(new SourceDefinitionException('Source name "apod" needs exactly one App\Briefing\Source\Source service, found 2: [App\Briefing\Source\Apod\ApodSource, second_apod (App\Briefing\Source\Apod\ApodSource)].'));
        new OneSourcePerNamePass()->process($container);
    }

    public function testReadsAClassGivenAsAParameter(): void
    {
        // Given
        $container = self::containerWith(ApodSource::class, OnThisDaySource::class, WeatherSource::class);
        $container->setParameter('second_apod.class', AnotherApodSource::class);
        $container->setDefinition('second_apod', new Definition('%second_apod.class%'))->addTag(Source::class);

        // Then / When
        $this->expectExceptionObject(new SourceDefinitionException('Source name "apod" needs exactly one App\Briefing\Source\Source service, found 2: [App\Briefing\Source\Apod\ApodSource, second_apod (App\Tests\Support\Briefing\AnotherApodSource)].'));
        new OneSourcePerNamePass()->process($container);
    }

    public function testRejectsAClassParameterThatIsNotAString(): void
    {
        // Given
        $container = self::containerWith(ApodSource::class, OnThisDaySource::class, WeatherSource::class);
        $container->setParameter('listed.class', [ApodSource::class]);
        $container->setDefinition('listed', new Definition('%listed.class%'))->addTag(Source::class);

        // Then / When
        $this->expectExceptionObject(new SourceDefinitionException('The service "listed" is tagged as App\Briefing\Source\Source but its class cannot be found.'));
        new OneSourcePerNamePass()->process($container);
    }

    public function testReadsTheClassOfAChildDefinitionFromItsParent(): void
    {
        // Given
        $container = self::containerWith(ApodSource::class, OnThisDaySource::class);
        $container->setDefinition('weather_template', new Definition(WeatherSource::class))->setAbstract(true);
        $container->setDefinition('weather', new ChildDefinition('weather_template'))->addTag(Source::class);

        // Then / When
        $this->expectNotToPerformAssertions();
        new OneSourcePerNamePass()->process($container);
    }

    public function testRejectsAnAbstractTaggedDefinition(): void
    {
        // Given
        $container = self::containerWith(ApodSource::class, OnThisDaySource::class, WeatherSource::class);
        $container->setDefinition('apod_template', new Definition(ApodSource::class))->setAbstract(true)->addTag(Source::class);

        // Then / When
        $this->expectExceptionObject(new InvalidArgumentException('The service "apod_template" tagged "App\Briefing\Source\Source" must not be abstract.'));
        new OneSourcePerNamePass()->process($container);
    }

    public function testRejectsATaggedServiceThatIsNotASource(): void
    {
        // Given
        $container = self::containerWith(ApodSource::class, OnThisDaySource::class, WeatherSource::class);
        $container->setDefinition('impostor', new Definition(\ArrayObject::class))->addTag(Source::class);

        // Then / When
        $this->expectExceptionObject(new SourceDefinitionException('The service "impostor" is tagged as App\Briefing\Source\Source but its class ArrayObject does not implement it.'));
        new OneSourcePerNamePass()->process($container);
    }

    public function testRejectsATaggedServiceWhoseClassDoesNotExist(): void
    {
        // Given
        $container = self::containerWith(ApodSource::class, OnThisDaySource::class, WeatherSource::class);
        $container->setDefinition('ghost', new Definition('App\Briefing\Source\Ghost\GhostSource'))->addTag(Source::class);

        // Then / When
        $this->expectExceptionObject(new SourceDefinitionException('The service "ghost" is tagged as App\Briefing\Source\Source but its class cannot be found.'));
        new OneSourcePerNamePass()->process($container);
    }

    public function testRejectsASourceThatDoesNotDeclareItsName(): void
    {
        // Given
        $container = self::containerWith(OnThisDaySource::class, WeatherSource::class, NamelessSource::class);

        // Then / When
        $this->expectExceptionObject(new SourceDefinitionException('The source class App\Tests\Support\Briefing\NamelessSource of the service "App\Tests\Support\Briefing\NamelessSource" must declare its name as the constant NAME of type App\Briefing\SourceName.'));
        new OneSourcePerNamePass()->process($container);
    }

    private static function containerWith(string ...$classes): ContainerBuilder
    {
        $container = new ContainerBuilder();

        foreach ($classes as $class) {
            $container->setDefinition($class, new Definition($class))->addTag(Source::class);
        }

        return $container;
    }
}
