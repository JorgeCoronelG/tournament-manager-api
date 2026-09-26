<?php

namespace Tests\Support;

use Spatie\LaravelData\Data;

class ItemData extends Data
{
    public function __construct(
        public string $name,
        public ?int $status = 1,
    ) {}
}
