<?php

namespace Tests\Feature;

use App\Models\AuditEntry;
use App\Models\Tank;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AuditTrailTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_changes_are_recorded_with_actor_and_previous_values(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $tank = Tank::create(['name' => 'Original', 'capacity_ml' => 100000]);
        $tank->update(['name' => 'Renamed']);

        $entry = AuditEntry::where('subject_type', 'Tank')->where('action', 'updated')->sole();
        $this->assertSame('Tank storage', $entry->stage);
        $this->assertSame($user->id, $entry->actor_id);
        $this->assertSame('Original', $entry->old_values['name']);
        $this->assertSame('Renamed', $entry->new_values['name']);
        $this->get('/admin/audit-entries')->assertOk()->assertSee('Audits');
    }

    public function test_rolled_back_changes_do_not_leave_audit_entries(): void
    {
        DB::beginTransaction();
        Tank::create(['name' => 'Rolled back', 'capacity_ml' => 100000]);
        DB::rollBack();

        $this->assertDatabaseCount('audit_entries', 0);
    }

    public function test_audits_require_authentication(): void
    {
        $this->get('/admin/audit-entries')->assertRedirect('/admin/login');
    }

    public function test_user_changes_are_audited_without_credentials(): void
    {
        $actor = User::factory()->create();
        $this->actingAs($actor);
        $user = User::factory()->create(['name' => 'Original user', 'email' => 'original@example.test']);
        $user->update(['name' => 'Updated user', 'password' => 'new-secret-value']);

        $entry = AuditEntry::where('subject_type', 'User')->where('subject_id', $user->id)->where('action', 'updated')->sole();
        $this->assertSame($actor->id, $entry->actor_id);
        $this->assertSame('Original user', $entry->old_values['name']);
        $this->assertSame('Updated user', $entry->new_values['name']);
        $this->assertTrue($entry->new_values['password_changed']);
        $this->assertArrayNotHasKey('password', $entry->new_values);
        $this->assertArrayNotHasKey('password', $entry->old_values);
        $this->assertStringNotContainsString('new-secret-value', $entry->toJson());
    }

    public function test_deletion_is_audited_with_previous_values(): void
    {
        $actor = User::factory()->create();
        $this->actingAs($actor);
        $tank = Tank::create(['name' => 'Removed tank', 'capacity_ml' => 100000]);
        $tank->delete();

        $entry = AuditEntry::where('subject_type', 'Tank')->where('subject_id', $tank->id)->where('action', 'deleted')->sole();
        $this->assertSame($actor->id, $entry->actor_id);
        $this->assertSame('Removed tank', $entry->old_values['name']);
    }
}
