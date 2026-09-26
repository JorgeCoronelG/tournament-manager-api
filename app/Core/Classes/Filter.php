<?php

namespace App\Core\Classes;

use App\Core\Enum\OperatorSql;

class Filter
{
    /**
     * @param  string  $boolean  Cómo se une con el filtro anterior: 'and' (por defecto) u 'or'
     */
    public function __construct(
        public string $field,
        public string|int|float|bool|null $value,
        public OperatorSql $operator,
        public string $boolean = 'and'
    ) {}
}
