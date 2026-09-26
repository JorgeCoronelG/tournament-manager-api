<?php

namespace Tests\Feature\Core;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class RoleRelationTest extends TestCase
{
    use RefreshDatabase;

    public function test_roles_table_has_the_expected_columns(): void
    {
        $this->assertTrue(Schema::hasColumns('roles', ['id', 'nombre']));
        $this->assertFalse(Schema::hasColumn('roles', 'created_at'));
        $this->assertFalse(Schema::hasColumn('users', 'role_id'));
        $this->assertTrue(Schema::hasColumns('role_user', ['role_id', 'user_id']));
    }

    public function test_user_belongs_to_many_roles(): void
    {
        $role = Role::factory()->create(['nombre' => 'Administrador']);
        $user = User::factory()->create();
        $user->roles()->attach($role);

        $this->assertTrue($user->roles->contains($role));
        $this->assertSame('Administrador', $user->fresh()->roles->first()->nombre);
    }

    public function test_role_has_many_users(): void
    {
        $role = Role::factory()->create();
        $users = User::factory()->count(2)->create();
        $role->users()->attach($users);
        User::factory()->create();

        $this->assertCount(2, $role->users);
    }

    public function test_user_can_exist_without_a_role(): void
    {
        $user = User::factory()->create();

        $this->assertCount(0, $user->roles);
    }

    public function test_a_user_can_have_multiple_roles(): void
    {
        $roles = Role::factory()->count(2)->create();
        $user = User::factory()->create();

        $user->roles()->attach($roles);

        $this->assertCount(2, $user->fresh()->roles);
    }
}
