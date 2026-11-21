<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Briefing\Validator\BriefingDayTextValidator;
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
            ->enableAttributeMapping()
            ->setConstraintValidatorFactory(new ConstraintValidatorFactory([
                RangeValidator::class => new RangeValidator(clock: $clock),
                BriefingDayTextValidator::class => new BriefingDayTextValidator($clock),
            ]))
            ->getValidator();
    }
}
