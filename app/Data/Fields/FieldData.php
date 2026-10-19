<?php

namespace App\Data\Fields;

use App\Core\Enum\FieldStatus;
use Spatie\LaravelData\Data;

class FieldData extends Data
{
    public function __construct(public string $name, public ?FieldStatus $status = null) {}
}
