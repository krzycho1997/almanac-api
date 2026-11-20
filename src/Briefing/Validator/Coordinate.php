<?php

declare(strict_types=1);

namespace App\Briefing\Validator;

use Symfony\Component\Validator\Constraints as Assert;

abstract class Coordinate extends Assert\Compound
{
    private const string TEXT = 'string';
    private const string DECIMAL = '/^-?\d{1,3}(\.\d+)?$/D';
    private const string DECIMAL_MESSAGE = 'This value should be a number in plain decimal notation.';

    abstract protected function limit(): float;

    /**
     * @param array<string, mixed> $options
     *
     * @return list<Assert\Sequentially>
     */
    protected function getConstraints(array $options): array
    {
        return [new Assert\Sequentially([
            new Assert\Type(self::TEXT),
            new Assert\NotBlank(allowNull: true),
            new Assert\Regex(self::DECIMAL, message: self::DECIMAL_MESSAGE),
            new Assert\Range(min: -$this->limit(), max: $this->limit()),
        ])];
    }
}
