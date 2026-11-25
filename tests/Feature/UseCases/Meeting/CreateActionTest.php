<?php

declare(strict_types=1);

namespace Tests\Feature\UseCases\Meeting;

use App\Models\Certification;
use App\Models\Enrollment;
use App\Models\User;
use App\UseCases\Meeting\CreateAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CreateActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_loads_certification_for_enrollment(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $certification = Certification::factory()->published()->create();

        $enrollment = Enrollment::factory()
            ->for($student, 'user')
            ->for($certification)
            ->learning()
            ->create();

        $this->assertFalse($enrollment->relationLoaded('certification'));

        $result = app(CreateAction::class)($enrollment);

        $this->assertTrue($result->relationLoaded('certification'));
        $this->assertSame($enrollment->id, $result->id);
        $this->assertSame($certification->id, $result->certification->id);
    }

    public function test_preserves_already_loaded_certification(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $certification = Certification::factory()->published()->create();

        $enrollment = Enrollment::factory()
            ->for($student, 'user')
            ->for($certification)
            ->learning()
            ->create();

        $enrollment->load('certification');

        $loadedCertification = $enrollment->certification;

        $result = app(CreateAction::class)($enrollment);

        $this->assertSame($enrollment, $result);
        $this->assertSame($loadedCertification, $result->certification);
    }
}
