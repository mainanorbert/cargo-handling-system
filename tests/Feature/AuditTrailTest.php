<?php

namespace Tests\Feature;

use App\Filament\Resources\AuditEntries\Pages\ListAuditEntries;
use App\Models\AuditEntry;
use App\Models\Tank;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
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
        $this->get('/admin/audit-entries')->assertOk()->assertSee('Audits')->assertSee('Reports & Audits');
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

    public function test_audits_show_actor_emails_and_filter_by_actor_and_recorded_time(): void
    {
        $viewer = User::factory()->create();
        $actor = User::factory()->create(['name' => 'Cargo Operator', 'email' => 'operator@example.test']);
        $otherActor = User::factory()->create(['name' => 'Other Operator', 'email' => 'other@example.test']);
        $older = AuditEntry::create([
            'stage' => 'Tank storage', 'action' => 'created', 'subject_type' => 'Tank', 'subject_id' => 1,
            'actor_id' => $actor->id, 'actor_name' => $actor->name, 'old_values' => [], 'new_values' => [],
            'created_at' => '2026-11-01 09:00:00',
        ]);
        $matching = AuditEntry::create([
            'stage' => 'Tank storage', 'action' => 'updated', 'subject_type' => 'Tank', 'subject_id' => 1,
            'actor_id' => $actor->id, 'actor_name' => $actor->name, 'old_values' => [], 'new_values' => [],
            'created_at' => '2026-11-02 09:00:00',
        ]);
        $other = AuditEntry::create([
            'stage' => 'Tank storage', 'action' => 'deleted', 'subject_type' => 'Tank', 'subject_id' => 1,
            'actor_id' => $otherActor->id, 'actor_name' => $otherActor->name, 'old_values' => [], 'new_values' => [],
            'created_at' => '2026-11-02 09:00:00',
        ]);

        $this->actingAs($viewer);

        Livewire::test(ListAuditEntries::class)
            ->assertSee('operator@example.test')
            ->filterTable('actor', $actor)
            ->assertCanSeeTableRecords([$older, $matching])
            ->assertCanNotSeeTableRecords([$other])
            ->filterTable('recorded_at', ['from' => '2026-11-02 09:00:00', 'until' => '2026-11-02 09:00:00'])
            ->assertCanSeeTableRecords([$matching])
            ->assertCanNotSeeTableRecords([$older, $other]);
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
