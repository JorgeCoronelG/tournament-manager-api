<?php

namespace Tests\Feature\Core;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SoftDeletesTest extends TestCase
{
    use RefreshDatabase;

    public function test_users_and_roles_tables_have_a_deleted_at_column(): void
    {
        $this->assertTrue(Schema::hasColumn('users', 'deleted_at'));
        $this->assertTrue(Schema::hasColumn('roles', 'deleted_at'));
    }

    public function test_deleting_a_user_keeps_the_row_and_sets_deleted_at(): void
    {
        $user = User::factory()->create();

        $user->delete();

        $this->assertSoftDeleted($user);
        $this->assertNull(User::find($user->id));
        $this->assertNotNull(User::withTrashed()->find($user->id));
    }

    public function test_deleting_a_role_keeps_the_row_and_sets_deleted_at(): void
    {
        $role = Role::factory()->create();

        $role->delete();

        $this->assertSoftDeleted($role);
        $this->assertNull(Role::find($role->id));
        $this->assertNotNull(Role::withTrashed()->find($role->id));
    }

    public function test_a_soft_deleted_user_can_be_restored(): void
    {
        $user = User::factory()->create();
        $user->delete();

        $user->restore();

        $this->assertNotSoftDeleted($user);
        $this->assertNotNull(User::find($user->id));
    }
}
