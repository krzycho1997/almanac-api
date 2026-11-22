<?php

declare(strict_types=1);

namespace App\Identity\Validator;

use App\Identity\Dto\Input\CreateUserInput;
use App\Identity\TokenLifetime;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Symfony\Component\Validator\Exception\UnexpectedValueException;

final class ValidTokenLifetimeValidator extends ConstraintValidator
{
    private const string TTL = 'ttl';

    public function validate(mixed $value, Constraint $constraint): void
    {
        if ($constraint instanceof ValidTokenLifetime) {
            $this->validateInput($value, $constraint);

            return;
        }

        throw new UnexpectedTypeException($constraint, ValidTokenLifetime::class);
    }

    private function validateInput(mixed $value, ValidTokenLifetime $constraint): void
    {
        if ($value instanceof CreateUserInput) {
            $this->validateLifetime($value->ttl, $constraint);

            return;
        }

        throw new UnexpectedValueException($value, CreateUserInput::class);
    }

    private function validateLifetime(string $ttl, ValidTokenLifetime $constraint): void
    {
        if (TokenLifetime::isWellFormed($ttl)) {
            $this->violateWhenOutOfRange($ttl, $constraint);
        }
    }

    private function violateWhenOutOfRange(string $ttl, ValidTokenLifetime $constraint): void
    {
        if (null === TokenLifetime::parse($ttl)) {
            $this->context->buildViolation($constraint->message)->atPath(self::TTL)->setCode(ValidTokenLifetime::TOO_LONG_ERROR)->addViolation();
        }
    }
}
