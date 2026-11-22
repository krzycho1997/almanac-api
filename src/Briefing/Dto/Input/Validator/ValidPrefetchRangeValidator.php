<?php

declare(strict_types=1);

namespace App\Briefing\Dto\Input\Validator;

use App\Briefing\BriefingDay;
use App\Briefing\DateRange;
use App\Briefing\Dto\Input\PrefetchInput;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Symfony\Component\Validator\Exception\UnexpectedValueException;

final class ValidPrefetchRangeValidator extends ConstraintValidator
{
    private const string UTC = 'UTC';
    private const string TO = 'to';

    public function validate(mixed $value, Constraint $constraint): void
    {
        if ($constraint instanceof ValidPrefetchRange) {
            $this->validateInput($value, $constraint);

            return;
        }

        throw new UnexpectedTypeException($constraint, ValidPrefetchRange::class);
    }

    private function validateInput(mixed $value, ValidPrefetchRange $constraint): void
    {
        if ($value instanceof PrefetchInput) {
            $this->validateRange($value, $constraint);

            return;
        }

        throw new UnexpectedValueException($value, PrefetchInput::class);
    }

    private function validateRange(PrefetchInput $input, ValidPrefetchRange $constraint): void
    {
        $from = BriefingDay::startOf($input->from, new \DateTimeZone(self::UTC));
        $to = BriefingDay::startOf($input->to, new \DateTimeZone(self::UTC));

        if (null === $from) {
            return;
        }

        if (null === $to) {
            return;
        }

        if ($to < $from) {
            $this->violate($constraint->endsBeforeItStartsMessage, ValidPrefetchRange::ENDS_BEFORE_IT_STARTS_ERROR);

            return;
        }

        if (\count(DateRange::between($from, $to)) > ValidPrefetchRange::MAX_DAYS) {
            $this->violate($constraint->tooLongMessage, ValidPrefetchRange::TOO_LONG_ERROR);
        }
    }

    private function violate(string $message, string $code): void
    {
        $this->context->buildViolation($message)->atPath(self::TO)->setCode($code)->addViolation();
    }
}
