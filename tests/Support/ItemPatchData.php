<?php

namespace Tests\Support;

use Spatie\LaravelData\Data;
use Spatie\LaravelData\Optional;

/**
 * DTO para actualizaciones parciales: lo que no se envía queda como Optional
 * y Data::toArray() lo omite, así no pisa el valor guardado.
 */
class ItemPatchData extends Data
{
    public function __construct(
        public string|Optional $name,
        public int|null|Optional $status,
    ) {}
}
