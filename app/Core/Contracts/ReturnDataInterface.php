<?php

namespace App\Core\Contracts;

use Spatie\LaravelData\Data;

interface ReturnDataInterface
{
    /**
     * @return Data|array<string, mixed>
     */
    public function toData(): Data|array;
}
