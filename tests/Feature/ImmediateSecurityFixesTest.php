<?php

namespace Tests\Feature;

use App\Models\Assignment;
use App\Models\AssignmentEvaluation;
use App\Models\AssignmentSubmission;
use App\Models\Certificate;
use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\Institution;
use App\Models\InstitutionUser;
use App\Models\Lesson;
use App\Models\LessonProgress;
use App\Models\ParentProfile;
use App\Models\Role;
use App\Models\StudentProfile;
use App\Models\TeacherProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class ImmediateSecurityFixesTest extends TestCase
{
    use RefreshDatabase;

    public function test_certificate_access_is_scoped_for_every_restricted_role(): void
    {
        $firstInstitution = $this->createInstitution('CERT-A');
        $secondInstitution = $this->createInstitution('CERT-B');
        [$firstTeacherUser, $firstTeacher] = $this->createTeacher($firstInstitution);
        [, $secondTeacher] = $this->createTeacher($secondInstitution);
        [$firstStudentUser, $firstStudent] = $this->createStudent($firstInstitution);
        [, $secondStudent] = $this->createStudent($secondInstitution);
        $firstCourse = $this->createCourse($firstInstitution, $firstTeacher, 'certificate-a');
        $secondCourse = $this->createCourse($secondInstitution, $secondTeacher, 'certificate-b');

        $this->createEnrollment($firstCourse, $firstStudent);
        $this->createEnrollment($secondCourse, $secondStudent);

        $firstCertificate = $this->createCertificate($firstCourse, $firstStudent, 'CERT-A-001');
        $secondCertificate = $this->createCertificate($secondCourse, $secondStudent, 'CERT-B-001');
        $parentUser = $this->createParent($firstInstitution, $firstStudent);
        $adminUser = $this->createInstitutionAdmin($firstInstitution);

        $this->authenticate($firstStudentUser);
        $this->getJson('/api/my-certificates')
            ->assertOk()
            ->assertJsonCount(1, 'data.data')
            ->assertJsonPath('data.data.0.id', $firstCertificate->id);
        $this->assertForbiddenResponse(
            $this->getJson('/api/certificates/'.$secondCertificate->id),
            'student reading another certificate'
        );

        $this->authenticate($parentUser);
        $this->getJson('/api/certificates')
            ->assertOk()
            ->assertJsonCount(1, 'data.data')
            ->assertJsonPath('data.data.0.id', $firstCertificate->id);
        $this->assertForbiddenResponse(
            $this->patchJson('/api/certificates/'.$firstCertificate->id, [
                'status' => 'revoked',
            ]),
            'parent updating a certificate'
        );

        $this->authenticate($firstTeacherUser);
        $this->getJson('/api/certificates')
            ->assertOk()
            ->assertJsonCount(1, 'data.data')
            ->assertJsonPath('data.data.0.id', $firstCertificate->id);
        $this->assertForbiddenResponse(
            $this->getJson('/api/certificates/'.$secondCertificate->id),
            'teacher reading another course certificate'
        );

        $this->authenticate($adminUser);
        $this->getJson('/api/certificates')
            ->assertOk()
            ->assertJsonCount(1, 'data.data')
            ->assertJsonPath('data.data.0.id', $firstCertificate->id);
        $this->assertForbiddenResponse(
            $this->getJson('/api/certificates/'.$secondCertificate->id),
            'institution admin reading another institution certificate'
        );
    }

    public function test_lesson_progress_enforces_ownership_and_parent_read_only_access(): void
    {
        $firstInstitution = $this->createInstitution('PROGRESS-A');
        $secondInstitution = $this->createInstitution('PROGRESS-B');
        [$firstTeacherUser, $firstTeacher] = $this->createTeacher($firstInstitution);
        [, $secondTeacher] = $this->createTeacher($secondInstitution);
        [$firstStudentUser, $firstStudent] = $this->createStudent($firstInstitution);
        [, $secondStudent] = $this->createStudent($secondInstitution);
        $firstCourse = $this->createCourse($firstInstitution, $firstTeacher, 'progress-a');
        $secondCourse = $this->createCourse($secondInstitution, $secondTeacher, 'progress-b');
        $firstEnrollment = $this->createEnrollment($firstCourse, $firstStudent);
        $secondEnrollment = $this->createEnrollment($secondCourse, $secondStudent);
        $firstLesson = $this->createLesson($firstCourse, 'First lesson');
        $secondLesson = $this->createLesson($secondCourse, 'Second lesson');
        $firstProgress = $this->createProgress($firstEnrollment, $firstLesson);
        $secondProgress = $this->createProgress($secondEnrollment, $secondLesson);
        $parentUser = $this->createParent($firstInstitution, $firstStudent);
        $adminUser = $this->createInstitutionAdmin($firstInstitution);

        $this->authenticate($firstStudentUser);
        $this->getJson('/api/lesson-progress')
            ->assertOk()
            ->assertJsonCount(1, 'data.data')
            ->assertJsonPath('data.data.0.id', $firstProgress->id);
        $this->patchJson('/api/lesson-progress/'.$firstProgress->id, [
            'status' => 'completed',
        ])->assertOk();
        $this->assertForbiddenResponse(
            $this->patchJson('/api/lesson-progress/'.$secondProgress->id, [
                'status' => 'completed',
            ]),
            'student updating another student progress'
        );

        $this->authenticate($parentUser);
        $this->getJson('/api/lesson-progress/'.$firstProgress->id)->assertOk();
        $this->assertForbiddenResponse(
            $this->patchJson('/api/lesson-progress/'.$firstProgress->id, [
                'status' => 'in_progress',
            ]),
            'parent updating child progress'
        );

        $this->authenticate($firstTeacherUser);
        $this->getJson('/api/lesson-progress')
            ->assertOk()
            ->assertJsonCount(1, 'data.data');
        $this->assertForbiddenResponse(
            $this->getJson('/api/lesson-progress/'.$secondProgress->id),
            'teacher reading another course progress'
        );

        $this->authenticate($adminUser);
        $this->getJson('/api/lesson-progress')
            ->assertOk()
            ->assertJsonCount(1, 'data.data');
        $this->assertForbiddenResponse(
            $this->getJson('/api/lesson-progress/'.$secondProgress->id),
            'institution admin reading another institution progress'
        );
    }

    public function test_assignment_evaluation_blocks_cross_course_grading_and_grade_tampering(): void
    {
        $firstInstitution = $this->createInstitution('EVAL-A');
        $secondInstitution = $this->createInstitution('EVAL-B');
        [$firstTeacherUser, $firstTeacher] = $this->createTeacher($firstInstitution);
        [, $secondTeacher] = $this->createTeacher($secondInstitution);
        [$firstStudentUser, $firstStudent] = $this->createStudent($firstInstitution);
        [, $secondStudent] = $this->createStudent($secondInstitution);
        $firstCourse = $this->createCourse($firstInstitution, $firstTeacher, 'evaluation-a');
        $secondCourse = $this->createCourse($secondInstitution, $secondTeacher, 'evaluation-b');

        $this->createEnrollment($firstCourse, $firstStudent);
        $this->createEnrollment($secondCourse, $secondStudent);

        $firstSubmission = $this->createSubmission(
            $this->createAssignment($firstCourse, $firstTeacher, 'First assignment'),
            $firstStudent
        );
        $secondSubmission = $this->createSubmission(
            $this->createAssignment($secondCourse, $secondTeacher, 'Second assignment'),
            $secondStudent
        );

        $this->authenticate($firstTeacherUser);
        $this->assertForbiddenResponse(
            $this->postJson('/api/assignment-evaluations', [
                'assignment_submission_id' => $secondSubmission->id,
                'marks_obtained' => 80,
            ]),
            'teacher evaluating another course submission'
        );

        $this->postJson('/api/assignment-evaluations', [
            'assignment_submission_id' => $firstSubmission->id,
            'marks_obtained' => 101,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('marks_obtained');

        $this->postJson('/api/assignment-evaluations', [
            'assignment_submission_id' => $firstSubmission->id,
            'marks_obtained' => 80,
            'maximum_marks' => 200,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('maximum_marks');

        $evaluationId = $this->postJson('/api/assignment-evaluations', [
            'assignment_submission_id' => $firstSubmission->id,
            'marks_obtained' => 80,
            'result_status' => 'failed',
        ])
            ->assertCreated()
            ->assertJsonPath('data.teacher_profile_id', $firstTeacher->id)
            ->assertJsonPath('data.maximum_marks', '100.00')
            ->assertJsonPath('data.result_status', 'passed')
            ->json('data.id');

        $this->authenticate($firstStudentUser);
        $this->getJson('/api/assignment-evaluations/'.$evaluationId)->assertOk();
        $this->assertForbiddenResponse(
            $this->patchJson('/api/assignment-evaluations/'.$evaluationId, [
                'marks_obtained' => 100,
            ]),
            'student updating an evaluation'
        );

        $parentUser = $this->createParent($firstInstitution, $firstStudent);
        $this->authenticate($parentUser);
        $this->getJson('/api/assignment-evaluations/'.$evaluationId)->assertOk();
        $this->assertForbiddenResponse(
            $this->deleteJson('/api/assignment-evaluations/'.$evaluationId),
            'parent deleting an evaluation'
        );

        $secondEvaluation = AssignmentEvaluation::create([
            'assignment_submission_id' => $secondSubmission->id,
            'teacher_profile_id' => $secondTeacher->id,
            'marks_obtained' => 75,
            'maximum_marks' => 100,
            'result_status' => 'passed',
            'evaluated_at' => now(),
        ]);

        $adminUser = $this->createInstitutionAdmin($firstInstitution);
        $this->authenticate($adminUser);
        $this->getJson('/api/assignment-evaluations')
            ->assertOk()
            ->assertJsonCount(1, 'data.data')
            ->assertJsonPath('data.data.0.id', $evaluationId);
        $this->assertForbiddenResponse(
            $this->getJson('/api/assignment-evaluations/'.$secondEvaluation->id),
            'institution admin reading another institution evaluation'
        );
    }

    public function test_institution_admin_without_an_active_profile_fails_closed(): void
    {
        $adminUser = $this->createUserWithRole('institution-admin');

        $this->authenticate($adminUser);

        $this->getJson('/api/certificates')->assertForbidden();
        $this->getJson('/api/lesson-progress')->assertForbidden();
        $this->getJson('/api/assignment-evaluations')->assertForbidden();
    }

    private function createInstitution(string $code): Institution
    {
        return Institution::create([
            'name' => 'Institution '.$code,
            'code' => $code,
        ]);
    }

    private function assertForbiddenResponse(
        TestResponse $response,
        string $context
    ): void {
        $this->assertSame(
            403,
            $response->getStatusCode(),
            $context.': '.$response->getContent()
        );
    }

    private function authenticate(User $user): void
    {
        $this->app['auth']->forgetGuards();
        $this->actingAs($user, 'web');
    }

    private function createUserWithRole(string $roleName): User
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $role = Role::firstOrCreate(
            [
                'name' => $roleName,
                'guard_name' => 'web',
            ],
            [
                'display_name' => Str::headline($roleName),
            ]
        );
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    private function createInstitutionAdmin(Institution $institution): User
    {
        $user = $this->createUserWithRole('institution-admin');

        InstitutionUser::create([
            'institution_id' => $institution->id,
            'user_id' => $user->id,
            'role_in_institution' => 'admin',
            'status' => 'active',
        ]);

        return $user;
    }

    private function createTeacher(Institution $institution): array
    {
        $user = $this->createUserWithRole('teacher');
        $teacherProfile = TeacherProfile::create([
            'institution_id' => $institution->id,
            'user_id' => $user->id,
            'status' => 'active',
        ]);

        return [$user, $teacherProfile];
    }

    private function createStudent(Institution $institution): array
    {
        $user = $this->createUserWithRole('student');
        $studentProfile = StudentProfile::create([
            'institution_id' => $institution->id,
            'user_id' => $user->id,
            'status' => 'active',
        ]);

        return [$user, $studentProfile];
    }

    private function createParent(
        Institution $institution,
        StudentProfile $studentProfile
    ): User {
        $user = $this->createUserWithRole('parent');

        ParentProfile::create([
            'institution_id' => $institution->id,
            'user_id' => $user->id,
            'student_profile_id' => $studentProfile->id,
            'status' => 'active',
        ]);

        return $user;
    }

    private function createCourse(
        Institution $institution,
        TeacherProfile $teacherProfile,
        string $slug
    ): Course {
        return Course::create([
            'institution_id' => $institution->id,
            'teacher_profile_id' => $teacherProfile->id,
            'title' => Str::headline($slug),
            'slug' => $slug,
            'status' => 'published',
        ]);
    }

    private function createEnrollment(
        Course $course,
        StudentProfile $studentProfile
    ): CourseEnrollment {
        return CourseEnrollment::create([
            'course_id' => $course->id,
            'student_profile_id' => $studentProfile->id,
            'enrollment_date' => now()->toDateString(),
            'status' => 'active',
        ]);
    }

    private function createCertificate(
        Course $course,
        StudentProfile $studentProfile,
        string $number
    ): Certificate {
        return Certificate::create([
            'course_id' => $course->id,
            'student_profile_id' => $studentProfile->id,
            'certificate_number' => $number,
            'certificate_uuid' => (string) Str::uuid(),
            'verification_token' => Str::random(16),
            'issued_date' => now()->toDateString(),
            'final_percentage' => 80,
            'final_grade' => 'B',
            'status' => 'issued',
            'verification_status' => 'valid',
        ]);
    }

    private function createLesson(Course $course, string $title): Lesson
    {
        return Lesson::create([
            'course_id' => $course->id,
            'title' => $title,
            'status' => 'published',
        ]);
    }

    private function createProgress(
        CourseEnrollment $courseEnrollment,
        Lesson $lesson
    ): LessonProgress {
        return LessonProgress::create([
            'course_enrollment_id' => $courseEnrollment->id,
            'lesson_id' => $lesson->id,
            'status' => 'in_progress',
            'progress_percentage' => 25,
        ]);
    }

    private function createAssignment(
        Course $course,
        TeacherProfile $teacherProfile,
        string $title
    ): Assignment {
        return Assignment::create([
            'course_id' => $course->id,
            'teacher_profile_id' => $teacherProfile->id,
            'title' => $title,
            'maximum_marks' => 100,
            'status' => 'published',
        ]);
    }

    private function createSubmission(
        Assignment $assignment,
        StudentProfile $studentProfile
    ): AssignmentSubmission {
        return AssignmentSubmission::create([
            'assignment_id' => $assignment->id,
            'student_profile_id' => $studentProfile->id,
            'submitted_at' => now(),
            'status' => 'submitted',
        ]);
    }
}
