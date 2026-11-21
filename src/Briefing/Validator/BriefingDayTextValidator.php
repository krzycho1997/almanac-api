<?php

declare(strict_types=1);

namespace App\Briefing\Validator;

use App\Briefing\BriefingDay;
use Psr\Clock\ClockInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

final class BriefingDayTextValidator extends ConstraintValidator
{
    public function __construct(private readonly ClockInterface $clock)
    {
    }

    public function validate(mixed $value, Constraint $constraint): void
    {
        if ($constraint instanceof BriefingDayText) {
            $this->validateDay($value);

            return;
        }

        throw new UnexpectedTypeException($constraint, BriefingDayText::class);
    }

    private function validateDay(mixed $value): void
    {
        $day = $this->dayOf($value);

        if (null === $day) {
            return;
        }

        $this->context->getValidator()->inContext($this->context)->validate($day, new BriefingDate());
    }

    private function dayOf(mixed $value): ?\DateTimeImmutable
    {
        if (\is_string($value)) {
            return BriefingDay::startOf($value, $this->clock->now()->getTimezone());
        }

        return null;
    }
}
