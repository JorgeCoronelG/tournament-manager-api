<?php

namespace App\Data\Users;

use Spatie\LaravelData\Data;

class CreateUserData extends Data
{
    /**
     * @param  list<int>  $roles
     */
    public function __construct(
        public string $first_name,
        public string $last_name,
        public string $email,
        public ?string $phone,
        public array $roles,
    ) {}
}
