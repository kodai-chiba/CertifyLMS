<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\MeetingPack;
use App\Models\Payment;
use App\Models\User;
use App\Services\StripeCheckoutService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class MeetingQuotaCheckoutTest extends TestCase
{
    use RefreshDatabase;

    public function test_purchase_page_displays_only_published_packs(): void
    {
        $student = User::factory()->student()->inProgress()->create();

        $published = MeetingPack::factory()
            ->published()
            ->create(['name' => '公開中の面談パック']);

        $draft = MeetingPack::factory()
            ->draft()
            ->create(['name' => '下書きの面談パック']);

        $archived = MeetingPack::factory()
            ->archived()
            ->create(['name' => '公開終了の面談パック']);

        $this->actingAs($student)
            ->get(route('meeting-quota.purchase.index'))
            ->assertOk()
            ->assertViewHas('plans', function ($plans) use (
                $published,
                $draft,
                $archived
            ) {
                return $plans->contains('id', $published->id)
                    && ! $plans->contains('id', $draft->id)
                    && ! $plans->contains('id', $archived->id);
            });
    }

    public function test_guest_cannot_access_purchase_page(): void
    {
        $this->get(route('meeting-quota.purchase.index'))
            ->assertRedirect();
    }

    public function test_admin_cannot_access_purchase_page(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->get(route('meeting-quota.purchase.index'))
            ->assertForbidden();
    }

    public function test_graduated_student_cannot_purchase(): void
    {
        $student = User::factory()->student()->graduated()->create();

        $pack = MeetingPack::factory()->published()->create();

        $this->actingAs($student)
            ->post(route('meeting-quota.checkout.create'), [
                'meeting_pack_id' => $pack->id,
            ])
            ->assertForbidden();

        $this->assertDatabaseCount('payments', 0);
    }

    public function test_draft_pack_cannot_be_purchased(): void
    {
        $student = User::factory()->student()->inProgress()->create();

        $pack = MeetingPack::factory()->draft()->create();

        $this->actingAs($student)
            ->post(route('meeting-quota.checkout.create'), [
                'meeting_pack_id' => $pack->id,
            ])
            ->assertSessionHasErrors('meeting_pack_id');

        $this->assertDatabaseCount('payments', 0);
    }

    public function test_archived_pack_cannot_be_purchased(): void
    {
        $student = User::factory()->student()->inProgress()->create();

        $pack = MeetingPack::factory()->archived()->create();

        $this->actingAs($student)
            ->post(route('meeting-quota.checkout.create'), [
                'meeting_pack_id' => $pack->id,
            ])
            ->assertSessionHasErrors('meeting_pack_id');

        $this->assertDatabaseCount('payments', 0);
    }

    public function test_invalid_pack_id_is_rejected(): void
    {
        $student = User::factory()->student()->inProgress()->create();

        $this->actingAs($student)
            ->post(route('meeting-quota.checkout.create'), [
                'meeting_pack_id' => 'invalid-id',
            ])
            ->assertSessionHasErrors('meeting_pack_id');
    }

    public function test_published_pack_redirects_to_stripe_checkout(): void
    {
        $student = User::factory()->student()->inProgress()->create();

        $pack = MeetingPack::factory()
            ->published()
            ->withCount(3)
            ->withPrice(9000)
            ->create();

        $payment = Payment::factory()->create([
            'user_id' => $student->id,
            'meeting_pack_id' => $pack->id,
            'amount' => 9000,
            'quantity' => 3,
        ]);

        $checkoutUrl = 'https://checkout.stripe.com/c/pay/test-session';

        $service = Mockery::mock(StripeCheckoutService::class);

        $service->shouldReceive('createSession')
            ->once()
            ->withArgs(function (
                User $user,
                MeetingPack $meetingPack,
                string $successUrl,
                string $cancelUrl
            ) use ($student, $pack) {
                return $user->id === $student->id
                    && $meetingPack->id === $pack->id
                    && str_contains($successUrl, 'session_id=')
                    && str_contains($cancelUrl, '/meeting-quota/purchase');
            })
            ->andReturn([
                'payment' => $payment,
                'checkout_url' => $checkoutUrl,
            ]);

        $this->app->instance(StripeCheckoutService::class, $service);

        $this->actingAs($student)
            ->post(route('meeting-quota.checkout.create'), [
                'meeting_pack_id' => $pack->id,
            ])
            ->assertRedirect($checkoutUrl);
    }

    public function test_success_page_only_displays_own_payment(): void
    {
        $student = User::factory()->student()->inProgress()->create();

        $otherStudent = User::factory()->student()->inProgress()->create();

        $payment = Payment::factory()->create([
            'user_id' => $otherStudent->id,
        ]);

        $this->actingAs($student)
            ->get(route('meeting-quota.success', [
                'session_id' => $payment->stripe_checkout_session_id,
            ]))
            ->assertOk()
            ->assertViewHas('payment', null);
    }
}
