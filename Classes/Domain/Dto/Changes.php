<?php

declare(strict_types=1);

namespace AE\History\Domain\Dto;

use Traversable;

final readonly class Changes implements \IteratorAggregate
{
    /**
     * @param Change[] $changes
     */
    public function __construct(
        public iterable $changes,
    ) {
    }

    public static function empty(): self
    {
        return new self([]);
    }

    public function getIterator(): Traversable
    {
        yield from $this->changes;
    }
}
