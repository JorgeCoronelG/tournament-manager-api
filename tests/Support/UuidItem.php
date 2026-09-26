<?php

namespace Tests\Support;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class UuidItem extends Model
{
    use HasUuids;

    protected $table = 'uuid_items';

    protected $fillable = ['name'];
}
