<?php

namespace App\Core\Enum;

enum OperatorSql: string
{
    case CONTAIN = '%LIKE%';
    case NOT_CONTAIN = 'NOT %LIKE%';
    case EQUAL = '=';
    case NOT_EQUAL = '!=';
    case STARTS_WITH = 'LIKE%';
    case ENDS_WITH = '%LIKE';
    case IS_NULL = 'IS NULL';
    case NOT_NULL = 'IS NOT NULL';
    case GREATER_THAN = '>';
    case GREATER_THAN_OR_EQUAL = '>=';
    case LESS_THAN = '<';
    case LESS_THAN_OR_EQUAL = '<=';
}
