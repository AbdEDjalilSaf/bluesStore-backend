<?php

namespace App\Enums;

enum Era: string
{
    case Fifties = 'fifties';
    case Sixties = 'sixties';
    case Seventies = 'seventies';
    case Eighties = 'eighties';
    case Nineties = 'nineties';
    case TwoThousands = 'two_thousands';
    case TwentyTens = 'twenty_tens';
    case TwentyTwenties = 'twenty_twenties';

    /**
     * The inclusive year range covered by this era.
     *
     * @return array{0: int, 1: int}
     */
    public function range(): array
    {
        return match ($this) {
            self::Fifties => [1950, 1959],
            self::Sixties => [1960, 1969],
            self::Seventies => [1970, 1979],
            self::Eighties => [1980, 1989],
            self::Nineties => [1990, 1999],
            self::TwoThousands => [2000, 2009],
            self::TwentyTens => [2010, 2019],
            self::TwentyTwenties => [2020, 2029],
        };
    }

    public function contains(int $year): bool
    {
        [$start, $end] = $this->range();

        return $year >= $start && $year <= $end;
    }

    public function label(): string
    {
        [$start] = $this->range();

        return $start.'s';
    }
}
