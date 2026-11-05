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
}
