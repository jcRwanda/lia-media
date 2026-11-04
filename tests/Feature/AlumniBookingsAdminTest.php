<?php

namespace Tests\Feature;

use App\Models\Cohort;
use App\Models\EquipmentItem;
use App\Models\MaterialRequest;
use App\Models\MaterialRequestItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AlumniBookingsAdminTest extends TestCase
{
    use RefreshDatabase;

    private function alumni(array $o = []): MaterialRequest
    {
        $mr = MaterialRequest::query()->create(array_merge([
            'requester_type' => 'alumni', 'full_name' => 'Grace Alumna', 'phone' => '0788000111',
            'email' => 'grace@example.com', 'project_name' => 'Short film',
            'needed_from' => now()->addDays(2)->toDateString(), 'return_by' => now()->addDays(5)->toDateString(),
            'status' => 'submitted', 'graduation_completion_year' => '2024', 'student_alumni_id' => 'LIA-C2-017',
            'project_title' => 'Umuganda Stories', 'project_type' => 'Documentary', 'expected_outcome' => 'A 10 minute doc',
            'portfolio_social_link' => 'https://instagram.com/grace', 'national_id' => '1199880012345678',
            'terms_accepted' => true, 'consent_declaration' => true,
        ], $o));
        MaterialRequestItem::query()->create([
            'material_request_id' => $mr->id, 'equipment_item_id' => EquipmentItem::factory()->create(['total_qty' => 3])->id,
            'qty_requested' => 1, 'status' => 'pending',
        ]);

        return $mr;
    }

    public function test_admin_lists_alumni_bookings_with_search_and_status_counts(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->alumni();
        $this->alumni(['full_name' => 'Eric Other', 'email' => 'eric@example.com', 'project_title' => 'Music video', 'student_alumni_id' => 'X', 'status' => 'approved']);
        MaterialRequest::query()->create(['requester_type' => 'trainee', 'full_name' => 'Trainee T', 'phone' => '1', 'email' => 't@example.com', 'needed_from' => now()->toDateString(), 'return_by' => now()->addDay()->toDateString(), 'status' => 'submitted']);

        $res = $this->actingAs($admin)->getJson('/api/material-requests?requester_type=alumni')->assertOk();
        $this->assertSame(2, $res->json('total'));
        $this->assertSame(1, (int) $res->json('status_counts.submitted'));
        $this->assertSame(1, (int) $res->json('status_counts.approved'));

        $res = $this->actingAs($admin)->getJson('/api/material-requests?requester_type=alumni&search=Umuganda')->assertOk();
        $this->assertSame(1, $res->json('total'));
        $this->assertSame('Grace Alumna', $res->json('data.0.full_name'));
    }

    public function test_detail_returns_every_alumni_form_answer_grouped_by_section(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $cohort = Cohort::query()->create(['name' => 'Cohort 2', 'code' => 'C2', 'year' => 2024]);
        $mr = $this->alumni(['cohort_id' => $cohort->id]);

        $res = $this->actingAs($admin)->getJson('/api/material-requests/'.$mr->id)->assertOk();
        $fields = collect($res->json('request.form_answers'))->flatMap(fn ($s) => $s['fields'])->keyBy('key');

        foreach (['graduation_completion_year' => '2024', 'student_alumni_id' => 'LIA-C2-017', 'project_title' => 'Umuganda Stories',
            'project_type' => 'Documentary', 'expected_outcome' => 'A 10 minute doc', 'portfolio_social_link' => 'https://instagram.com/grace',
            'national_id' => '1199880012345678', 'requester_type' => 'Alumni'] as $key => $value) {
            $this->assertSame($value, $fields[$key]['value'], $key);
        }
        $this->assertStringContainsString('Cohort 2', $fields['cohort_id']['value']);
        $this->assertTrue($fields['consent_declaration']['value']);
        $this->assertArrayNotHasKey('items', $fields->all());
        $this->assertContains('Programme', collect($res->json('request.form_answers'))->pluck('label')->all());
    }

    public function test_existing_review_actions_work_on_alumni_bookings(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $mr = $this->alumni();
        $this->actingAs($admin)->postJson('/api/material-requests/'.$mr->id.'/request-changes', ['note' => 'Add portfolio link'])->assertOk();
        $this->assertSame('changes_requested', $mr->fresh()->status);
    }
}
