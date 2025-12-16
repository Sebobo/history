<?php

declare(strict_types=1);

namespace AE\History\Domain\Dto;

final readonly class Change implements \JsonSerializable
{
    public function __construct(
        public string $propertyLabel,
        public mixed $oldValue,
        public mixed $newValue,
        public ChangeType $oldType,
        public ChangeType $newType,
        public string|array $diff,
    ) {
    }


    public function jsonSerialize(): array
    {
        return [
            'propertyLabel' => $this->propertyLabel,
            'oldValue' => $this->oldValue,
            'newValue' => $this->newValue,
            'oldType' => $this->oldType->value,
            'newType' => $this->newType->value,
            'diff' => $this->diff,
        ];
    }
}
