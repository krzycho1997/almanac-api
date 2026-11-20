<?php

declare(strict_types=1);

namespace App\Briefing\ValueResolver;

use App\Briefing\BriefingDay;
use App\Briefing\Validator\BriefingDate;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsTargetedValueResolver;
use Symfony\Component\HttpKernel\Controller\ValueResolverInterface;
use Symfony\Component\HttpKernel\ControllerMetadata\ArgumentMetadata;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\Constraints\Date;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\ConstraintViolationListInterface;
use Symfony\Component\Validator\Exception\ValidationFailedException;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[AsTargetedValueResolver]
final readonly class BriefingDateValueResolver implements ValueResolverInterface
{
    private const string MIDNIGHT_FORMAT = '!'.BriefingDay::FORMAT;

    public function __construct(
        private ValidatorInterface $validator,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @return iterable<\DateTimeImmutable>
     */
    public function resolve(Request $request, ArgumentMetadata $argument): iterable
    {
        $name = $argument->getName();
        $value = $request->attributes->getString($name);
        $this->assertCalendarDate($value, $name);
        $date = $this->startOf($value);
        $this->assertWithinWindow($date, $name);

        return [$date];
    }

    private function assertCalendarDate(string $value, string $path): void
    {
        $violations = $this->violations($value, [new NotBlank(), new Date()], $path);

        if (0 === $violations->count()) {
            return;
        }

        throw new BadRequestHttpException('The briefing date is not a calendar date in Y-m-d.', new ValidationFailedException($value, $violations));
    }

    private function startOf(string $value): \DateTimeImmutable
    {
        $date = \DateTimeImmutable::createFromFormat(self::MIDNIGHT_FORMAT, $value, $this->clock->now()->getTimezone());

        if ($date instanceof \DateTimeImmutable) {
            return $date;
        }

        throw new \LogicException('A validated Y-m-d date could not be created.');
    }

    private function assertWithinWindow(\DateTimeImmutable $date, string $path): void
    {
        $violations = $this->violations($date, new BriefingDate(), $path);

        if (0 === $violations->count()) {
            return;
        }

        throw new UnprocessableEntityHttpException('The briefing date is outside the briefing window.', new ValidationFailedException($date, $violations));
    }

    /**
     * @param Constraint|list<Constraint> $constraints
     */
    private function violations(mixed $value, Constraint|array $constraints, string $path): ConstraintViolationListInterface
    {
        return $this->validator->startContext()->atPath($path)->validate($value, $constraints)->getViolations();
    }
}
