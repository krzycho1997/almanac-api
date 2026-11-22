<?php

declare(strict_types=1);

namespace App\PHPStan\Tests\Rule;

use App\PHPStan\Rule\DataCarrierRule;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;

/**
 * @extends RuleTestCase<DataCarrierRule>
 */
final class DataCarrierRuleTest extends RuleTestCase
{
    private const string FIXTURES = __DIR__.'/Fixtures/DataCarrier/';

    protected function getRule(): Rule
    {
        return new DataCarrierRule();
    }

    public function testReportsMethodsHooksTraitsAndConstructorLogicOfADtoOrMessage(): void
    {
        // When
        $errors = $this->gatherAnalyserErrors([self::FIXTURES.'carriers.php']);

        // Then
        self::assertSame([
            [14, 'App\Shop\Dto\Request\OrderQuery carries data only; move pageNumber() to a mapper or a validator.'],
            [29, 'App\Shop\Message\ShipOrderMessage carries data only; move of() to a mapper or a validator.'],
            [54, 'App\Shop\Dto\Response\PriceDto carries data only; it may not use a trait.'],
            [56, 'App\Shop\Dto\Response\PriceDto carries data only; move the hooks of $cents to a mapper or a validator.'],
            [61, 'App\Shop\Dto\Response\PriceDto carries data only; move the hooks of $currency to a mapper or a validator.'],
            [72, 'App\Shop\Dto\Response\OrderState carries data only; move label() to a mapper or a validator.'],
            [90, 'App\Shop\Dto\Request\SearchQuery carries data only; its constructor may only assign a parameter, a constant or a literal to a property.'],
            [92, 'App\Shop\Dto\Request\SearchQuery carries data only; its constructor may only assign a parameter, a constant or a literal to a property.'],
            [102, 'App\Shop\Message\Validator\RefundCheck carries data only; move allows() to a mapper or a validator.'],
            [120, 'App\Shop\Dto\Response\LabelDto carries data only; its constructor may only assign a parameter, a constant or a literal to a property.'],
            [121, 'App\Shop\Dto\Response\LabelDto carries data only; its constructor may only assign a parameter, a constant or a literal to a property.'],
            [122, 'App\Shop\Dto\Response\LabelDto carries data only; its constructor may only assign a parameter, a constant or a literal to a property.'],
            [123, 'App\Shop\Dto\Response\LabelDto carries data only; its constructor may only assign a parameter, a constant or a literal to a property.'],
            [131, 'App\Shop\Dto\Request\Validator\ValidSearchValidator carries data only; move validate() to a mapper or a validator.'],
        ], array_map(static fn ($error): array => [$error->getLine(), $error->getMessage()], $errors));
        self::assertSame(['almanac.dataCarrier'], array_values(array_unique(array_map(static fn ($error): ?string => $error->getIdentifier(), $errors))));
        self::assertFalse($errors[0]->canBeIgnored());
    }

    public function testAcceptsValidatorsInteractMethodsMappersHandlersAndServices(): void
    {
        // When
        $errors = $this->gatherAnalyserErrors([self::FIXTURES.'allowed.php']);

        // Then
        self::assertSame([], $errors);
    }
}
