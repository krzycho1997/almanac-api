<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Validator\Constraints\RangeValidator;
use Symfony\Component\Validator\ConstraintValidatorFactory;
use Symfony\Component\Validator\Validation;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final class ValidatorWithClock
{
    public static function create(ClockInterface $clock): ValidatorInterface
    {
        return Validation::createValidatorBuilder()
            ->setConstraintValidatorFactory(new ConstraintValidatorFactory([RangeValidator::class => new RangeValidator(clock: $clock)]))
            ->getValidator();
    }
}
