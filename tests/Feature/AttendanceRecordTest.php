<?php

namespace Tests\Feature;

use App\Models\AttendanceRecord;
use App\Models\Batch;
use App\Models\Course;
use App\Models\Institution;
use App\Models\Role;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;
use App\Models\TeacherProfile;

class AttendanceRecordTest extends TestCase
{
    use RefreshDatabase;

    public function test_teacher_can_create_bulk_update_and_report_attendance(): void
    {
        $teacher = $this->authenticateTeacher();
        $institution = Institution::create([
            'name' => 'EDURA Academy',
            'code' => 'EDURA',
        ]);
        $teacherProfile = TeacherProfile::create([
            'institution_id' => $institution->id,
            'user_id' => $teacher->id,
        ]);

        $batch = Batch::create([
            'institution_id' => $institution->id,
            'name' => 'Batch A',
            'code' => 'BATCH-A',
        ]);
        
        $course = Course::create([
            'institution_id' => $institution->id,
            'batch_id' => $batch->id,
            'teacher_profile_id' => $teacherProfile->id,
            'title' => 'Mathematics',
            'slug' => 'mathematics',
        ]);
        $firstStudent = $this->createStudentProfile($institution->id, $batch->id, 'A-001');
        $secondStudent = $this->createStudentProfile($institution->id, $batch->id, 'A-002');

        $this->postJson('/api/attendance-records', [
            'student_profile_id' => $firstStudent->id,
            'course_id' => $course->id,
            'attendance_date' => '2026-06-14',
            'attendance_status' => 'absent',
            'remarks' => 'Initial mark',
        ])
            ->assertCreated()
            ->assertJsonPath('data.institution_id', $institution->id)
            ->assertJsonPath('data.batch_id', $batch->id)
            ->assertJsonPath('data.marked_by_id', $teacher->id)
            ->assertJsonPath('data.attendance_status', 'absent');

        $this->postJson('/api/attendance-records/bulk', [
            'batch_id' => $batch->id,
            'course_id' => $course->id,
            'attendance_date' => '2026-06-14',
            'records' => [
                [
                    'student_profile_id' => $firstStudent->id,
                    'attendance_status' => 'present',
                ],
                [
                    'student_profile_id' => $secondStudent->id,
                    'attendance_status' => 'late',
                ],
            ],
        ])
            ->assertCreated()
            ->assertJsonCount(2, 'data');

        $this->assertTrue(
            AttendanceRecord::where('student_profile_id', $firstStudent->id)
                ->whereDate('attendance_date', '2026-06-14')
                ->where('attendance_status', 'present')
                ->exists()
        );
        $this->assertTrue(
            AttendanceRecord::where('student_profile_id', $secondStudent->id)
                ->whereDate('attendance_date', '2026-06-14')
                ->where('attendance_status', 'late')
                ->exists()
        );

        $this->getJson('/api/attendance-reports?from_date=2026-06-14&to_date=2026-06-14&batch_id=' . $batch->id)
            ->assertOk()
            ->assertJsonPath('data.summary.total_records', 2)
            ->assertJsonPath('data.summary.status_counts.present', 1)
            ->assertJsonPath('data.summary.status_counts.late', 1)
            ->assertJsonPath('data.summary.attendance_percentage', 100)
            ->assertJsonCount(2, 'data.students');
    }

    private function authenticateTeacher(): User
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $role = Role::create([
            'name' => 'teacher',
            'guard_name' => 'web',
            'display_name' => 'Teacher',
        ]);

        $user = User::factory()->create();

        $user->assignRole($role);

        $this->actingAs($user, 'web');

        return $user;
    }

    private function createStudentProfile(int $institutionId, int $batchId, string $rollNumber): StudentProfile
    {
        $user = User::factory()->create();

        return StudentProfile::create([
            'institution_id' => $institutionId,
            'user_id' => $user->id,
            'batch_id' => $batchId,
            'roll_number' => $rollNumber,
        ]);
    }
}
