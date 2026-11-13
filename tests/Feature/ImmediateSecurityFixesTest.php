<?php

namespace Tests\Feature;

use App\Models\Assignment;
use App\Models\AssignmentEvaluation;
use App\Models\AssignmentSubmission;
use App\Models\ActivityLog;
use App\Models\Certificate;
use App\Models\CertificateSetting;
use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\CourseSection;
use App\Models\Institution;
use App\Models\InstitutionUser;
use App\Models\Lesson;
use App\Models\LessonProgress;
use App\Models\ParentProfile;
use App\Models\QuestionBank;
use App\Models\QuestionOption;
use App\Models\Quiz;
use App\Models\QuizAnswer;
use App\Models\QuizAttempt;
use App\Models\QuizQuestion;
use App\Models\Role;
use App\Models\StudentProfile;
use App\Models\TeacherProfile;
use App\Models\User;
use App\Services\CourseEnrollmentService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;
use App\Models\Gradebook;

class ImmediateSecurityFixesTest extends TestCase
{
    use RefreshDatabase;

    public function test_parent_user_can_have_multiple_parent_profiles(): void
    {
        $institution = $this->createInstitution('PARENT-MULTI');

        [, $firstStudent] = $this->createStudent($institution);
        [, $secondStudent] = $this->createStudent($institution);

        $parentUser = $this->createParent($institution, $firstStudent);

        ParentProfile::create([
            'institution_id' => $institution->id,
            'user_id' => $parentUser->id,
            'student_profile_id' => $secondStudent->id,
            'status' => 'active',
        ]);

        $parentProfiles = $parentUser->parentProfiles;

        $this->assertCount(2, $parentProfiles);
        $this->assertEqualsCanonicalizing(
            [$firstStudent->id, $secondStudent->id],
            $parentProfiles->pluck('student_profile_id')->all()
        );
    }


    public function test_parent_dashboard_supports_multiple_children_and_blocks_unauthorized_children(): void
    {
        $institution = $this->createInstitution('PARENT-DASHBOARD');

        [, $firstStudent] = $this->createStudent($institution);
        [, $secondStudent] = $this->createStudent($institution);
        [, $unrelatedStudent] = $this->createStudent($institution);
        [, $inactiveLinkedStudent] = $this->createStudent($institution);

        $parentUser = $this->createParent($institution, $firstStudent);

        ParentProfile::create([
            'institution_id' => $institution->id,
            'user_id' => $parentUser->id,
            'student_profile_id' => $secondStudent->id,
            'status' => 'active',
        ]);

        ParentProfile::create([
            'institution_id' => $institution->id,
            'user_id' => $parentUser->id,
            'student_profile_id' => $inactiveLinkedStudent->id,
            'status' => 'inactive',
        ]);

        $this->authenticate($parentUser);

        $this->getJson('/api/parent-dashboard/children')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonFragment(['id' => $firstStudent->id])
            ->assertJsonFragment(['id' => $secondStudent->id])
            ->assertJsonMissing(['id' => $inactiveLinkedStudent->id]);

        $this->getJson('/api/parent-dashboard/' . $firstStudent->id)
            ->assertOk();

        $this->getJson('/api/parent-dashboard/' . $secondStudent->id)
            ->assertOk();

        $this->assertForbiddenResponse(
            $this->getJson('/api/parent-dashboard/' . $unrelatedStudent->id),
            'parent accessing an unrelated child dashboard'
        );

        $this->assertForbiddenResponse(
            $this->getJson('/api/parent-dashboard/' . $inactiveLinkedStudent->id),
            'parent accessing a child through an inactive link'
        );
    }

    public function test_parent_gradebook_access_supports_multiple_children(): void
    {
        $institution = $this->createInstitution('GRADEBOOK-MULTI');

        [, $firstStudent] = $this->createStudent($institution);
        [, $secondStudent] = $this->createStudent($institution);
        [, $unrelatedStudent] = $this->createStudent($institution);
        [, $inactiveStudent] = $this->createStudent($institution);

        [, $teacher] = $this->createTeacher($institution);

        $firstCourse = $this->createCourse(
            $institution,
            $teacher,
            'gradebook-child-one'
        );

        $secondCourse = $this->createCourse(
            $institution,
            $teacher,
            'gradebook-child-two'
        );

        $unrelatedCourse = $this->createCourse(
            $institution,
            $teacher,
            'gradebook-unrelated'
        );

        $inactiveCourse = $this->createCourse(
            $institution,
            $teacher,
            'gradebook-inactive-child'
        );

        $this->createEnrollment($firstCourse, $firstStudent);
        $this->createEnrollment($secondCourse, $secondStudent);
        $this->createEnrollment($unrelatedCourse, $unrelatedStudent);
        $this->createEnrollment($inactiveCourse, $inactiveStudent);

        $firstGradebook = Gradebook::create([
            'course_id' => $firstCourse->id,
            'student_profile_id' => $firstStudent->id,
            'assignment_marks' => 40,
            'quiz_marks' => 40,
            'total_marks' => 80,
            'maximum_marks' => 100,
            'percentage' => 80,
            'grade' => 'A',
            'result_status' => 'passed',
        ]);

        $secondGradebook = Gradebook::create([
            'course_id' => $secondCourse->id,
            'student_profile_id' => $secondStudent->id,
            'assignment_marks' => 35,
            'quiz_marks' => 35,
            'total_marks' => 70,
            'maximum_marks' => 100,
            'percentage' => 70,
            'grade' => 'B+',
            'result_status' => 'passed',
        ]);

        $unrelatedGradebook = Gradebook::create([
            'course_id' => $unrelatedCourse->id,
            'student_profile_id' => $unrelatedStudent->id,
            'assignment_marks' => 30,
            'quiz_marks' => 30,
            'total_marks' => 60,
            'maximum_marks' => 100,
            'percentage' => 60,
            'grade' => 'B',
            'result_status' => 'passed',
        ]);

        $inactiveGradebook = \App\Models\Gradebook::create([
            'course_id' => $inactiveCourse->id,
            'student_profile_id' => $inactiveStudent->id,
            'assignment_marks' => 20,
            'quiz_marks' => 20,
            'total_marks' => 40,
            'maximum_marks' => 100,
            'percentage' => 40,
            'grade' => 'D',
            'result_status' => 'passed',
        ]);

        $parentUser = $this->createParent($institution, $firstStudent);

        ParentProfile::create([
            'institution_id' => $institution->id,
            'user_id' => $parentUser->id,
            'student_profile_id' => $secondStudent->id,
            'status' => 'active',
        ]);

        ParentProfile::create([
            'institution_id' => $institution->id,
            'user_id' => $parentUser->id,
            'student_profile_id' => $inactiveStudent->id,
            'status' => 'inactive',
        ]);

        $this->authenticate($parentUser);

        // Parent can list gradebooks for all active linked children.
        $this->getJson('/api/gradebooks')
            ->assertOk()
            ->assertJsonCount(2, 'data.data')
            ->assertJsonFragment(['id' => $firstGradebook->id])
            ->assertJsonFragment(['id' => $secondGradebook->id])
            ->assertJsonMissing(['id' => $unrelatedGradebook->id])
            ->assertJsonMissing(['id' => $inactiveGradebook->id]);

        // Parent can view the first active linked child's gradebook.
        $this->getJson('/api/gradebooks/' . $firstGradebook->id)
            ->assertOk();

        // Parent can view the second active linked child's gradebook.
        $this->getJson('/api/gradebooks/' . $secondGradebook->id)
            ->assertOk();

        // Parent cannot view an unrelated child's gradebook.
        $this->assertForbiddenResponse(
            $this->getJson('/api/gradebooks/' . $unrelatedGradebook->id),
            'parent reading unrelated child gradebook'
        );

        // Parent cannot view a gradebook through an inactive parent link.
        $this->assertForbiddenResponse(
            $this->getJson('/api/gradebooks/' . $inactiveGradebook->id),
            'parent reading inactive linked child gradebook'
        );
    }

    public function test_non_parent_cannot_access_parent_dashboard(): void
    {
        $institution = $this->createInstitution('NON-PARENT-DASHBOARD');

        [, $student] = $this->createStudent($institution);

        $studentUser = User::findOrFail($student->user_id);

        $this->authenticate($studentUser);

        $this->assertForbiddenResponse(
            $this->getJson('/api/parent-dashboard/children'),
            'student accessing the parent children endpoint'
        );

        $this->assertForbiddenResponse(
            $this->getJson('/api/parent-dashboard/' . $student->id),
            'student accessing the parent dashboard'
        );
    }


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
            $this->getJson('/api/certificates/' . $secondCertificate->id),
            'student reading another certificate'
        );
        $this->assertForbiddenResponse(
            $this->getJson('/api/certificates/' . $secondCertificate->id . '/download'),
            'student downloading another certificate'
        );

        $this->authenticate($parentUser);
        $this->getJson('/api/certificates')
            ->assertOk()
            ->assertJsonCount(1, 'data.data')
            ->assertJsonPath('data.data.0.id', $firstCertificate->id);
        $this->assertForbiddenResponse(
            $this->patchJson('/api/certificates/' . $firstCertificate->id, [
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
            $this->getJson('/api/certificates/' . $secondCertificate->id),
            'teacher reading another course certificate'
        );
        $this->assertForbiddenResponse(
            $this->getJson('/api/certificates/' . $secondCertificate->id . '/download'),
            'teacher downloading another course certificate'
        );

        $this->authenticate($adminUser);
        $this->getJson('/api/certificates')
            ->assertOk()
            ->assertJsonCount(1, 'data.data')
            ->assertJsonPath('data.data.0.id', $firstCertificate->id);
        $this->assertForbiddenResponse(
            $this->getJson('/api/certificates/' . $secondCertificate->id),
            'institution admin reading another institution certificate'
        );
        $this->assertForbiddenResponse(
            $this->getJson('/api/certificates/' . $secondCertificate->id . '/download'),
            'institution admin downloading another institution certificate'
        );
    }

    public function test_parent_certificate_responses_hide_gradebooks_that_do_not_match_the_certificate_pair(): void
    {
        $institution = $this->createInstitution('CERT-GRADEBOOK-PAIR');
        [$teacherUser, $teacher] = $this->createTeacher($institution);
        [, $student] = $this->createStudent($institution);
        [, $otherStudent] = $this->createStudent($institution);
        $course = $this->createCourse($institution, $teacher, 'certificate-gradebook-course');
        $otherStudentCourse = $this->createCourse(
            $institution,
            $teacher,
            'certificate-gradebook-other-student-course'
        );
        $otherCourse = $this->createCourse($institution, $teacher, 'certificate-gradebook-other-course');
        $missingGradebookCourse = $this->createCourse(
            $institution,
            $teacher,
            'certificate-gradebook-missing-course'
        );

        foreach ([$course, $otherStudentCourse, $otherCourse, $missingGradebookCourse] as $certificateCourse) {
            $this->createEnrollment($certificateCourse, $student);
        }
        $this->createEnrollment($otherStudentCourse, $otherStudent);

        $validGradebook = $this->createPassingGradebook($course, $student);
        $otherStudentsGradebook = Gradebook::create([
            'course_id' => $otherStudentCourse->id,
            'student_profile_id' => $otherStudent->id,
            'assignment_marks' => 7654.32,
            'quiz_marks' => 8765.43,
            'total_marks' => 9876.54,
            'maximum_marks' => 10000,
            'percentage' => 98.76,
            'grade' => 'A+',
            'result_status' => 'passed',
        ]);
        $validCertificate = $this->createCertificate(
            $course,
            $student,
            'CERT-GRADEBOOK-VALID'
        );
        $validCertificate->update(['gradebook_id' => $validGradebook->id]);

        // Create a legacy inconsistent relationship directly to exercise response-time protection.
        $otherStudentsCertificate = $this->createCertificate(
            $otherStudentCourse,
            $student,
            'CERT-GRADEBOOK-OTHER-STUDENT'
        );
        $otherStudentsCertificate->update(['gradebook_id' => $otherStudentsGradebook->id]);

        $otherCoursesCertificate = $this->createCertificate(
            $otherCourse,
            $student,
            'CERT-GRADEBOOK-OTHER-COURSE'
        );
        $otherCoursesCertificate->update(['gradebook_id' => $validGradebook->id]);

        $missingGradebookCertificate = $this->createCertificate(
            $missingGradebookCourse,
            $student,
            'CERT-GRADEBOOK-MISSING'
        );
        $parent = $this->createParent($institution, $student);

        $this->authenticate($parent);

        $dashboardResponse = $this->getJson('/api/parent-dashboard/' . $student->id)
            ->assertOk();
        $dashboardCertificates = collect($dashboardResponse->json('data.certificates'))->keyBy('id');
        $this->assertSame(
            $validGradebook->id,
            $dashboardCertificates[$validCertificate->id]['gradebook']['id']
        );
        $this->assertNull($dashboardCertificates[$otherStudentsCertificate->id]['gradebook']);
        $this->assertNull($dashboardCertificates[$otherCoursesCertificate->id]['gradebook']);
        $this->assertNull($dashboardCertificates[$missingGradebookCertificate->id]['gradebook']);

        $listingResponse = $this->getJson('/api/certificates')->assertOk();
        $listedCertificates = collect($listingResponse->json('data.data'))->keyBy('id');
        $this->assertSame(
            $validGradebook->id,
            $listedCertificates[$validCertificate->id]['gradebook']['id']
        );
        $this->assertNull($listedCertificates[$otherStudentsCertificate->id]['gradebook']);
        $this->assertNull($listedCertificates[$otherCoursesCertificate->id]['gradebook']);
        $this->assertNull($listedCertificates[$missingGradebookCertificate->id]['gradebook']);

        $this->getJson('/api/certificates/' . $validCertificate->id)
            ->assertOk()
            ->assertJsonPath('data.gradebook.id', $validGradebook->id);
        $this->getJson('/api/certificates/' . $otherStudentsCertificate->id)
            ->assertOk()
            ->assertJsonPath('data.gradebook', null)
            ->assertJsonMissing(['total_marks' => '9876.54'])
            ->assertJsonMissing(['quiz_marks' => '8765.43']);
        $this->getJson('/api/certificates/' . $otherCoursesCertificate->id)
            ->assertOk()
            ->assertJsonPath('data.gradebook', null);
        $this->getJson('/api/certificates/' . $missingGradebookCertificate->id)
            ->assertOk()
            ->assertJsonPath('data.gradebook', null);

        foreach ([$dashboardResponse->json(), $listingResponse->json()] as $parentPayload) {
            $serializedPayload = json_encode($parentPayload);
            $this->assertIsString($serializedPayload);
            $this->assertStringNotContainsString('9876.54', $serializedPayload);
            $this->assertStringNotContainsString('8765.43', $serializedPayload);
        }

        $this->authenticate($teacherUser);
        $this->getJson('/api/certificates/' . $otherStudentsCertificate->id)
            ->assertOk()
            ->assertJsonPath('data.gradebook.id', $otherStudentsGradebook->id)
            ->assertJsonPath('data.gradebook.total_marks', '9876.54');

        foreach ([
            $this->createInstitutionAdmin($institution),
            $this->createUserWithRole('super-admin'),
        ] as $staffAdmin) {
            $this->authenticate($staffAdmin);
            $this->getJson('/api/certificates/' . $otherStudentsCertificate->id)
                ->assertOk()
                ->assertJsonPath('data.gradebook.id', $otherStudentsGradebook->id);
        }
    }

    public function test_institution_admin_certificate_access_uses_all_active_memberships(): void
    {
        $firstInstitution = $this->createInstitution('CERT-ADMIN-MULTI-A');
        $secondInstitution = $this->createInstitution('CERT-ADMIN-MULTI-B');
        $inactiveInstitution = $this->createInstitution('CERT-ADMIN-MULTI-INACTIVE');
        $unrelatedInstitution = $this->createInstitution('CERT-ADMIN-MULTI-OTHER');

        [, $firstTeacher] = $this->createTeacher($firstInstitution);
        [, $secondTeacher] = $this->createTeacher($secondInstitution);
        [, $inactiveTeacher] = $this->createTeacher($inactiveInstitution);
        [, $unrelatedTeacher] = $this->createTeacher($unrelatedInstitution);
        [, $firstStudent] = $this->createStudent($firstInstitution);
        [, $secondStudent] = $this->createStudent($secondInstitution);
        [, $inactiveStudent] = $this->createStudent($inactiveInstitution);
        [, $unrelatedStudent] = $this->createStudent($unrelatedInstitution);

        $firstCourse = $this->createCourse($firstInstitution, $firstTeacher, 'certificate-admin-multi-a');
        $secondCourse = $this->createCourse($secondInstitution, $secondTeacher, 'certificate-admin-multi-b');
        $inactiveCourse = $this->createCourse(
            $inactiveInstitution,
            $inactiveTeacher,
            'certificate-admin-multi-inactive'
        );
        $unrelatedCourse = $this->createCourse(
            $unrelatedInstitution,
            $unrelatedTeacher,
            'certificate-admin-multi-other'
        );

        $firstCertificate = $this->createCertificate($firstCourse, $firstStudent, 'CERT-ADMIN-MULTI-A');
        $secondCertificate = $this->createCertificate($secondCourse, $secondStudent, 'CERT-ADMIN-MULTI-B');
        $inactiveCertificate = $this->createCertificate(
            $inactiveCourse,
            $inactiveStudent,
            'CERT-ADMIN-MULTI-INACTIVE'
        );
        $unrelatedCertificate = $this->createCertificate(
            $unrelatedCourse,
            $unrelatedStudent,
            'CERT-ADMIN-MULTI-OTHER'
        );
        $inconsistentCertificate = $this->createCertificate(
            $secondCourse,
            $inactiveStudent,
            'CERT-ADMIN-MULTI-INCONSISTENT'
        );

        foreach ([
            [$firstCertificate, 'admin-a.pdf'],
            [$secondCertificate, 'admin-b.pdf'],
            [$inactiveCertificate, 'admin-inactive.pdf'],
            [$unrelatedCertificate, 'admin-other.pdf'],
        ] as [$certificate, $path]) {
            $certificate->update(['certificate_file' => $path]);
        }

        $admin = $this->createInstitutionAdmin($firstInstitution);
        InstitutionUser::create([
            'institution_id' => $secondInstitution->id,
            'user_id' => $admin->id,
            'role_in_institution' => 'admin',
            'status' => 'active',
        ]);
        InstitutionUser::create([
            'institution_id' => $inactiveInstitution->id,
            'user_id' => $admin->id,
            'role_in_institution' => 'admin',
            'status' => 'inactive',
        ]);

        Storage::fake('certificates');
        foreach (['admin-a.pdf', 'admin-b.pdf', 'admin-inactive.pdf', 'admin-other.pdf'] as $path) {
            Storage::disk('certificates')->put($path, '%PDF-1.4 admin certificate fixture');
        }

        $this->authenticate($admin);

        $listing = $this->getJson('/api/certificates')->assertOk();
        $listedIds = collect($listing->json('data.data'))->pluck('id')->all();
        $this->assertEqualsCanonicalizing(
            [$firstCertificate->id, $secondCertificate->id],
            $listedIds
        );

        $this->getJson('/api/certificates/' . $firstCertificate->id)->assertOk();
        $this->getJson('/api/certificates/' . $secondCertificate->id)->assertOk();
        foreach ([$inactiveCertificate, $unrelatedCertificate, $inconsistentCertificate] as $certificate) {
            $this->assertForbiddenResponse(
                $this->getJson('/api/certificates/' . $certificate->id),
                'institution admin reading a certificate outside an active, consistent institution'
            );
        }

        $this->get('/api/certificates/' . $secondCertificate->id . '/download')
            ->assertDownload('admin-b.pdf');
        $this->assertForbiddenResponse(
            $this->getJson('/api/certificates/' . $inactiveCertificate->id . '/download'),
            'institution admin downloading a certificate through an inactive membership'
        );

        $this->patchJson('/api/certificates/' . $secondCertificate->id, [
            'remarks' => 'Updated by a multi-institution administrator.',
        ])->assertOk();
        $this->assertForbiddenResponse(
            $this->patchJson('/api/certificates/' . $inactiveCertificate->id, [
                'remarks' => 'Should not be changed.',
            ]),
            'institution admin updating a certificate through an inactive membership'
        );

        $pdf = \Mockery::mock(\Barryvdh\DomPDF\PDF::class);
        $pdf->shouldReceive('setPaper')->once()->with('a4', 'landscape')->andReturnSelf();
        $pdf->shouldReceive('output')->once()->andReturn('%PDF-1.4 regenerated certificate');
        Pdf::shouldReceive('loadView')->once()->andReturn($pdf);

        $this->postJson('/api/certificates/' . $secondCertificate->id . '/generate')
            ->assertOk();
        $this->assertForbiddenResponse(
            $this->postJson('/api/certificates/' . $inactiveCertificate->id . '/generate'),
            'institution admin generating a certificate through an inactive membership'
        );

        $this->deleteJson('/api/certificates/' . $secondCertificate->id)->assertOk();
        $this->assertForbiddenResponse(
            $this->deleteJson('/api/certificates/' . $inactiveCertificate->id),
            'institution admin deleting a certificate through an inactive membership'
        );
    }

    public function test_institution_admin_can_issue_for_a_second_active_membership_but_not_an_inactive_one(): void
    {
        $firstInstitution = $this->createInstitution('CERT-ADMIN-ISSUE-A');
        $authorizedInstitution = $this->createInstitution('CERT-ADMIN-ISSUE-B');
        $inactiveInstitution = $this->createInstitution('CERT-ADMIN-ISSUE-INACTIVE');
        [, $authorizedTeacher] = $this->createTeacher($authorizedInstitution);
        [, $inactiveTeacher] = $this->createTeacher($inactiveInstitution);
        [, $authorizedStudent] = $this->createStudent($authorizedInstitution);
        [, $inactiveStudent] = $this->createStudent($inactiveInstitution);
        $authorizedCourse = $this->createCourse(
            $authorizedInstitution,
            $authorizedTeacher,
            'certificate-admin-issue-authorized'
        );
        $inactiveCourse = $this->createCourse(
            $inactiveInstitution,
            $inactiveTeacher,
            'certificate-admin-issue-inactive'
        );
        $this->createEnrollment($authorizedCourse, $authorizedStudent);
        $this->createEnrollment($inactiveCourse, $inactiveStudent);
        $gradebook = $this->createPassingGradebook($authorizedCourse, $authorizedStudent);

        $admin = $this->createInstitutionAdmin($firstInstitution);
        InstitutionUser::create([
            'institution_id' => $authorizedInstitution->id,
            'user_id' => $admin->id,
            'role_in_institution' => 'admin',
            'status' => 'active',
        ]);
        InstitutionUser::create([
            'institution_id' => $inactiveInstitution->id,
            'user_id' => $admin->id,
            'role_in_institution' => 'admin',
            'status' => 'inactive',
        ]);

        $this->mock(\App\Services\GradebookCalculationService::class)
            ->shouldReceive('recalculate')
            ->once()
            ->with((int) $authorizedCourse->id, (int) $authorizedStudent->id)
            ->andReturn($gradebook);

        Storage::fake('certificates');
        $pdf = \Mockery::mock(\Barryvdh\DomPDF\PDF::class);
        $pdf->shouldReceive('setPaper')->once()->with('a4', 'landscape')->andReturnSelf();
        $pdf->shouldReceive('output')->once()->andReturn('%PDF-1.4 issued certificate');
        Pdf::shouldReceive('loadView')->once()->andReturn($pdf);

        $this->authenticate($admin);

        $this->postJson('/api/certificates', [
            'course_id' => $inactiveCourse->id,
            'student_profile_id' => $inactiveStudent->id,
        ])->assertForbidden();

        $this->postJson('/api/certificates', [
            'course_id' => $authorizedCourse->id,
            'student_profile_id' => $authorizedStudent->id,
        ])->assertCreated();

        $this->assertDatabaseHas('certificates', [
            'course_id' => $authorizedCourse->id,
            'student_profile_id' => $authorizedStudent->id,
            'status' => 'issued',
        ]);
        $this->assertDatabaseMissing('certificates', [
            'course_id' => $inactiveCourse->id,
            'student_profile_id' => $inactiveStudent->id,
        ]);
    }

    public function test_super_admin_certificate_access_does_not_require_institution_membership(): void
    {
        $institution = $this->createInstitution('CERT-SUPER-ADMIN-ACCESS');
        [, $teacher] = $this->createTeacher($institution);
        [, $student] = $this->createStudent($institution);
        $course = $this->createCourse($institution, $teacher, 'certificate-super-admin-access');
        $certificate = $this->createCertificate($course, $student, 'CERT-SUPER-ADMIN-ACCESS-001');
        $certificate->update(['certificate_file' => 'super-admin-certificate.pdf']);
        $superAdmin = $this->createUserWithRole('super-admin');

        Storage::fake('certificates');
        Storage::disk('certificates')->put('super-admin-certificate.pdf', '%PDF-1.4 super admin fixture');

        $this->authenticate($superAdmin);

        $this->getJson('/api/certificates')
            ->assertOk()
            ->assertJsonCount(1, 'data.data')
            ->assertJsonPath('data.data.0.id', $certificate->id);
        $this->getJson('/api/certificates/' . $certificate->id)->assertOk();
        $this->get('/api/certificates/' . $certificate->id . '/download')
            ->assertDownload('super-admin-certificate.pdf');
    }

    public function test_parent_certificate_access_supports_multiple_children(): void
    {
        $institution = $this->createInstitution('CERT-MULTI');

        [, $firstStudent] = $this->createStudent($institution);
        [, $secondStudent] = $this->createStudent($institution);
        [, $unrelatedStudent] = $this->createStudent($institution);

        [, $teacher] = $this->createTeacher($institution);

        $firstCourse = $this->createCourse(
            $institution,
            $teacher,
            'certificate-child-one'
        );

        $secondCourse = $this->createCourse(
            $institution,
            $teacher,
            'certificate-child-two'
        );

        $unrelatedCourse = $this->createCourse(
            $institution,
            $teacher,
            'certificate-unrelated'
        );

        $this->createEnrollment($firstCourse, $firstStudent);
        $this->createEnrollment($secondCourse, $secondStudent);
        $this->createEnrollment($unrelatedCourse, $unrelatedStudent);

        $firstCertificate = $this->createCertificate(
            $firstCourse,
            $firstStudent,
            'CERT-MULTI-001'
        );

        $secondCertificate = $this->createCertificate(
            $secondCourse,
            $secondStudent,
            'CERT-MULTI-002'
        );

        $unrelatedCertificate = $this->createCertificate(
            $unrelatedCourse,
            $unrelatedStudent,
            'CERT-MULTI-003'
        );

        $parentUser = $this->createParent($institution, $firstStudent);

        ParentProfile::create([
            'institution_id' => $institution->id,
            'user_id' => $parentUser->id,
            'student_profile_id' => $secondStudent->id,
            'status' => 'active',
        ]);

        $inactiveStudent = $this->createStudent($institution)[1];

        $inactiveCertificate = $this->createCertificate(
            $firstCourse,
            $inactiveStudent,
            'CERT-MULTI-004'
        );

        ParentProfile::create([
            'institution_id' => $institution->id,
            'user_id' => $parentUser->id,
            'student_profile_id' => $inactiveStudent->id,
            'status' => 'inactive',
        ]);

        $this->authenticate($parentUser);

        $this->getJson('/api/certificates')
            ->assertOk()
            ->assertJsonCount(2, 'data.data')
            ->assertJsonFragment(['id' => $firstCertificate->id])
            ->assertJsonFragment(['id' => $secondCertificate->id])
            ->assertJsonMissing(['id' => $unrelatedCertificate->id])
            ->assertJsonMissing(['id' => $inactiveCertificate->id]);

        $this->getJson('/api/certificates/' . $firstCertificate->id)
            ->assertOk();

        $this->getJson('/api/certificates/' . $secondCertificate->id)
            ->assertOk();

        $this->assertForbiddenResponse(
            $this->getJson('/api/certificates/' . $unrelatedCertificate->id),
            'parent reading unrelated child certificate'
        );

        $this->assertForbiddenResponse(
            $this->getJson('/api/certificates/' . $unrelatedCertificate->id . '/download'),
            'parent downloading unrelated child certificate'
        );

        $this->assertForbiddenResponse(
            $this->getJson('/api/certificates/' . $inactiveCertificate->id),
            'parent reading inactive linked child certificate'
        );
    }

    public function test_same_institution_parent_profile_creation_and_certificate_download_are_allowed(): void
    {
        $institution = $this->createInstitution('PARENT-PROFILE-VALID');
        $admin = $this->createInstitutionAdmin($institution);
        $parentUser = $this->createUserWithRole('parent');
        [, $student] = $this->createStudent($institution);
        [, $teacher] = $this->createTeacher($institution);

        $this->authenticate($admin);
        $this->postJson('/api/parent-profiles', [
            'institution_id' => $institution->id,
            'user_id' => $parentUser->id,
            'student_profile_id' => $student->id,
            'status' => 'active',
        ])->assertCreated()
            ->assertJsonPath('data.institution_id', $institution->id)
            ->assertJsonPath('data.user_id', $parentUser->id)
            ->assertJsonPath('data.student_profile_id', $student->id);

        $course = $this->createCourse($institution, $teacher, 'parent-profile-valid-download');
        $this->createEnrollment($course, $student);
        $certificate = $this->createCertificate($course, $student, 'PARENT-PROFILE-VALID-001');
        $certificate->update(['certificate_file' => 'parent-profile-valid.pdf']);
        Storage::fake('certificates');
        Storage::disk('certificates')->put('parent-profile-valid.pdf', '%PDF-1.4 same institution');

        $this->authenticate($parentUser);
        $response = $this->get('/api/certificates/' . $certificate->id . '/download')
            ->assertDownload('parent-profile-valid.pdf');

        $this->assertSame('%PDF-1.4 same institution', $response->streamedContent());
    }

    public function test_parent_profile_creation_rejects_cross_institution_parent_child_link(): void
    {
        $firstInstitution = $this->createInstitution('PARENT-PROFILE-CREATE-A');
        $secondInstitution = $this->createInstitution('PARENT-PROFILE-CREATE-B');
        $admin = $this->createInstitutionAdmin($firstInstitution);
        $parentUser = $this->createUserWithRole('parent');
        [, $student] = $this->createStudent($secondInstitution);

        $this->authenticate($admin);
        $this->postJson('/api/parent-profiles', [
            'institution_id' => $firstInstitution->id,
            'user_id' => $parentUser->id,
            'student_profile_id' => $student->id,
            'status' => 'active',
        ])->assertUnprocessable();

        $this->assertDatabaseMissing('parent_profiles', [
            'institution_id' => $firstInstitution->id,
            'user_id' => $parentUser->id,
            'student_profile_id' => $student->id,
        ]);
    }

    public function test_parent_profile_creation_requires_a_user_with_the_parent_role(): void
    {
        $institution = $this->createInstitution('PARENT-PROFILE-ROLE');
        $admin = $this->createInstitutionAdmin($institution);
        [$studentUser, $student] = $this->createStudent($institution);

        $this->authenticate($admin);
        $this->postJson('/api/parent-profiles', [
            'institution_id' => $institution->id,
            'user_id' => $studentUser->id,
            'student_profile_id' => $student->id,
            'status' => 'active',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('user_id');
    }

    public function test_parent_profile_update_rejects_cross_institution_link_when_only_student_changes(): void
    {
        $firstInstitution = $this->createInstitution('PARENT-PROFILE-UPDATE-A');
        $secondInstitution = $this->createInstitution('PARENT-PROFILE-UPDATE-B');
        $admin = $this->createInstitutionAdmin($firstInstitution);
        [, $firstStudent] = $this->createStudent($firstInstitution);
        [, $secondStudent] = $this->createStudent($secondInstitution);
        $parentUser = $this->createUserWithRole('parent');
        $parentProfile = ParentProfile::create([
            'institution_id' => $firstInstitution->id,
            'user_id' => $parentUser->id,
            'student_profile_id' => $firstStudent->id,
            'status' => 'active',
        ]);

        $this->authenticate($admin);
        $this->patchJson('/api/parent-profiles/' . $parentProfile->id, [
            'student_profile_id' => $secondStudent->id,
        ])->assertUnprocessable();

        $this->patchJson('/api/parent-profiles/' . $parentProfile->id, [
            'institution_id' => $secondInstitution->id,
        ])->assertUnprocessable();

        $this->assertSame(
            $firstStudent->id,
            $parentProfile->fresh()->student_profile_id
        );
        $this->assertSame(
            $firstInstitution->id,
            $parentProfile->fresh()->institution_id
        );
    }

    public function test_parent_certificate_download_denies_inconsistent_profile_or_course_institution(): void
    {
        $firstInstitution = $this->createInstitution('PARENT-CERT-CONSISTENCY-A');
        $secondInstitution = $this->createInstitution('PARENT-CERT-CONSISTENCY-B');
        [, $teacher] = $this->createTeacher($firstInstitution);
        [, $firstStudent] = $this->createStudent($firstInstitution);
        [, $secondStudent] = $this->createStudent($secondInstitution);
        $course = $this->createCourse($firstInstitution, $teacher, 'parent-cert-consistency');
        $firstCertificate = $this->createCertificate(
            $course,
            $firstStudent,
            'PARENT-CERT-CONSISTENCY-001'
        );
        $secondCertificate = $this->createCertificate(
            $course,
            $secondStudent,
            'PARENT-CERT-CONSISTENCY-002'
        );
        $parentUser = $this->createUserWithRole('parent');

        ParentProfile::create([
            'institution_id' => $secondInstitution->id,
            'user_id' => $parentUser->id,
            'student_profile_id' => $firstStudent->id,
            'status' => 'active',
        ]);
        ParentProfile::create([
            'institution_id' => $secondInstitution->id,
            'user_id' => $parentUser->id,
            'student_profile_id' => $secondStudent->id,
            'status' => 'active',
        ]);

        $this->authenticate($parentUser);
        $this->assertForbiddenResponse(
            $this->get('/api/certificates/' . $firstCertificate->id . '/download'),
            'parent profile institution differs from certificate student institution'
        );
        $this->assertForbiddenResponse(
            $this->get('/api/certificates/' . $secondCertificate->id . '/download'),
            'certificate course institution differs from certificate student institution'
        );

        $this->getJson('/api/certificates')
            ->assertOk()
            ->assertJsonMissing(['id' => $firstCertificate->id])
            ->assertJsonMissing(['id' => $secondCertificate->id]);
    }

    public function test_institution_admin_cannot_manage_parent_profiles_outside_authorized_institution(): void
    {
        $firstInstitution = $this->createInstitution('PARENT-PROFILE-ADMIN-A');
        $secondInstitution = $this->createInstitution('PARENT-PROFILE-ADMIN-B');
        $admin = $this->createInstitutionAdmin($firstInstitution);
        [, $firstStudent] = $this->createStudent($firstInstitution);
        [, $secondStudent] = $this->createStudent($secondInstitution);
        $firstParent = $this->createUserWithRole('parent');
        $secondParent = $this->createUserWithRole('parent');
        $firstProfile = ParentProfile::create([
            'institution_id' => $firstInstitution->id,
            'user_id' => $firstParent->id,
            'student_profile_id' => $firstStudent->id,
            'status' => 'active',
        ]);
        $secondProfile = ParentProfile::create([
            'institution_id' => $secondInstitution->id,
            'user_id' => $secondParent->id,
            'student_profile_id' => $secondStudent->id,
            'status' => 'active',
        ]);

        $this->authenticate($admin);
        $this->getJson('/api/parent-profiles')
            ->assertOk()
            ->assertJsonCount(1, 'data.data')
            ->assertJsonPath('data.data.0.id', $firstProfile->id);

        $this->assertForbiddenResponse(
            $this->getJson('/api/parent-profiles/' . $secondProfile->id),
            'institution admin reading another institution parent profile'
        );
        $this->assertForbiddenResponse(
            $this->patchJson('/api/parent-profiles/' . $secondProfile->id, ['status' => 'inactive']),
            'institution admin updating another institution parent profile'
        );
        $this->assertForbiddenResponse(
            $this->deleteJson('/api/parent-profiles/' . $secondProfile->id),
            'institution admin deleting another institution parent profile'
        );
        $this->assertForbiddenResponse(
            $this->postJson('/api/parent-profiles', [
                'institution_id' => $secondInstitution->id,
                'user_id' => $this->createUserWithRole('parent')->id,
                'student_profile_id' => $secondStudent->id,
                'status' => 'active',
            ]),
            'institution admin creating a parent profile for another institution'
        );

        $this->assertDatabaseHas('parent_profiles', ['id' => $secondProfile->id]);
    }

    public function test_pending_manual_grading_blocks_certificate_issue_generation_and_download_without_mutating_existing_record(): void
    {
        $fixture = $this->createQuizResultVisibilityFixture('CERT-PENDING-ISSUE', false);
        $attempt = QuizAttempt::create([
            'quiz_id' => $fixture['quiz']->id,
            'student_profile_id' => $fixture['student']->id,
            'attempt_number' => 1,
            'started_at' => now()->subMinutes(10),
            'submitted_at' => now()->subMinutes(5),
            'evaluated_at' => now()->subMinutes(4),
            'total_marks' => 16,
            'marks_obtained' => 12,
            'percentage' => 75,
            'result_status' => 'passed',
            'status' => 'evaluated',
        ]);
        QuizAnswer::create([
            'quiz_attempt_id' => $attempt->id,
            'question_bank_id' => $fixture['manual_questions']['short_answer']->id,
            'answer_text' => 'Legacy answer without a grading marker.',
            'is_correct' => true,
            'marks_obtained' => 5,
            'manually_graded_at' => null,
        ]);
        $gradebook = Gradebook::create([
            'course_id' => $fixture['quiz']->course_id,
            'student_profile_id' => $fixture['student']->id,
            'assignment_marks' => 80,
            'quiz_marks' => 75,
            'total_marks' => 155,
            'maximum_marks' => 200,
            'percentage' => 77.5,
            'grade' => 'B',
            'result_status' => 'passed',
        ]);

        Storage::fake('certificates');
        Storage::fake('public');
        $this->authenticate($fixture['teacher_user']);

        $this->postJson('/api/certificates', [
            'course_id' => $fixture['quiz']->course_id,
            'student_profile_id' => $fixture['student']->id,
        ])->assertUnprocessable()
            ->assertJsonPath('grading_pending', true);

        $this->assertDatabaseMissing('certificates', [
            'course_id' => $fixture['quiz']->course_id,
            'student_profile_id' => $fixture['student']->id,
        ]);

        $certificate = $this->createCertificate(
            $fixture['quiz']->course,
            $fixture['student'],
            'CERT-PENDING-ISSUE-001'
        );
        $certificate->update([
            'gradebook_id' => $gradebook->id,
            'certificate_file' => 'certificates/stale-certificate.pdf',
            'status' => 'pending',
            'verification_status' => 'revoked',
        ]);
        Storage::disk('public')->put(
            $certificate->certificate_file,
            'stale certificate contents'
        );
        $originalCertificate = $certificate->fresh()->getAttributes();

        $this->patchJson('/api/certificates/' . $certificate->id, [
            'status' => 'issued',
            'verification_status' => 'valid',
        ])->assertUnprocessable()
            ->assertJsonPath('grading_pending', true);
        $this->postJson('/api/certificates/' . $certificate->id . '/generate')
            ->assertUnprocessable()
            ->assertJsonPath('grading_pending', true);

        $this->authenticate($fixture['student_user']);
        $this->getJson('/api/certificates/' . $certificate->id . '/download')
            ->assertUnprocessable()
            ->assertJsonPath('grading_pending', true);

        Storage::disk('public')->assertExists('certificates/stale-certificate.pdf');
        Storage::disk('certificates')->assertMissing('certificates/stale-certificate.pdf');
        $this->assertSame(
            'stale certificate contents',
            Storage::disk('public')->get('certificates/stale-certificate.pdf')
        );
        $this->assertSame($originalCertificate, $certificate->fresh()->getAttributes());

        $certificate->update(['certificate_file' => null]);
        $this->getJson('/api/certificates/' . $certificate->id . '/download')
            ->assertUnprocessable()
            ->assertJsonPath('grading_pending', true);
        $this->assertNull($certificate->fresh()->certificate_file);
    }

    public function test_certificate_issuance_recalculates_stale_passing_gradebook_after_manual_mark_is_finalized(): void
    {
        $fixture = $this->createQuizResultVisibilityFixture('CERT-FRESH-GRADEBOOK-FAIL', false);
        $gradebook = $this->createPassingGradebook($fixture['quiz']->course, $fixture['student']);
        $certificate = $this->createCertificate(
            $fixture['quiz']->course,
            $fixture['student'],
            'CERT-FRESH-GRADEBOOK-FAIL-001'
        );
        $certificate->update([
            'gradebook_id' => $gradebook->id,
            'final_percentage' => 88.25,
            'final_grade' => 'A',
            'certificate_file' => 'existing-issued-certificate.pdf',
        ]);
        $originalSnapshot = $this->certificateSnapshot($certificate);

        $attempt = QuizAttempt::create([
            'quiz_id' => $fixture['quiz']->id,
            'student_profile_id' => $fixture['student']->id,
            'attempt_number' => 1,
            'started_at' => now()->subMinutes(10),
            'submitted_at' => now()->subMinutes(5),
            'total_marks' => 16,
            'marks_obtained' => 6,
            'percentage' => 37.5,
            'result_status' => 'pending',
            'status' => 'submitted',
        ]);
        $manualAnswer = QuizAnswer::create([
            'quiz_attempt_id' => $attempt->id,
            'question_bank_id' => $fixture['manual_questions']['short_answer']->id,
            'answer_text' => 'Answer awaiting final marks.',
            'is_correct' => true,
            'marks_obtained' => 5,
            'manually_graded_at' => null,
        ]);
        QuizAnswer::create([
            'quiz_attempt_id' => $attempt->id,
            'question_bank_id' => $fixture['automatic_question']->id,
            'question_option_id' => $fixture['correct_option']->id,
            'is_correct' => true,
            'marks_obtained' => 1,
        ]);

        Storage::fake('certificates');
        Storage::fake('public');
        Storage::disk('certificates')->put(
            'existing-issued-certificate.pdf',
            '%PDF-1.4 existing issued snapshot'
        );
        $this->createCertificateSetting(
            Institution::findOrFail($fixture['student']->institution_id)
        );
        Pdf::shouldReceive('loadView')->never();

        $this->authenticate($fixture['teacher_user']);
        $this->patchJson('/api/quiz-answers/' . $manualAnswer->id, [
            'marks_obtained' => 0,
        ])->assertOk();

        $this->assertSame('evaluated', $attempt->fresh()->status);
        $this->assertSame('failed', $attempt->fresh()->result_status);
        $this->assertNotNull($manualAnswer->fresh()->manually_graded_at);
        $this->assertSame('passed', $gradebook->fresh()->result_status);

        $this->postJson('/api/certificates', [
            'course_id' => $fixture['quiz']->course_id,
            'student_profile_id' => $fixture['student']->id,
        ])->assertUnprocessable()
            ->assertJsonPath(
                'message',
                'Certificate cannot be issued because student has not passed.'
            );

        $this->assertSame('6.25', $gradebook->fresh()->percentage);
        $this->assertSame('failed', $gradebook->fresh()->result_status);
        $this->assertSame($originalSnapshot, $this->certificateSnapshot($certificate->fresh()));
        Storage::disk('certificates')->assertExists('existing-issued-certificate.pdf');
        $this->assertSame(
            ['existing-issued-certificate.pdf'],
            Storage::disk('certificates')->allFiles()
        );
    }

    public function test_certificate_issuance_can_use_a_fresh_passing_gradebook_after_manual_grading(): void
    {
        $fixture = $this->createQuizResultVisibilityFixture('CERT-FRESH-GRADEBOOK-PASS', false);
        $gradebook = Gradebook::create([
            'course_id' => $fixture['quiz']->course_id,
            'student_profile_id' => $fixture['student']->id,
            'assignment_marks' => 0,
            'quiz_marks' => 0,
            'total_marks' => 0,
            'maximum_marks' => 16,
            'percentage' => 0,
            'grade' => 'F',
            'result_status' => 'failed',
        ]);
        $attempt = QuizAttempt::create([
            'quiz_id' => $fixture['quiz']->id,
            'student_profile_id' => $fixture['student']->id,
            'attempt_number' => 1,
            'started_at' => now()->subMinutes(10),
            'submitted_at' => now()->subMinutes(5),
            'total_marks' => 16,
            'marks_obtained' => 0,
            'percentage' => 0,
            'result_status' => 'pending',
            'status' => 'submitted',
        ]);
        $firstManualAnswer = QuizAnswer::create([
            'quiz_attempt_id' => $attempt->id,
            'question_bank_id' => $fixture['manual_questions']['short_answer']->id,
            'answer_text' => 'First answer.',
            'is_correct' => false,
            'marks_obtained' => 0,
            'manually_graded_at' => null,
        ]);
        $secondManualAnswer = QuizAnswer::create([
            'quiz_attempt_id' => $attempt->id,
            'question_bank_id' => $fixture['manual_questions']['long_answer']->id,
            'answer_text' => 'Second answer.',
            'is_correct' => false,
            'marks_obtained' => 0,
            'manually_graded_at' => null,
        ]);
        $this->createCertificateSetting(
            Institution::findOrFail($fixture['student']->institution_id)
        );
        Storage::fake('certificates');
        Storage::fake('public');

        $pdf = \Mockery::mock(\Barryvdh\DomPDF\PDF::class);
        $pdf->shouldReceive('setPaper')->once()->with('a4', 'landscape')->andReturnSelf();
        $pdf->shouldReceive('output')->once()->andReturn('%PDF-1.4 fresh passing certificate');
        Pdf::shouldReceive('loadView')->once()->andReturn($pdf);

        $this->authenticate($fixture['teacher_user']);
        $this->patchJson('/api/quiz-answers/' . $firstManualAnswer->id, [
            'marks_obtained' => 5,
        ])->assertOk();
        $this->patchJson('/api/quiz-answers/' . $secondManualAnswer->id, [
            'marks_obtained' => 5,
        ])->assertOk();

        $this->assertSame('evaluated', $attempt->fresh()->status);
        $this->assertSame('passed', $attempt->fresh()->result_status);
        $this->assertSame('failed', $gradebook->fresh()->result_status);

        $response = $this->postJson('/api/certificates', [
            'course_id' => $fixture['quiz']->course_id,
            'student_profile_id' => $fixture['student']->id,
        ])->assertCreated();

        $certificate = Certificate::where('course_id', $fixture['quiz']->course_id)
            ->where('student_profile_id', $fixture['student']->id)
            ->firstOrFail();
        $this->assertSame('passed', $gradebook->fresh()->result_status);
        $this->assertSame('62.50', $gradebook->fresh()->percentage);
        $this->assertSame('62.50', $certificate->final_percentage);
        $this->assertSame('B', $certificate->final_grade);
        $this->assertSame($certificate->id, $response->json('data.id'));
        Storage::disk('certificates')->assertExists($certificate->certificate_file);
    }

    public function test_gradebook_refresh_does_not_rewrite_an_existing_certificate_snapshot(): void
    {
        $fixture = $this->createQuizResultVisibilityFixture('CERT-SNAPSHOT-GRADEBOOK-REFRESH', false);
        $gradebook = $this->createPassingGradebook($fixture['quiz']->course, $fixture['student']);
        $attempt = QuizAttempt::create([
            'quiz_id' => $fixture['quiz']->id,
            'student_profile_id' => $fixture['student']->id,
            'attempt_number' => 1,
            'started_at' => now()->subMinutes(10),
            'submitted_at' => now()->subMinutes(5),
            'evaluated_at' => now()->subMinutes(4),
            'total_marks' => 16,
            'marks_obtained' => 8,
            'percentage' => 50,
            'result_status' => 'passed',
            'status' => 'evaluated',
        ]);
        $certificate = $this->createCertificate(
            $fixture['quiz']->course,
            $fixture['student'],
            'CERT-SNAPSHOT-GRADEBOOK-REFRESH-001'
        );
        $certificate->update([
            'gradebook_id' => $gradebook->id,
            'final_percentage' => 91.75,
            'final_grade' => 'A+',
        ]);
        $originalSnapshot = $this->certificateSnapshot($certificate);

        $this->authenticate($fixture['teacher_user']);
        $this->postJson('/api/gradebooks/recalculate', [
            'course_id' => $fixture['quiz']->course_id,
            'student_profile_id' => $fixture['student']->id,
        ])->assertOk()
            ->assertJsonPath('data.quiz_marks', '8.00')
            ->assertJsonPath('data.percentage', '50.00');

        $this->assertSame('evaluated', $attempt->fresh()->status);
        $this->assertSame($originalSnapshot, $this->certificateSnapshot($certificate->fresh()));
    }

    public function test_certificate_reconciliation_reports_matching_snapshot_for_multi_institution_admin(): void
    {
        $fixture = $this->createCertificateReconciliationFixture('RECONCILE-MATCH');
        $authorizedInstitution = Institution::findOrFail($fixture['quiz']->course->institution_id);
        $firstInstitution = $this->createInstitution('RECONCILE-MULTI-ADMIN-A');
        $inactiveInstitution = $this->createInstitution('RECONCILE-MULTI-ADMIN-INACTIVE');
        $admin = $this->createInstitutionAdmin($firstInstitution);
        InstitutionUser::create([
            'institution_id' => $authorizedInstitution->id,
            'user_id' => $admin->id,
            'role_in_institution' => 'admin',
            'status' => 'active',
        ]);
        InstitutionUser::create([
            'institution_id' => $inactiveInstitution->id,
            'user_id' => $admin->id,
            'role_in_institution' => 'admin',
            'status' => 'inactive',
        ]);

        [, $inactiveTeacher] = $this->createTeacher($inactiveInstitution);
        [, $inactiveStudent] = $this->createStudent($inactiveInstitution);
        $inactiveCourse = $this->createCourse(
            $inactiveInstitution,
            $inactiveTeacher,
            'certificate-reconciliation-inactive-membership'
        );
        $inactiveCertificate = $this->createCertificate(
            $inactiveCourse,
            $inactiveStudent,
            'CERT-RECONCILE-INACTIVE-MEMBERSHIP'
        );

        $this->authenticate($admin);

        $this->postJson('/api/certificates/' . $fixture['certificate']->id . '/reconcile')
            ->assertOk()
            ->assertJsonPath('data.snapshot_comparison.discrepancy_detected', false)
            ->assertJsonPath('data.snapshot_comparison.mismatched_fields', [])
            ->assertJsonPath('data.certificate.currently_valid', true)
            ->assertJsonPath('data.current_gradebook.currently_pass_eligible', true);

        $auditLog = ActivityLog::where('module', 'Certificate')
            ->where('action', 'snapshot_reconciled')
            ->firstOrFail();
        $this->assertSame($admin->id, $auditLog->user_id);
        $this->assertSame($authorizedInstitution->id, $auditLog->institution_id);
        $this->assertSame('Course', class_basename($auditLog->model_type));
        $this->assertSame($fixture['quiz']->course_id, $auditLog->model_id);
        $this->assertSame($fixture['certificate']->id, $auditLog->metadata['certificate_id']);
        $this->assertFalse($auditLog->metadata['snapshot_discrepancy_detected']);

        $visibleAuditLogs = app(\App\Services\AuditLogService::class)
            ->timeline($admin, [])
            ->getCollection();
        $this->assertTrue($visibleAuditLogs->contains('id', $auditLog->id));

        $this->postJson('/api/certificates/' . $inactiveCertificate->id . '/reconcile')
            ->assertForbidden();
        $this->assertSame(1, ActivityLog::where('action', 'snapshot_reconciled')->count());
    }

    public function test_certificate_reconciliation_detects_changed_grade_without_changing_certificate_or_pdf(): void
    {
        $fixture = $this->createCertificateReconciliationFixture('RECONCILE-CHANGED');
        $attempt = $fixture['attempt'];
        $attempt->update([
            'marks_obtained' => 6,
            'percentage' => 37.5,
            'result_status' => 'failed',
        ]);

        $privatePdfPath = 'historic-reconciliation-certificate.pdf';
        $fixture['certificate']->update(['certificate_file' => $privatePdfPath]);
        $certificateAttributes = $fixture['certificate']->fresh()->getAttributes();
        $pdfContents = '%PDF-1.4 historical certificate remains unchanged';
        Storage::fake('certificates');
        Storage::disk('certificates')->put($privatePdfPath, $pdfContents);

        $this->authenticate($fixture['teacher_user']);

        $this->postJson('/api/certificates/' . $fixture['certificate']->id . '/reconcile')
            ->assertOk()
            ->assertJsonPath('data.snapshot_comparison.discrepancy_detected', true)
            ->assertJsonPath('data.snapshot_comparison.mismatched_fields', [
                'final_percentage',
                'final_grade',
            ])
            ->assertJsonPath('data.certificate.final_percentage', '75.00')
            ->assertJsonPath('data.certificate.final_grade', 'B+')
            ->assertJsonPath('data.current_gradebook.percentage', '37.50')
            ->assertJsonPath('data.current_gradebook.grade', 'F')
            ->assertJsonPath('data.current_gradebook.result_status', 'failed')
            ->assertJsonPath('data.current_gradebook.currently_pass_eligible', false);

        $this->assertSame($certificateAttributes, $fixture['certificate']->fresh()->getAttributes());
        Storage::disk('certificates')->assertExists($privatePdfPath);
        $this->assertSame($pdfContents, Storage::disk('certificates')->get($privatePdfPath));
        $this->assertSame('37.50', $fixture['gradebook']->fresh()->percentage);
        $this->assertSame('failed', $fixture['gradebook']->fresh()->result_status);

        $auditLog = ActivityLog::where('module', 'Certificate')
            ->where('action', 'snapshot_reconciled')
            ->firstOrFail();
        $this->assertSame($fixture['institution_id'], $auditLog->institution_id);
        $this->assertSame($fixture['teacher_user']->id, $auditLog->user_id);
        $this->assertSame([
            'final_percentage',
            'final_grade',
        ], $auditLog->metadata['mismatched_fields']);
        $this->assertArrayNotHasKey('final_grade', $auditLog->metadata);
        $this->assertArrayNotHasKey('percentage', $auditLog->metadata);
    }

    public function test_certificate_reconciliation_fails_closed_when_gradebook_refresh_fails(): void
    {
        $fixture = $this->createCertificateReconciliationFixture('RECONCILE-FAIL');
        $fixture['gradebook']->update(['percentage' => 74]);
        $certificateAttributes = $fixture['certificate']->fresh()->getAttributes();
        $gradebookAttributes = $fixture['gradebook']->fresh()->getAttributes();
        DB::statement(
            "CREATE TRIGGER fail_reconciliation_gradebook_refresh " .
            "BEFORE UPDATE ON gradebooks " .
            "BEGIN SELECT RAISE(ABORT, 'simulated reconciliation refresh failure'); END"
        );

        $this->authenticate($fixture['teacher_user']);

        $this->postJson('/api/certificates/' . $fixture['certificate']->id . '/reconcile')
            ->assertStatus(503)
            ->assertJsonPath(
                'message',
                'Certificate reconciliation could not be completed.'
            )
            ->assertJsonMissingPath('data');

        $this->assertSame($certificateAttributes, $fixture['certificate']->fresh()->getAttributes());
        $this->assertSame($gradebookAttributes, $fixture['gradebook']->fresh()->getAttributes());
        $this->assertSame(0, ActivityLog::where('action', 'snapshot_reconciled')->count());
    }

    public function test_certificate_reconciliation_rolls_back_gradebook_refresh_if_audit_write_fails(): void
    {
        $fixture = $this->createCertificateReconciliationFixture('RECONCILE-AUDIT-FAIL');
        $fixture['gradebook']->update(['percentage' => 74]);
        $certificateAttributes = $fixture['certificate']->fresh()->getAttributes();
        $gradebookAttributes = $fixture['gradebook']->fresh()->getAttributes();
        DB::statement(
            "CREATE TRIGGER fail_reconciliation_audit_insert " .
            "BEFORE INSERT ON activity_logs " .
            "BEGIN SELECT RAISE(ABORT, 'simulated reconciliation audit failure'); END"
        );

        $this->authenticate($fixture['teacher_user']);

        $this->postJson('/api/certificates/' . $fixture['certificate']->id . '/reconcile')
            ->assertStatus(503)
            ->assertJsonPath(
                'message',
                'Certificate reconciliation could not be completed.'
            )
            ->assertJsonMissingPath('data');

        $this->assertSame($certificateAttributes, $fixture['certificate']->fresh()->getAttributes());
        $this->assertSame($gradebookAttributes, $fixture['gradebook']->fresh()->getAttributes());
        $this->assertSame(0, ActivityLog::where('action', 'snapshot_reconciled')->count());
    }

    public function test_certificate_reconciliation_is_deferred_for_pending_manual_grading_and_unissued_certificates(): void
    {
        $fixture = $this->createCertificateReconciliationFixture('RECONCILE-PENDING');
        $answer = QuizAnswer::create([
            'quiz_attempt_id' => $fixture['attempt']->id,
            'question_bank_id' => $fixture['manual_questions']['long_answer']->id,
            'answer_text' => 'Waiting for manual grading.',
            'is_correct' => false,
            'marks_obtained' => 0,
            'manually_graded_at' => null,
        ]);
        $certificateAttributes = $fixture['certificate']->fresh()->getAttributes();
        $gradebookAttributes = $fixture['gradebook']->fresh()->getAttributes();

        $this->authenticate($fixture['teacher_user']);

        $this->postJson('/api/certificates/' . $fixture['certificate']->id . '/reconcile')
            ->assertUnprocessable()
            ->assertJsonPath('grading_pending', true)
            ->assertJsonMissingPath('data');

        $this->assertSame($certificateAttributes, $fixture['certificate']->fresh()->getAttributes());
        $this->assertSame($gradebookAttributes, $fixture['gradebook']->fresh()->getAttributes());
        $this->assertSame(0, ActivityLog::where('action', 'snapshot_reconciled')->count());

        $answer->delete();
        $fixture['certificate']->update(['status' => 'pending']);
        $pendingCertificateAttributes = $fixture['certificate']->fresh()->getAttributes();

        $this->postJson('/api/certificates/' . $fixture['certificate']->id . '/reconcile')
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Certificate cannot be reconciled.')
            ->assertJsonMissingPath('data');

        $this->assertSame(
            $pendingCertificateAttributes,
            $fixture['certificate']->fresh()->getAttributes()
        );
        $this->assertSame(0, ActivityLog::where('action', 'snapshot_reconciled')->count());
    }

    public function test_certificate_reconciliation_rejects_inconsistent_relationships_without_disclosure(): void
    {
        $courseInstitution = $this->createInstitution('RECONCILE-INCONSISTENT-COURSE');
        $studentInstitution = $this->createInstitution('RECONCILE-INCONSISTENT-STUDENT');
        [, $teacher] = $this->createTeacher($courseInstitution);
        [, $student] = $this->createStudent($studentInstitution);
        $course = $this->createCourse(
            $courseInstitution,
            $teacher,
            'certificate-reconciliation-inconsistent'
        );
        $certificate = $this->createCertificate(
            $course,
            $student,
            'CERT-RECONCILE-INCONSISTENT'
        );
        $originalAttributes = $certificate->fresh()->getAttributes();
        $superAdmin = $this->createUserWithRole('super-admin');

        $this->authenticate($superAdmin);

        $this->postJson('/api/certificates/' . $certificate->id . '/reconcile')
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Certificate cannot be reconciled.')
            ->assertJsonMissingPath('data')
            ->assertJsonMissingPath('student_name')
            ->assertJsonMissingPath('course_title')
            ->assertJsonMissingPath('institution_name');

        $this->assertSame($originalAttributes, $certificate->fresh()->getAttributes());
        $this->assertSame(0, Gradebook::count());
        $this->assertSame(0, ActivityLog::where('action', 'snapshot_reconciled')->count());
    }

    public function test_certificate_reconciliation_keeps_teacher_and_super_admin_access_and_denies_learners(): void
    {
        $fixture = $this->createCertificateReconciliationFixture('RECONCILE-ROLES');

        $this->authenticate($fixture['teacher_user']);
        $this->postJson('/api/certificates/' . $fixture['certificate']->id . '/reconcile')
            ->assertOk();

        $this->authenticate($fixture['student_user']);
        $this->postJson('/api/certificates/' . $fixture['certificate']->id . '/reconcile')
            ->assertForbidden();

        $parentUser = $this->createParent(
            Institution::findOrFail($fixture['institution_id']),
            $fixture['student']
        );
        $this->authenticate($parentUser);
        $this->postJson('/api/certificates/' . $fixture['certificate']->id . '/reconcile')
            ->assertForbidden();

        $superAdmin = $this->createUserWithRole('super-admin');
        $this->authenticate($superAdmin);
        $this->postJson('/api/certificates/' . $fixture['certificate']->id . '/reconcile')
            ->assertOk();

        $this->assertDatabaseHas('activity_logs', [
            'institution_id' => $fixture['institution_id'],
            'user_id' => $superAdmin->id,
            'module' => 'Certificate',
            'action' => 'snapshot_reconciled',
        ]);
    }

    public function test_certificate_reconciliation_does_not_reactivate_revoked_or_expired_certificates(): void
    {
        foreach ([
            [
                'suffix' => 'RECONCILE-REVOKED',
                'status' => 'revoked',
                'verification_status' => 'revoked',
            ],
            [
                'suffix' => 'RECONCILE-EXPIRED',
                'status' => 'issued',
                'verification_status' => 'expired',
            ],
        ] as $case) {
            $fixture = $this->createCertificateReconciliationFixture($case['suffix']);
            $fixture['certificate']->update([
                'status' => $case['status'],
                'verification_status' => $case['verification_status'],
            ]);
            $originalAttributes = $fixture['certificate']->fresh()->getAttributes();

            $this->authenticate($fixture['teacher_user']);

            $this->postJson('/api/certificates/' . $fixture['certificate']->id . '/reconcile')
                ->assertOk()
                ->assertJsonPath('data.certificate.currently_valid', false)
                ->assertJsonPath('data.certificate.status', $case['status'])
                ->assertJsonPath(
                    'data.certificate.verification_status',
                    $case['verification_status']
                );

            $this->assertSame(
                $originalAttributes,
                $fixture['certificate']->fresh()->getAttributes()
            );

            $this->patchJson('/api/certificates/' . $fixture['certificate']->id, [
                'status' => 'issued',
                'verification_status' => 'valid',
            ])->assertStatus(409)
                ->assertJsonPath(
                    'message',
                    'Certificate reactivation requires a versioned reissue workflow.'
                );

            $this->assertSame(
                $originalAttributes,
                $fixture['certificate']->fresh()->getAttributes()
            );
        }
    }

    public function test_certificate_issuance_fails_closed_when_gradebook_recalculation_fails(): void
    {
        $fixture = $this->createQuizResultVisibilityFixture('CERT-GRADEBOOK-REFRESH-FAIL', false);
        $gradebook = $this->createPassingGradebook($fixture['quiz']->course, $fixture['student']);
        $certificate = $this->createCertificate(
            $fixture['quiz']->course,
            $fixture['student'],
            'CERT-GRADEBOOK-REFRESH-FAIL-001'
        );
        $certificate->update([
            'gradebook_id' => $gradebook->id,
            'final_percentage' => 88.25,
            'final_grade' => 'A',
        ]);
        $originalAttributes = $certificate->fresh()->getAttributes();
        DB::statement(
            "CREATE TRIGGER fail_gradebook_refresh " .
            "BEFORE UPDATE ON gradebooks " .
            "BEGIN SELECT RAISE(ABORT, 'simulated gradebook refresh failure'); END"
        );
        Storage::fake('certificates');
        Pdf::shouldReceive('loadView')->never();

        $this->authenticate($fixture['teacher_user']);
        $this->postJson('/api/certificates', [
            'course_id' => $fixture['quiz']->course_id,
            'student_profile_id' => $fixture['student']->id,
        ])->assertStatus(503)
            ->assertJsonPath(
                'message',
                'Certificate cannot be issued because current grades could not be established.'
            );

        $this->assertSame($originalAttributes, $certificate->fresh()->getAttributes());
        $this->assertSame([], Storage::disk('certificates')->allFiles());
    }

    public function test_pending_certificate_results_are_redacted_from_student_parent_and_dashboard_responses_but_visible_to_staff(): void
    {
        $fixture = $this->createQuizResultVisibilityFixture('CERT-PENDING-READ', false);
        $attempt = QuizAttempt::create([
            'quiz_id' => $fixture['quiz']->id,
            'student_profile_id' => $fixture['student']->id,
            'attempt_number' => 1,
            'started_at' => now()->subMinutes(10),
            'submitted_at' => now()->subMinutes(5),
            'evaluated_at' => now()->subMinutes(4),
            'total_marks' => 16,
            'marks_obtained' => 12,
            'percentage' => 75,
            'result_status' => 'passed',
            'status' => 'evaluated',
        ]);
        $answer = QuizAnswer::create([
            'quiz_attempt_id' => $attempt->id,
            'question_bank_id' => $fixture['manual_questions']['long_answer']->id,
            'answer_text' => 'Legacy answer awaiting manual grading.',
            'is_correct' => true,
            'marks_obtained' => 5,
            'manually_graded_at' => null,
        ]);
        $gradebook = Gradebook::create([
            'course_id' => $fixture['quiz']->course_id,
            'student_profile_id' => $fixture['student']->id,
            'assignment_marks' => 80,
            'quiz_marks' => 75,
            'total_marks' => 155,
            'maximum_marks' => 200,
            'percentage' => 77.5,
            'grade' => 'B',
            'result_status' => 'passed',
        ]);
        $certificate = $this->createCertificate(
            $fixture['quiz']->course,
            $fixture['student'],
            'CERT-PENDING-READ-001'
        );
        $certificate->update(['gradebook_id' => $gradebook->id]);
        $parentUser = $this->createParent(
            Institution::findOrFail($fixture['student']->institution_id),
            $fixture['student']
        );
        $originalCertificate = $certificate->fresh()->getAttributes();

        $this->authenticate($fixture['student_user']);
        $studentList = $this->getJson('/api/my-certificates')
            ->assertOk()
            ->json('data.data.0');
        $this->assertPendingCertificatePayload($studentList);
        $studentDetail = $this->getJson('/api/certificates/' . $certificate->id)
            ->assertOk()
            ->json('data');
        $this->assertPendingCertificatePayload($studentDetail);
        $studentDashboard = $this->getJson('/api/student-dashboard/' . $fixture['student']->id)
            ->assertOk()
            ->json('data');
        $this->assertPendingCertificatePayload($studentDashboard['certificates'][0]);
        $this->assertPendingCertificatePayload(
            $studentDashboard['student']['certificates'][0]
        );

        $this->authenticate($parentUser);
        $parentList = $this->getJson('/api/certificates')
            ->assertOk()
            ->json('data.data.0');
        $this->assertPendingCertificatePayload($parentList);
        $parentDetail = $this->getJson('/api/certificates/' . $certificate->id)
            ->assertOk()
            ->json('data');
        $this->assertPendingCertificatePayload($parentDetail);
        $parentDashboard = $this->getJson('/api/parent-dashboard/' . $fixture['student']->id)
            ->assertOk()
            ->json('data');
        $this->assertPendingCertificatePayload($parentDashboard['certificates'][0]);

        $this->authenticate($fixture['teacher_user']);
        $staffList = $this->getJson('/api/certificates')
            ->assertOk()
            ->json('data.data.0');
        $this->assertSame('80.00', $staffList['final_percentage']);
        $this->assertSame('B', $staffList['final_grade']);
        $this->getJson('/api/certificates/' . $certificate->id)
            ->assertOk()
            ->assertJsonPath('data.final_percentage', '80.00')
            ->assertJsonPath('data.final_grade', 'B')
            ->assertJsonPath('data.gradebook.percentage', '77.50')
            ->assertJsonPath('data.gradebook.result_status', 'passed');

        $this->assertSame($originalCertificate, $certificate->fresh()->getAttributes());
        $this->assertNull($answer->fresh()->manually_graded_at);
    }

    public function test_public_certificate_verification_suppresses_pending_results_and_restores_completed_result_behavior(): void
    {
        $fixture = $this->createQuizResultVisibilityFixture('CERT-PENDING-VERIFY', false);
        $attempt = QuizAttempt::create([
            'quiz_id' => $fixture['quiz']->id,
            'student_profile_id' => $fixture['student']->id,
            'attempt_number' => 1,
            'started_at' => now()->subMinutes(10),
            'submitted_at' => now()->subMinutes(5),
            'evaluated_at' => now()->subMinutes(4),
            'total_marks' => 16,
            'marks_obtained' => 12,
            'percentage' => 75,
            'result_status' => 'passed',
            'status' => 'evaluated',
        ]);
        $answer = QuizAnswer::create([
            'quiz_attempt_id' => $attempt->id,
            'question_bank_id' => $fixture['manual_questions']['fill_blank']->id,
            'answer_text' => 'Pending legacy answer.',
            'is_correct' => true,
            'marks_obtained' => 5,
            'manually_graded_at' => null,
        ]);
        $certificate = $this->createCertificate(
            $fixture['quiz']->course,
            $fixture['student'],
            'CERT-PENDING-VERIFY-001'
        );
        $originalCertificate = $certificate->fresh()->getAttributes();

        $this->getJson('/api/verify-certificate/' . $certificate->verification_token)
            ->assertUnprocessable()
            ->assertJsonPath('valid', false)
            ->assertJsonPath('message', 'Certificate is not valid.')
            ->assertJsonMissingPath('status')
            ->assertJsonMissingPath('grading_pending')
            ->assertJsonMissingPath('data.final_percentage')
            ->assertJsonMissingPath('data.final_grade')
            ->assertJsonMissingPath('data.certificate_file')
            ->assertJsonMissingPath('data.certificate_file_url');

        $this->assertSame($originalCertificate, $certificate->fresh()->getAttributes());
        $this->assertNull($answer->fresh()->manually_graded_at);

        $answer->update(['manually_graded_at' => now()]);

        $this->getJson('/api/verify-certificate/' . $certificate->verification_token)
            ->assertOk()
            ->assertJsonPath('valid', true)
            ->assertJsonPath('data.final_percentage', '80.00')
            ->assertJsonPath('data.final_grade', 'B');

        $this->authenticate($fixture['student_user']);
        $this->getJson('/api/my-certificates')
            ->assertOk()
            ->assertJsonPath('data.data.0.final_percentage', '80.00')
            ->assertJsonPath('data.data.0.final_grade', 'B')
            ->assertJsonPath('data.data.0.grading_pending', false)
            ->assertJsonMissingPath('data.data.0.certificate_file')
            ->assertJsonMissingPath('data.data.0.certificate_file_url');
    }

    public function test_new_certificate_pdfs_use_private_storage_and_responses_hide_internal_paths(): void
    {
        $fixture = $this->createQuizResultVisibilityFixture('CERT-PRIVATE-ISSUE', false);
        $this->createPassingGradebook($fixture['quiz']->course, $fixture['student']);
        $this->createPassingQuizAttempt($fixture['quiz'], $fixture['student']);
        $this->createCertificateSetting(
            Institution::findOrFail($fixture['student']->institution_id)
        );

        $certificateRoot = config('filesystems.disks.certificates.root');
        $publicRoot = config('filesystems.disks.public.root');
        $this->assertSame(storage_path('app/private/certificates'), $certificateRoot);
        $this->assertSame(storage_path('app/public'), $publicRoot);
        $this->assertFalse(Str::startsWith($certificateRoot, public_path()));
        $this->assertSame(
            storage_path('app/public'),
            config('filesystems.links')[public_path('storage')]
        );

        Storage::fake('certificates');
        Storage::fake('public');
        $this->authenticate($fixture['teacher_user']);

        $this->postJson('/api/certificates', [
            'course_id' => $fixture['quiz']->course_id,
            'student_profile_id' => $fixture['student']->id,
        ])->assertCreated()
            ->assertJsonMissingPath('data.certificate_file')
            ->assertJsonMissingPath('data.certificate_file_url')
            ->assertJsonPath('data.status', 'issued');

        $certificate = Certificate::where('course_id', $fixture['quiz']->course_id)
            ->where('student_profile_id', $fixture['student']->id)
            ->firstOrFail();
        $this->assertNotEmpty($certificate->certificate_file);
        $this->assertStringNotContainsString('/storage/', $certificate->certificate_file);
        Storage::disk('certificates')->assertExists($certificate->certificate_file);
        Storage::disk('public')->assertMissing($certificate->certificate_file);
        $this->assertFileDoesNotExist(
            public_path('storage/' . $certificate->certificate_file)
        );

        $this->getJson('/api/certificates/' . $certificate->id)
            ->assertOk()
            ->assertJsonMissingPath('data.certificate_file')
            ->assertJsonMissingPath('data.certificate_file_url')
            ->assertJsonPath('data.public_verification_url', $certificate->public_verification_url);

        $this->authenticate($fixture['student_user']);
        $this->getJson('/api/my-certificates')
            ->assertOk()
            ->assertJsonMissingPath('data.data.0.certificate_file')
            ->assertJsonMissingPath('data.data.0.certificate_file_url')
            ->assertJsonPath('data.data.0.public_verification_url', $certificate->public_verification_url);

        $this->getJson('/api/verify-certificate/' . $certificate->verification_token)
            ->assertOk()
            ->assertJsonMissingPath('data.certificate_file')
            ->assertJsonMissingPath('data.certificate_file_url')
            ->assertJsonMissingPath('data.public_verification_url');
    }

    public function test_authorized_users_download_private_certificate_pdfs_without_changing_snapshots(): void
    {
        $institution = $this->createInstitution('CERT-PRIVATE-DOWNLOAD');
        [$teacherUser, $teacher] = $this->createTeacher($institution);
        [$studentUser, $student] = $this->createStudent($institution);
        $course = $this->createCourse($institution, $teacher, 'certificate-private-download');
        $this->createEnrollment($course, $student);
        $certificate = $this->createCertificate($course, $student, 'CERT-PRIVATE-DOWNLOAD-001');
        $certificate->update([
            'final_percentage' => 73.25,
            'final_grade' => 'C',
            'certificate_file' => 'existing-private-certificate.pdf',
        ]);
        $originalSnapshot = $this->certificateSnapshot($certificate);
        $parentUser = $this->createParent($institution, $student);
        $institutionAdmin = $this->createInstitutionAdmin($institution);
        $contents = '%PDF-1.4 private certificate fixture';

        Storage::fake('certificates');
        Storage::disk('certificates')->put('existing-private-certificate.pdf', $contents);

        foreach ([$studentUser, $parentUser, $teacherUser, $institutionAdmin] as $user) {
            $this->authenticate($user);

            $response = $this->get('/api/certificates/' . $certificate->id . '/download')
                ->assertDownload('existing-private-certificate.pdf');

            $this->assertSame($contents, $response->streamedContent());
            $this->assertSame($originalSnapshot, $this->certificateSnapshot($certificate->fresh()));
        }
    }

    public function test_download_rejects_pending_revoked_and_expired_certificates(): void
    {
        $institution = $this->createInstitution('CERT-STATUS-DOWNLOAD');
        [$teacherUser, $teacher] = $this->createTeacher($institution);
        [$studentUser, $student] = $this->createStudent($institution);

        $validCourse = $this->createCourse($institution, $teacher, 'certificate-status-valid');
        $this->createEnrollment($validCourse, $student);
        $validCertificate = $this->createCertificate(
            $validCourse,
            $student,
            'CERT-STATUS-DOWNLOAD-VALID'
        );
        $validCertificate->update(['certificate_file' => 'valid-status.pdf']);

        $nonValidCertificates = [];
        foreach ([
            ['name' => 'pending', 'status' => 'pending', 'verification_status' => 'valid'],
            ['name' => 'revoked', 'status' => 'revoked', 'verification_status' => 'revoked'],
            ['name' => 'expired', 'status' => 'issued', 'verification_status' => 'expired'],
        ] as $case) {
            $course = $this->createCourse(
                $institution,
                $teacher,
                'certificate-status-' . $case['name']
            );
            $this->createEnrollment($course, $student);
            $certificate = $this->createCertificate(
                $course,
                $student,
                'CERT-STATUS-DOWNLOAD-' . strtoupper($case['name'])
            );
            $path = $case['name'] . '-status.pdf';
            $certificate->update([
                'status' => $case['status'],
                'verification_status' => $case['verification_status'],
                'certificate_file' => $path,
            ]);
            $nonValidCertificates[] = [$certificate, $path];
        }

        Storage::fake('certificates');
        foreach (array_merge([[$validCertificate, 'valid-status.pdf']], $nonValidCertificates) as [, $path]) {
            Storage::disk('certificates')->put($path, '%PDF-1.4 status fixture');
        }

        $this->authenticate($studentUser);
        $this->get('/api/certificates/' . $validCertificate->id . '/download')
            ->assertDownload('valid-status.pdf');

        foreach ($nonValidCertificates as [$certificate]) {
            $this->getJson('/api/certificates/' . $certificate->id . '/download')
                ->assertUnprocessable()
                ->assertJsonPath('message', 'Certificate is not available for download.')
                ->assertJsonMissingPath('status');
        }
    }

    public function test_public_verification_rejects_revoked_expired_and_pending_certificates_without_status_details(): void
    {
        $institution = $this->createInstitution('CERT-STATUS-VERIFY');
        [, $teacher] = $this->createTeacher($institution);
        [, $student] = $this->createStudent($institution);

        $validCourse = $this->createCourse($institution, $teacher, 'certificate-verify-valid');
        $validCertificate = $this->createCertificate(
            $validCourse,
            $student,
            'CERT-STATUS-VERIFY-VALID'
        );

        $nonValidCertificates = [];
        foreach ([
            ['name' => 'revoked', 'status' => 'revoked', 'verification_status' => 'revoked'],
            ['name' => 'expired', 'status' => 'issued', 'verification_status' => 'expired'],
            ['name' => 'pending', 'status' => 'pending', 'verification_status' => 'valid'],
        ] as $case) {
            $course = $this->createCourse(
                $institution,
                $teacher,
                'certificate-verify-' . $case['name']
            );
            $certificate = $this->createCertificate(
                $course,
                $student,
                'CERT-STATUS-VERIFY-' . strtoupper($case['name'])
            );
            $certificate->update([
                'status' => $case['status'],
                'verification_status' => $case['verification_status'],
            ]);
            $nonValidCertificates[] = $certificate;
        }

        $this->getJson('/api/verify-certificate/' . $validCertificate->verification_token)
            ->assertOk()
            ->assertJsonPath('valid', true);

        foreach ($nonValidCertificates as $certificate) {
            $this->getJson('/api/verify-certificate/' . $certificate->verification_token)
                ->assertUnprocessable()
                ->assertJsonPath('valid', false)
                ->assertJsonPath('message', 'Certificate is not valid.')
                ->assertJsonMissingPath('status')
                ->assertJsonMissingPath('data');
        }
    }

    public function test_public_verification_rejects_cross_institution_certificate_without_disclosing_metadata(): void
    {
        $courseInstitution = $this->createInstitution('CERT-VERIFY-CONSISTENCY-COURSE');
        $studentInstitution = $this->createInstitution('CERT-VERIFY-CONSISTENCY-STUDENT');
        [, $teacher] = $this->createTeacher($courseInstitution);
        [, $student] = $this->createStudent($studentInstitution);
        $course = $this->createCourse(
            $courseInstitution,
            $teacher,
            'certificate-verify-cross-institution'
        );
        $certificate = $this->createCertificate(
            $course,
            $student,
            'CERT-VERIFY-CROSS-INSTITUTION-001'
        );
        $certificate->update([
            'final_percentage' => 98.75,
            'final_grade' => 'PRIVATE-GRADE-SENTINEL',
        ]);

        $this->getJson('/api/verify-certificate/' . $certificate->verification_token)
            ->assertUnprocessable()
            ->assertExactJson([
                'message' => 'Certificate is not valid.',
                'valid' => false,
            ]);

        $this->assertDatabaseHas('certificates', [
            'id' => $certificate->id,
            'status' => 'issued',
            'verification_status' => 'valid',
            'final_percentage' => 98.75,
            'final_grade' => 'PRIVATE-GRADE-SENTINEL',
        ]);
    }

    public function test_public_verification_rejects_certificate_with_soft_deleted_course_without_metadata(): void
    {
        $institution = $this->createInstitution('CERT-VERIFY-MISSING-COURSE');
        [, $teacher] = $this->createTeacher($institution);
        [, $student] = $this->createStudent($institution);
        $course = $this->createCourse($institution, $teacher, 'certificate-verify-deleted-course');
        $certificate = $this->createCertificate(
            $course,
            $student,
            'CERT-VERIFY-DELETED-COURSE-001'
        );
        $course->delete();

        $this->getJson('/api/verify-certificate/' . $certificate->verification_token)
            ->assertUnprocessable()
            ->assertExactJson([
                'message' => 'Certificate is not valid.',
                'valid' => false,
            ]);

        $this->assertDatabaseHas('certificates', [
            'id' => $certificate->id,
            'status' => 'issued',
            'verification_status' => 'valid',
        ]);
        $this->assertSoftDeleted('courses', ['id' => $course->id]);
    }

    public function test_generation_of_expired_certificate_is_denied_without_changing_status_or_grade_snapshot(): void
    {
        $institution = $this->createInstitution('CERT-STATUS-GENERATE');
        [$teacherUser, $teacher] = $this->createTeacher($institution);
        [, $student] = $this->createStudent($institution);
        $course = $this->createCourse($institution, $teacher, 'certificate-status-generate');
        $this->createEnrollment($course, $student);
        $certificate = $this->createCertificate(
            $course,
            $student,
            'CERT-STATUS-GENERATE-EXPIRED'
        );
        $certificate->update([
            'final_percentage' => 73.25,
            'final_grade' => 'C',
            'verification_status' => 'expired',
        ]);
        $originalSnapshot = $this->certificateSnapshot($certificate);

        Storage::fake('certificates');

        $this->authenticate($teacherUser);
        $this->postJson('/api/certificates/' . $certificate->id . '/generate')
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Certificate is not available for PDF generation.')
            ->assertJsonMissingPath('status');

        $generatedCertificate = $certificate->fresh();
        $this->assertSame('issued', $generatedCertificate->status);
        $this->assertSame('expired', $generatedCertificate->verification_status);
        $this->assertSame($originalSnapshot, $this->certificateSnapshot($generatedCertificate));
        $this->assertNull($generatedCertificate->certificate_file);
        $this->assertSame([], Storage::disk('certificates')->allFiles());
    }

    public function test_legacy_public_pdf_download_regenerates_ephemerally_without_rewriting_the_record_or_old_file(): void
    {
        $institution = $this->createInstitution('CERT-LEGACY-PUBLIC');
        [, $teacher] = $this->createTeacher($institution);
        [$studentUser, $student] = $this->createStudent($institution);
        $course = $this->createCourse($institution, $teacher, 'certificate-legacy-public');
        $this->createEnrollment($course, $student);
        $certificate = $this->createCertificate($course, $student, 'CERT-LEGACY-001');
        $this->createCertificateSetting($institution);
        $legacyPath = 'certificates/certificate-CERT-LEGACY-001.pdf';
        $legacyContents = 'unchanged public certificate bytes';
        $certificate->update(['certificate_file' => $legacyPath]);
        $originalAttributes = $certificate->fresh()->getAttributes();

        Storage::fake('certificates');
        Storage::fake('public');
        Storage::disk('public')->put($legacyPath, $legacyContents);
        $this->authenticate($studentUser);

        $response = $this->get('/api/certificates/' . $certificate->id . '/download')
            ->assertDownload('certificate-cert-legacy-001.pdf');

        $this->assertStringStartsWith('%PDF', $response->getContent());
        $this->assertSame($originalAttributes, $certificate->fresh()->getAttributes());
        Storage::disk('public')->assertExists($legacyPath);
        $this->assertSame($legacyContents, Storage::disk('public')->get($legacyPath));
        $this->assertSame([], Storage::disk('certificates')->allFiles());
    }

    public function test_failed_certificate_render_does_not_leave_a_successful_issuance(): void
    {
        $fixture = $this->createQuizResultVisibilityFixture('CERT-PRIVATE-RENDER-FAIL', false);
        $this->createPassingGradebook($fixture['quiz']->course, $fixture['student']);
        $this->createPassingQuizAttempt($fixture['quiz'], $fixture['student']);
        $this->createCertificateSetting(
            Institution::findOrFail($fixture['student']->institution_id)
        );
        Storage::fake('certificates');
        Pdf::shouldReceive('loadView')
            ->once()
            ->andThrow(new \RuntimeException('Simulated PDF render failure.'));
        $this->authenticate($fixture['teacher_user']);

        $this->postJson('/api/certificates', [
            'course_id' => $fixture['quiz']->course_id,
            'student_profile_id' => $fixture['student']->id,
        ])->assertServerError()
            ->assertJsonPath('message', 'Certificate PDF generation failed.');

        $this->assertDatabaseMissing('certificates', [
            'course_id' => $fixture['quiz']->course_id,
            'student_profile_id' => $fixture['student']->id,
        ]);
        $this->assertSame([], Storage::disk('certificates')->allFiles());
    }

    public function test_failed_certificate_storage_does_not_leave_a_successful_issuance(): void
    {
        $fixture = $this->createQuizResultVisibilityFixture('CERT-PRIVATE-STORAGE-FAIL', false);
        $this->createPassingGradebook($fixture['quiz']->course, $fixture['student']);
        $this->createPassingQuizAttempt($fixture['quiz'], $fixture['student']);
        $this->createCertificateSetting(
            Institution::findOrFail($fixture['student']->institution_id)
        );
        $pdf = \Mockery::mock(\Barryvdh\DomPDF\PDF::class);
        $pdf->shouldReceive('setPaper')->once()->with('a4', 'landscape')->andReturnSelf();
        $pdf->shouldReceive('output')->once()->andReturn('%PDF-1.4 fixture');
        Pdf::shouldReceive('loadView')->once()->andReturn($pdf);

        Storage::fake('public');
        $publicDisk = Storage::disk('public');
        $disk = \Mockery::mock();
        $disk->shouldReceive('put')->once()->andReturn(false);
        $disk->shouldReceive('exists')->atLeast()->once()->andReturn(false);
        Storage::shouldReceive('disk')
            ->with('certificates')
            ->atLeast()->once()
            ->andReturn($disk);
        Storage::shouldReceive('disk')
            ->with('public')
            ->atLeast()->once()
            ->andReturn($publicDisk);
        $this->authenticate($fixture['teacher_user']);

        $this->postJson('/api/certificates', [
            'course_id' => $fixture['quiz']->course_id,
            'student_profile_id' => $fixture['student']->id,
        ])->assertServerError()
            ->assertJsonPath('message', 'Certificate PDF generation failed.');

        $this->assertDatabaseMissing('certificates', [
            'course_id' => $fixture['quiz']->course_id,
            'student_profile_id' => $fixture['student']->id,
        ]);
    }

    public function test_certificate_path_persistence_failure_rolls_back_issuance_and_cleans_new_pdf(): void
    {
        $fixture = $this->createQuizResultVisibilityFixture('CERT-PRIVATE-DB-FAIL', false);
        $this->createPassingGradebook($fixture['quiz']->course, $fixture['student']);
        $this->createPassingQuizAttempt($fixture['quiz'], $fixture['student']);
        $this->createCertificateSetting(
            Institution::findOrFail($fixture['student']->institution_id)
        );

        Storage::fake('certificates');
        Storage::fake('public');
        $this->failCertificateFilePathPersistence();

        $pdf = \Mockery::mock(\Barryvdh\DomPDF\PDF::class);
        $pdf->shouldReceive('setPaper')->once()->with('a4', 'landscape')->andReturnSelf();
        $pdf->shouldReceive('output')->once()->andReturn('%PDF-1.4 transaction fixture');
        Pdf::shouldReceive('loadView')->once()->andReturn($pdf);

        $this->authenticate($fixture['teacher_user']);
        $this->postJson('/api/certificates', [
            'course_id' => $fixture['quiz']->course_id,
            'student_profile_id' => $fixture['student']->id,
        ])->assertServerError()
            ->assertJsonPath('message', 'Certificate PDF generation failed.');

        $this->assertDatabaseMissing('certificates', [
            'course_id' => $fixture['quiz']->course_id,
            'student_profile_id' => $fixture['student']->id,
        ]);
        $this->assertSame([], Storage::disk('certificates')->allFiles());
    }

    public function test_certificate_regeneration_associates_new_pdf_before_deleting_old_pdf(): void
    {
        $institution = $this->createInstitution('CERT-REPLACE-ORDER');
        [$teacherUser, $teacher] = $this->createTeacher($institution);
        [, $student] = $this->createStudent($institution);
        $course = $this->createCourse($institution, $teacher, 'certificate-replace-order');
        $this->createEnrollment($course, $student);
        $certificate = $this->createCertificate($course, $student, 'CERT-REPLACE-ORDER-001');
        $oldPath = 'old-private-certificate.pdf';
        $certificate->update([
            'final_percentage' => 73.25,
            'final_grade' => 'C',
            'certificate_file' => $oldPath,
        ]);
        $originalSnapshot = $this->certificateSnapshot($certificate);

        Storage::fake('certificates');
        Storage::fake('public');
        $disk = Storage::disk('certificates');
        $publicDisk = Storage::disk('public');
        $disk->put($oldPath, '%PDF-1.4 old private certificate');
        $newFileWasAssociatedBeforeOldDelete = false;
        $diskSpy = \Mockery::mock($disk)->makePartial();
        $diskSpy->shouldReceive('delete')
            ->once()
            ->with($oldPath)
            ->andReturnUsing(function (string $path) use (
                $disk,
                $certificate,
                $oldPath,
                &$newFileWasAssociatedBeforeOldDelete
            ): bool {
                $newPath = $certificate->fresh()->certificate_file;
                $newFileWasAssociatedBeforeOldDelete =
                    $newPath !== $oldPath &&
                    $disk->exists($newPath) &&
                    $disk->exists($oldPath);

                return $disk->delete($path);
            });
        Storage::shouldReceive('disk')->with('certificates')->atLeast()->once()->andReturn($diskSpy);
        Storage::shouldReceive('disk')->with('public')->atLeast()->once()->andReturn($publicDisk);

        $pdf = \Mockery::mock(\Barryvdh\DomPDF\PDF::class);
        $pdf->shouldReceive('setPaper')->once()->with('a4', 'landscape')->andReturnSelf();
        $pdf->shouldReceive('output')->once()->andReturn('%PDF-1.4 replacement certificate');
        Pdf::shouldReceive('loadView')->once()->andReturn($pdf);

        $this->authenticate($teacherUser);
        $this->postJson('/api/certificates/' . $certificate->id . '/generate')->assertOk();

        $replacement = $certificate->fresh();
        $this->assertTrue($newFileWasAssociatedBeforeOldDelete);
        $this->assertNotSame($oldPath, $replacement->certificate_file);
        $this->assertTrue($disk->exists($replacement->certificate_file));
        $this->assertFalse($disk->exists($oldPath));
        $this->assertSame($originalSnapshot, $this->certificateSnapshot($replacement));
    }

    public function test_failed_certificate_replacement_preserves_old_pdf_and_reference(): void
    {
        $institution = $this->createInstitution('CERT-REPLACE-FAIL');
        [$teacherUser, $teacher] = $this->createTeacher($institution);
        [, $student] = $this->createStudent($institution);
        $course = $this->createCourse($institution, $teacher, 'certificate-replace-fail');
        $this->createEnrollment($course, $student);
        $certificate = $this->createCertificate($course, $student, 'CERT-REPLACE-FAIL-001');
        $oldPath = 'usable-old-certificate.pdf';
        $certificate->update(['certificate_file' => $oldPath]);
        $originalSnapshot = $this->certificateSnapshot($certificate);

        Storage::fake('certificates');
        Storage::fake('public');
        Storage::disk('certificates')->put($oldPath, '%PDF-1.4 old usable certificate');
        $this->failCertificateFilePathPersistence();

        $pdf = \Mockery::mock(\Barryvdh\DomPDF\PDF::class);
        $pdf->shouldReceive('setPaper')->once()->with('a4', 'landscape')->andReturnSelf();
        $pdf->shouldReceive('output')->once()->andReturn('%PDF-1.4 replacement certificate');
        Pdf::shouldReceive('loadView')->once()->andReturn($pdf);

        $this->authenticate($teacherUser);
        $this->postJson('/api/certificates/' . $certificate->id . '/generate')
            ->assertServerError()
            ->assertJsonPath('message', 'Certificate PDF generation failed.')
            ->assertJsonMissing(['file_path' => $oldPath]);

        $freshCertificate = $certificate->fresh();
        $this->assertSame($oldPath, $freshCertificate->certificate_file);
        $this->assertSame($originalSnapshot, $this->certificateSnapshot($freshCertificate));
        Storage::disk('certificates')->assertExists($oldPath);
        $this->assertSame([$oldPath], Storage::disk('certificates')->allFiles());
    }

    public function test_duplicate_certificate_issuance_preserves_the_historical_snapshot_and_pdf(): void
    {
        $fixture = $this->createQuizResultVisibilityFixture('CERT-REISSUE-REPLACE', false);
        $gradebook = $this->createPassingGradebook($fixture['quiz']->course, $fixture['student']);
        $this->createPassingQuizAttempt($fixture['quiz'], $fixture['student']);
        $certificate = $this->createCertificate(
            $fixture['quiz']->course,
            $fixture['student'],
            'CERT-REISSUE-REPLACE-001'
        );
        $certificate->update(['gradebook_id' => $gradebook->id]);
        $oldPath = 'reissued-old-private-certificate.pdf';
        $certificate->update(['certificate_file' => $oldPath]);

        Storage::fake('certificates');
        Storage::fake('public');
        Storage::disk('certificates')->put($oldPath, '%PDF-1.4 old reissued certificate');
        $originalAttributes = $certificate->fresh()->getAttributes();
        Pdf::shouldReceive('loadView')->never();

        $this->authenticate($fixture['teacher_user']);
        $this->postJson('/api/certificates', [
            'course_id' => $fixture['quiz']->course_id,
            'student_profile_id' => $fixture['student']->id,
        ])->assertStatus(409)
            ->assertJsonPath(
                'message',
                'A certificate already exists for this student and course.'
            );

        $preservedCertificate = $certificate->fresh();
        $this->assertSame($originalAttributes, $preservedCertificate->getAttributes());
        $this->assertSame($oldPath, $preservedCertificate->certificate_file);
        $this->assertSame('75.00', $gradebook->fresh()->percentage);
        $this->assertSame('B+', $gradebook->fresh()->grade);
        $this->assertSame('passed', $gradebook->fresh()->result_status);
        Storage::disk('certificates')->assertExists($oldPath);
        $this->assertSame([$oldPath], Storage::disk('certificates')->allFiles());
    }

    public function test_certificate_cleanup_failure_is_logged_and_old_file_is_not_reported_as_removed(): void
    {
        $institution = $this->createInstitution('CERT-CLEANUP-FAIL');
        [$teacherUser, $teacher] = $this->createTeacher($institution);
        [, $student] = $this->createStudent($institution);
        $course = $this->createCourse($institution, $teacher, 'certificate-cleanup-fail');
        $this->createEnrollment($course, $student);
        $certificate = $this->createCertificate($course, $student, 'CERT-CLEANUP-FAIL-001');
        $oldPath = 'old-pdf-that-cannot-be-deleted.pdf';
        $certificate->update(['certificate_file' => $oldPath]);

        Storage::fake('certificates');
        Storage::fake('public');
        $disk = Storage::disk('certificates');
        $publicDisk = Storage::disk('public');
        $disk->put($oldPath, '%PDF-1.4 retained old certificate');
        $diskSpy = \Mockery::mock($disk)->makePartial();
        $diskSpy->shouldReceive('delete')->once()->with($oldPath)->andReturn(false);
        Storage::shouldReceive('disk')->with('certificates')->atLeast()->once()->andReturn($diskSpy);
        Storage::shouldReceive('disk')->with('public')->atLeast()->once()->andReturn($publicDisk);
        Log::shouldReceive('error')
            ->once()
            ->withArgs(function (string $message, array $context) use ($oldPath, $certificate): bool {
                return $message === 'Private certificate PDF cleanup failed.' &&
                    $context['certificate_id'] === $certificate->id &&
                    $context['file_path'] === $oldPath &&
                    $context['delete_returned'] === false &&
                    $context['file_still_exists'] === true;
            });

        $pdf = \Mockery::mock(\Barryvdh\DomPDF\PDF::class);
        $pdf->shouldReceive('setPaper')->once()->with('a4', 'landscape')->andReturnSelf();
        $pdf->shouldReceive('output')->once()->andReturn('%PDF-1.4 replacement certificate');
        Pdf::shouldReceive('loadView')->once()->andReturn($pdf);

        $this->authenticate($teacherUser);
        $response = $this->postJson('/api/certificates/' . $certificate->id . '/generate')
            ->assertOk();

        $newPath = $certificate->fresh()->certificate_file;
        $this->assertNotSame($oldPath, $newPath);
        $this->assertTrue($disk->exists($newPath));
        $this->assertTrue($disk->exists($oldPath));
        $this->assertStringNotContainsString('removed', strtolower($response->getContent()));
    }

    public function test_certificate_delete_removes_only_unreferenced_private_pdf_and_preserves_public_pdf(): void
    {
        $institution = $this->createInstitution('CERT-DELETE-LIFECYCLE');
        [$teacherUser, $teacher] = $this->createTeacher($institution);
        [, $firstStudent] = $this->createStudent($institution);
        [, $secondStudent] = $this->createStudent($institution);
        [, $thirdStudent] = $this->createStudent($institution);
        $firstCourse = $this->createCourse($institution, $teacher, 'certificate-delete-first');
        $secondCourse = $this->createCourse($institution, $teacher, 'certificate-delete-second');
        $thirdCourse = $this->createCourse($institution, $teacher, 'certificate-delete-third');
        $this->createEnrollment($firstCourse, $firstStudent);
        $this->createEnrollment($secondCourse, $secondStudent);
        $this->createEnrollment($thirdCourse, $thirdStudent);
        $firstCertificate = $this->createCertificate($firstCourse, $firstStudent, 'CERT-DELETE-001');
        $secondCertificate = $this->createCertificate($secondCourse, $secondStudent, 'CERT-DELETE-002');
        $thirdCertificate = $this->createCertificate($thirdCourse, $thirdStudent, 'CERT-DELETE-003');
        $sharedPath = 'shared-certificate.pdf';
        $privatePath = 'intended-private-certificate.pdf';
        $legacyPublicPath = 'certificates/legacy-public-certificate.pdf';
        $firstCertificate->update(['certificate_file' => $sharedPath]);
        $secondCertificate->update(['certificate_file' => $sharedPath]);
        $thirdCertificate->update(['certificate_file' => $privatePath]);

        Storage::fake('certificates');
        Storage::fake('public');
        Storage::disk('certificates')->put($sharedPath, '%PDF shared');
        Storage::disk('certificates')->put($privatePath, '%PDF private');
        Storage::disk('public')->put($legacyPublicPath, '%PDF legacy public');

        $legacyCertificate = $this->createCertificate(
            $thirdCourse,
            $this->createStudent($institution)[1],
            'CERT-DELETE-004'
        );
        $legacyCertificate->update(['certificate_file' => $legacyPublicPath]);
        $this->createEnrollment($thirdCourse, $legacyCertificate->studentProfile);

        $this->authenticate($teacherUser);
        $this->deleteJson('/api/certificates/' . $firstCertificate->id)->assertOk();
        $this->deleteJson('/api/certificates/' . $thirdCertificate->id)->assertOk();
        $this->deleteJson('/api/certificates/' . $legacyCertificate->id)->assertOk();

        $this->assertTrue(Certificate::withTrashed()->findOrFail($firstCertificate->id)->trashed());
        $this->assertTrue(Certificate::withTrashed()->findOrFail($thirdCertificate->id)->trashed());
        $this->assertTrue(Certificate::withTrashed()->findOrFail($legacyCertificate->id)->trashed());
        Storage::disk('certificates')->assertExists($sharedPath);
        Storage::disk('certificates')->assertMissing($privatePath);
        Storage::disk('public')->assertExists($legacyPublicPath);
        $this->assertFalse($secondCertificate->fresh()->trashed());
    }

    public function test_legacy_download_attempts_temporary_pdf_cleanup_after_read_failure(): void
    {
        $institution = $this->createInstitution('CERT-LEGACY-READ-FAIL');
        [, $teacher] = $this->createTeacher($institution);
        [$studentUser, $student] = $this->createStudent($institution);
        $course = $this->createCourse($institution, $teacher, 'certificate-legacy-read-fail');
        $this->createEnrollment($course, $student);
        $certificate = $this->createCertificate($course, $student, 'CERT-LEGACY-READ-FAIL-001');
        $certificate->update(['certificate_file' => null]);
        $originalAttributes = $certificate->fresh()->getAttributes();

        Storage::fake('certificates');
        Storage::fake('public');
        $disk = Storage::disk('certificates');
        $publicDisk = Storage::disk('public');
        $temporaryPath = null;
        $cleanupSawTemporaryFile = false;
        $diskSpy = \Mockery::mock($disk)->makePartial();
        $diskSpy->shouldReceive('get')
            ->once()
            ->andThrow(new \RuntimeException('Simulated temporary PDF read failure.'));
        $diskSpy->shouldReceive('delete')
            ->once()
            ->andReturnUsing(function (string $path) use (
                $disk,
                &$temporaryPath,
                &$cleanupSawTemporaryFile
            ): bool {
                $temporaryPath = $path;
                $cleanupSawTemporaryFile = $disk->exists($path);

                return $disk->delete($path);
            });
        Storage::shouldReceive('disk')->with('certificates')->atLeast()->once()->andReturn($diskSpy);
        Storage::shouldReceive('disk')->with('public')->atLeast()->once()->andReturn($publicDisk);

        $pdf = \Mockery::mock(\Barryvdh\DomPDF\PDF::class);
        $pdf->shouldReceive('setPaper')->once()->with('a4', 'landscape')->andReturnSelf();
        $pdf->shouldReceive('output')->once()->andReturn('%PDF-1.4 temporary certificate');
        Pdf::shouldReceive('loadView')->once()->andReturn($pdf);

        $this->authenticate($studentUser);
        $this->get('/api/certificates/' . $certificate->id . '/download')
            ->assertServerError()
            ->assertJsonPath('message', 'Certificate PDF is currently unavailable.');

        $this->assertNotNull($temporaryPath);
        $this->assertTrue($cleanupSawTemporaryFile);
        $this->assertSame($originalAttributes, $certificate->fresh()->getAttributes());
        $this->assertFalse($disk->exists($temporaryPath));
        $this->assertSame([], $disk->allFiles());
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
        $this->patchJson('/api/lesson-progress/' . $firstProgress->id, [
            'status' => 'completed',
        ])->assertUnprocessable();
        $this->postJson('/api/lesson-progress/' . $firstProgress->id . '/complete')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('assessment');
        $this->authenticate($adminUser);
        $this->postJson('/api/lesson-progress/' . $firstProgress->id . '/complete')
            ->assertOk()
            ->assertJsonPath('data.status', 'completed');
        $this->authenticate($firstStudentUser);
        $this->assertForbiddenResponse(
            $this->patchJson('/api/lesson-progress/' . $secondProgress->id, [
                'status' => 'completed',
            ]),
            'student updating another student progress'
        );
        $this->assertForbiddenResponse(
            $this->postJson('/api/lesson-progress/' . $secondProgress->id . '/complete'),
            'student completing another student lesson progress'
        );

        $this->authenticate($parentUser);
        $this->getJson('/api/lesson-progress/' . $firstProgress->id)->assertOk();
        $this->assertForbiddenResponse(
            $this->patchJson('/api/lesson-progress/' . $firstProgress->id, [
                'status' => 'in_progress',
            ]),
            'parent updating child progress'
        );
        $this->assertForbiddenResponse(
            $this->postJson('/api/lesson-progress/' . $firstProgress->id . '/complete'),
            'parent completing child lesson progress'
        );

        $this->authenticate($firstTeacherUser);
        $this->getJson('/api/lesson-progress')
            ->assertOk()
            ->assertJsonCount(1, 'data.data');
        $this->assertForbiddenResponse(
            $this->getJson('/api/lesson-progress/' . $secondProgress->id),
            'teacher reading another course progress'
        );

        $this->authenticate($adminUser);
        $this->getJson('/api/lesson-progress')
            ->assertOk()
            ->assertJsonCount(1, 'data.data');
        $this->assertForbiddenResponse(
            $this->getJson('/api/lesson-progress/' . $secondProgress->id),
            'institution admin reading another institution progress'
        );
    }

    public function test_publishing_and_unpublishing_lessons_recalculates_all_course_enrollments(): void
    {
        $institution = $this->createInstitution('LESSON-PUBLICATION-PROGRESS');
        [, $teacher] = $this->createTeacher($institution);
        $adminUser = $this->createInstitutionAdmin($institution);
        [, $firstStudent] = $this->createStudent($institution);
        [, $secondStudent] = $this->createStudent($institution);
        $course = $this->createCourse($institution, $teacher, 'lesson-publication-progress');
        $publishedLesson = $this->createLesson($course, 'Existing published lesson');
        $firstEnrollment = $this->createEnrollment($course, $firstStudent);
        $secondEnrollment = $this->createEnrollment($course, $secondStudent);

        $firstProgress = $this->createProgress($firstEnrollment, $publishedLesson);
        $firstProgress->update(['status' => 'completed']);
        $secondProgress = $this->createProgress($secondEnrollment, $publishedLesson);
        $secondProgress->update(['status' => 'completed']);

        $enrollmentService = app(CourseEnrollmentService::class);
        $enrollmentService->recalculateProgress($firstEnrollment);
        $enrollmentService->recalculateProgress($secondEnrollment);

        foreach ([$firstEnrollment, $secondEnrollment] as $enrollment) {
            $this->assertSame(100.0, (float) $enrollment->fresh()->progress_percentage);
            $this->assertSame('completed', $enrollment->fresh()->status);
            $this->assertNotNull($enrollment->fresh()->completed_at);
        }

        $this->authenticate($adminUser);
        $newLessonId = $this->postJson('/api/lessons', [
            'course_id' => $course->id,
            'title' => 'New published lesson',
            'status' => 'published',
        ])->assertCreated()
            ->json('data.id');

        foreach ([$firstEnrollment, $secondEnrollment] as $enrollment) {
            $this->assertSame(50.0, (float) $enrollment->fresh()->progress_percentage);
            $this->assertSame('active', $enrollment->fresh()->status);
            $this->assertNull($enrollment->fresh()->completed_at);
        }

        $this->patchJson('/api/lessons/' . $newLessonId, [
            'status' => 'draft',
        ])->assertOk();

        foreach ([$firstEnrollment, $secondEnrollment] as $enrollment) {
            $this->assertSame(100.0, (float) $enrollment->fresh()->progress_percentage);
            $this->assertSame('completed', $enrollment->fresh()->status);
            $this->assertNotNull($enrollment->fresh()->completed_at);
        }

        $this->patchJson('/api/lessons/' . $newLessonId, [
            'status' => 'published',
        ])->assertOk();

        foreach ([$firstEnrollment, $secondEnrollment] as $enrollment) {
            $this->assertSame(50.0, (float) $enrollment->fresh()->progress_percentage);
            $this->assertSame('active', $enrollment->fresh()->status);
            $this->assertNull($enrollment->fresh()->completed_at);
        }
    }

    public function test_moving_and_deleting_a_published_lesson_recalculates_affected_courses(): void
    {
        $institution = $this->createInstitution('LESSON-COURSE-MOVE-PROGRESS');
        [, $teacher] = $this->createTeacher($institution);
        $adminUser = $this->createInstitutionAdmin($institution);
        [, $sourceStudent] = $this->createStudent($institution);
        [, $targetStudent] = $this->createStudent($institution);
        $sourceCourse = $this->createCourse($institution, $teacher, 'lesson-move-source');
        $targetCourse = $this->createCourse($institution, $teacher, 'lesson-move-target');
        $sourceCompletedLesson = $this->createLesson($sourceCourse, 'Source completed lesson');
        $movingLesson = $this->createLesson($sourceCourse, 'Moving lesson');
        $targetCompletedLesson = $this->createLesson($targetCourse, 'Target completed lesson');
        $sourceEnrollment = $this->createEnrollment($sourceCourse, $sourceStudent);
        $targetEnrollment = $this->createEnrollment($targetCourse, $targetStudent);

        $sourceProgress = $this->createProgress($sourceEnrollment, $sourceCompletedLesson);
        $sourceProgress->update(['status' => 'completed']);
        $targetProgress = $this->createProgress($targetEnrollment, $targetCompletedLesson);
        $targetProgress->update(['status' => 'completed']);

        $enrollmentService = app(CourseEnrollmentService::class);
        $enrollmentService->recalculateProgress($sourceEnrollment);
        $enrollmentService->recalculateProgress($targetEnrollment);

        $this->assertSame(50.0, (float) $sourceEnrollment->fresh()->progress_percentage);
        $this->assertSame('active', $sourceEnrollment->fresh()->status);
        $this->assertNull($sourceEnrollment->fresh()->completed_at);
        $this->assertSame(100.0, (float) $targetEnrollment->fresh()->progress_percentage);
        $this->assertSame('completed', $targetEnrollment->fresh()->status);
        $this->assertNotNull($targetEnrollment->fresh()->completed_at);

        $this->authenticate($adminUser);
        $this->patchJson('/api/lessons/' . $movingLesson->id, [
            'course_id' => $targetCourse->id,
        ])->assertOk();

        $this->assertSame(100.0, (float) $sourceEnrollment->fresh()->progress_percentage);
        $this->assertSame('completed', $sourceEnrollment->fresh()->status);
        $this->assertNotNull($sourceEnrollment->fresh()->completed_at);
        $this->assertSame(50.0, (float) $targetEnrollment->fresh()->progress_percentage);
        $this->assertSame('active', $targetEnrollment->fresh()->status);
        $this->assertNull($targetEnrollment->fresh()->completed_at);

        $this->deleteJson('/api/lessons/' . $movingLesson->id)->assertOk();

        $this->assertSoftDeleted('lessons', ['id' => $movingLesson->id]);
        $this->assertSame(100.0, (float) $targetEnrollment->fresh()->progress_percentage);
        $this->assertSame('completed', $targetEnrollment->fresh()->status);
        $this->assertNotNull($targetEnrollment->fresh()->completed_at);
    }

    public function test_lesson_move_rejects_assessments_that_would_remain_in_the_source_course(): void
    {
        $institution = $this->createInstitution('LESSON-MOVE-ASSESSMENTS');
        [, $teacher] = $this->createTeacher($institution);
        $adminUser = $this->createInstitutionAdmin($institution);
        $sourceCourse = $this->createCourse($institution, $teacher, 'lesson-assessment-source');
        $targetCourse = $this->createCourse($institution, $teacher, 'lesson-assessment-target');
        $sourceSection = CourseSection::create([
            'course_id' => $sourceCourse->id,
            'title' => 'Source section',
            'sort_order' => 1,
            'status' => 'active',
        ]);
        $lesson = $this->createLesson($sourceCourse, 'Lesson with published assessments');
        $lesson->update(['course_section_id' => $sourceSection->id]);
        $quiz = Quiz::create([
            'course_id' => $sourceCourse->id,
            'lesson_id' => $lesson->id,
            'teacher_profile_id' => $teacher->id,
            'title' => 'Source lesson quiz',
            'total_marks' => 1,
            'passing_marks' => 1,
            'status' => 'published',
        ]);
        $assignment = $this->createAssignment(
            $sourceCourse,
            $teacher,
            'Source lesson assignment'
        );
        $assignment->update(['lesson_id' => $lesson->id]);

        $this->authenticate($adminUser);
        $this->patchJson('/api/lessons/' . $lesson->id, [
            'course_id' => $targetCourse->id,
        ])->assertUnprocessable();

        $this->assertDatabaseHas('lessons', [
            'id' => $lesson->id,
            'course_id' => $sourceCourse->id,
            'course_section_id' => $sourceSection->id,
        ]);
        $this->assertDatabaseHas('quizzes', [
            'id' => $quiz->id,
            'course_id' => $sourceCourse->id,
            'lesson_id' => $lesson->id,
        ]);
        $this->assertDatabaseHas('assignments', [
            'id' => $assignment->id,
            'course_id' => $sourceCourse->id,
            'lesson_id' => $lesson->id,
        ]);
    }

    public function test_moved_lesson_clears_foreign_section_and_target_assessments_still_require_evidence(): void
    {
        $institution = $this->createInstitution('LESSON-MOVE-TARGET-EVIDENCE');
        [$teacherUser, $teacher] = $this->createTeacher($institution);
        $adminUser = $this->createInstitutionAdmin($institution);
        [$studentUser, $student] = $this->createStudent($institution);
        $sourceCourse = $this->createCourse($institution, $teacher, 'lesson-section-source');
        $targetCourse = $this->createCourse($institution, $teacher, 'lesson-section-target');
        $enrollment = $this->createEnrollment($targetCourse, $student);
        $sourceSection = CourseSection::create([
            'course_id' => $sourceCourse->id,
            'title' => 'Source-only section',
            'sort_order' => 1,
            'status' => 'active',
        ]);
        $targetSection = CourseSection::create([
            'course_id' => $targetCourse->id,
            'title' => 'Target section',
            'sort_order' => 1,
            'status' => 'active',
        ]);
        $lesson = $this->createLesson($sourceCourse, 'Moved assessment lesson');
        $lesson->update(['course_section_id' => $sourceSection->id]);

        $this->authenticate($adminUser);
        $this->patchJson('/api/lessons/' . $lesson->id, [
            'course_id' => $targetCourse->id,
        ])->assertOk()
            ->assertJsonPath('data.course_id', $targetCourse->id)
            ->assertJsonPath('data.course_section_id', null)
            ->assertJsonPath('data.course_section', null);

        $quiz = Quiz::create([
            'course_id' => $targetCourse->id,
            'lesson_id' => $lesson->id,
            'teacher_profile_id' => $teacher->id,
            'title' => 'Target lesson quiz',
            'total_marks' => 1,
            'passing_marks' => 1,
            'status' => 'published',
        ]);
        $assignment = $this->createAssignment(
            $targetCourse,
            $teacher,
            'Target lesson assignment'
        );
        $assignment->update(['lesson_id' => $lesson->id]);

        // Simulate a pre-existing stale section reference and verify it is not serialized to students.
        $lesson->refresh()->update(['course_section_id' => $sourceSection->id]);
        $validLesson = $this->createLesson($targetCourse, 'Lesson with a valid section');
        $validLesson->update(['course_section_id' => $targetSection->id]);
        $this->authenticate($studentUser);
        $this->getJson('/api/student-courses/' . $targetCourse->id . '/lessons')
            ->assertOk()
            ->assertJsonPath('data.0.course_section_id', null)
            ->assertJsonPath('data.0.course_section', null);
        $this->getJson('/api/student-courses/' . $targetCourse->id . '/lessons/' . $lesson->id)
            ->assertOk()
            ->assertJsonPath('data.course_section_id', null)
            ->assertJsonPath('data.course_section', null);

        $progressResponse = $this->postJson('/api/lesson-progress', [
            'course_enrollment_id' => $enrollment->id,
            'lesson_id' => $lesson->id,
            'status' => 'in_progress',
            'progress_percentage' => 80,
        ])->assertCreated()
            ->assertJsonPath('data.lesson.id', $lesson->id)
            ->assertJsonPath('data.lesson.course_section_id', null)
            ->assertJsonPath('data.lesson.course_section', null);
        $progressId = $progressResponse->json('data.id');
        $validProgressResponse = $this->postJson('/api/lesson-progress', [
            'course_enrollment_id' => $enrollment->id,
            'lesson_id' => $validLesson->id,
            'status' => 'in_progress',
            'progress_percentage' => 25,
        ])->assertCreated()
            ->assertJsonPath('data.lesson.id', $validLesson->id)
            ->assertJsonPath('data.lesson.course_section_id', $targetSection->id);
        $validProgressId = $validProgressResponse->json('data.id');

        $this->assertDatabaseHas('lessons', [
            'id' => $lesson->id,
            'course_id' => $targetCourse->id,
            'course_section_id' => $sourceSection->id,
        ]);

        $progressRecords = collect(
            $this->getJson('/api/lesson-progress')
                ->assertOk()
                ->json('data.data')
        );
        $staleProgressData = $progressRecords->firstWhere('id', $progressId);
        $validProgressData = $progressRecords->firstWhere('id', $validProgressId);
        $this->assertSame(null, data_get($staleProgressData, 'lesson.course_section_id'));
        $this->assertSame(null, data_get($staleProgressData, 'lesson.course_section'));
        $this->assertSame(
            $targetSection->id,
            data_get($validProgressData, 'lesson.course_section_id')
        );
        $this->assertArrayNotHasKey('course_section', $validProgressData['lesson']);

        $this->getJson('/api/lesson-progress/' . $progressId)
            ->assertOk()
            ->assertJsonPath('data.lesson.course_section_id', null)
            ->assertJsonPath('data.lesson.course_section', null);
        $validShowData = $this->getJson('/api/lesson-progress/' . $validProgressId)
            ->assertOk()
            ->assertJsonPath('data.lesson.course_section_id', $targetSection->id)
            ->json('data');
        $this->assertArrayNotHasKey('course_section', $validShowData['lesson']);

        $this->patchJson('/api/lesson-progress/' . $progressId, [
            'watch_time_minutes' => 6,
        ])->assertOk()
            ->assertJsonPath('data.lesson.course_section_id', null)
            ->assertJsonPath('data.lesson.course_section', null);
        $this->patchJson('/api/lesson-progress/' . $validProgressId, [
            'watch_time_minutes' => 7,
        ])->assertOk()
            ->assertJsonPath('data.lesson.course_section_id', $targetSection->id);

        $this->postJson('/api/lesson-progress/' . $progressId . '/complete')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('assessment');

        QuizAttempt::create([
            'quiz_id' => $quiz->id,
            'student_profile_id' => $student->id,
            'attempt_number' => 1,
            'started_at' => now()->subMinute(),
            'submitted_at' => now(),
            'total_marks' => 1,
            'marks_obtained' => 1,
            'percentage' => 100,
            'result_status' => 'passed',
            'status' => 'evaluated',
        ]);

        $this->postJson('/api/lesson-progress/' . $progressId . '/complete')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('assessment');

        AssignmentSubmission::create([
            'assignment_id' => $assignment->id,
            'student_profile_id' => $student->id,
            'submission_text' => 'Target-course assignment response',
            'submitted_at' => now(),
            'status' => 'submitted',
        ]);

        $this->postJson('/api/lesson-progress/' . $progressId . '/complete')
            ->assertOk()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.lesson.course_section_id', null)
            ->assertJsonPath('data.lesson.course_section', null);

        $this->authenticate($teacherUser);
        $this->postJson('/api/lesson-progress/' . $validProgressId . '/complete')
            ->assertOk()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.lesson.course_section_id', $targetSection->id);

        $this->assertDatabaseHas('lessons', [
            'id' => $lesson->id,
            'course_section_id' => $sourceSection->id,
        ]);
        $this->assertDatabaseHas('lessons', [
            'id' => $validLesson->id,
            'course_section_id' => $targetSection->id,
        ]);
    }

    public function test_students_cannot_forge_lesson_completion_and_staff_can_complete_and_recalculate_progress(): void
    {
        $institution = $this->createInstitution('SECURE-COMPLETION');
        [, $teacher] = $this->createTeacher($institution);
        $adminUser = $this->createInstitutionAdmin($institution);
        [$studentUser, $student] = $this->createStudent($institution);
        [, $otherStudent] = $this->createStudent($institution);
        $course = $this->createCourse($institution, $teacher, 'secure-completion');
        $enrollment = $this->createEnrollment($course, $student);
        $otherEnrollment = $this->createEnrollment($course, $otherStudent);
        $lesson = $this->createLesson($course, 'Reading lesson');
        $otherLesson = $this->createLesson($course, 'Other student lesson');
        $this->createLesson($course, 'Third lesson');
        $otherProgress = $this->createProgress($otherEnrollment, $otherLesson);

        $this->authenticate($studentUser);

        $this->postJson('/api/lesson-progress', [
            'course_enrollment_id' => $enrollment->id,
            'lesson_id' => $lesson->id,
            'status' => 'completed',
            'progress_percentage' => 100,
        ])->assertUnprocessable();

        $this->postJson('/api/lesson-progress', [
            'course_enrollment_id' => $enrollment->id,
            'lesson_id' => $lesson->id,
            'status' => 'in_progress',
            'progress_percentage' => 100,
        ])->assertUnprocessable();

        $this->patchJson('/api/course-enrollments/' . $enrollment->id, [
            'progress_percentage' => 100,
            'status' => 'completed',
        ])->assertForbidden();
        $this->postJson('/api/course-enrollments', [
            'course_id' => $course->id,
            'student_profile_id' => $student->id,
            'progress_percentage' => 100,
            'status' => 'completed',
        ])->assertForbidden();

        $this->postJson('/api/lesson-progress', [
            'course_enrollment_id' => $enrollment->id,
            'lesson_id' => $lesson->id,
            'status' => 'in_progress',
            'progress_percentage' => 35,
        ])->assertCreated()
            ->assertJsonPath('data.progress_percentage', '35.00');

        $progress = LessonProgress::where('course_enrollment_id', $enrollment->id)
            ->where('lesson_id', $lesson->id)
            ->firstOrFail();

        $this->patchJson('/api/lesson-progress/' . $progress->id, [
            'progress_percentage' => 100,
        ])->assertUnprocessable();

        $this->assertForbiddenResponse(
            $this->postJson('/api/lesson-progress/' . $otherProgress->id . '/complete'),
            'student completing another student progress'
        );

        $this->postJson('/api/lesson-progress/' . $progress->id . '/complete')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('assessment');

        $this->authenticate($adminUser);
        $this->postJson('/api/lesson-progress/' . $progress->id . '/complete')
            ->assertOk()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.progress_percentage', '100.00');

        $this->assertSame(
            33.33,
            (float) $enrollment->fresh()->progress_percentage
        );
        $this->assertSame('active', $enrollment->fresh()->status);
        $this->assertNull($enrollment->fresh()->completed_at);

        $secondProgress = $this->createProgress($enrollment, $otherLesson);
        $thirdProgress = $this->createProgress(
            $enrollment,
            Lesson::where('course_id', $course->id)
                ->where('title', 'Third lesson')
                ->firstOrFail()
        );

        $this->postJson('/api/lesson-progress/' . $secondProgress->id . '/complete')
            ->assertOk();
        $this->assertSame(66.67, (float) $enrollment->fresh()->progress_percentage);
        $this->assertSame('active', $enrollment->fresh()->status);

        $this->postJson('/api/lesson-progress/' . $thirdProgress->id . '/complete')
            ->assertOk();
        $this->assertSame(100.0, (float) $enrollment->fresh()->progress_percentage);
        $this->assertSame('completed', $enrollment->fresh()->status);
        $this->assertNotNull($enrollment->fresh()->completed_at);

        $this->authenticate($adminUser);
        $this->patchJson('/api/course-enrollments/' . $enrollment->id, [
            'progress_percentage' => 0,
            'status' => 'active',
        ])->assertOk();
        $this->assertSame(100.0, (float) $enrollment->fresh()->progress_percentage);
        $this->assertSame('completed', $enrollment->fresh()->status);

        $this->deleteJson('/api/lesson-progress/' . $thirdProgress->id)->assertOk();
        $this->assertSame(66.67, (float) $enrollment->fresh()->progress_percentage);
        $this->assertSame('active', $enrollment->fresh()->status);
        $this->assertNull($enrollment->fresh()->completed_at);
    }

    public function test_students_cannot_reassign_a_completed_progress_record_to_another_lesson(): void
    {
        $institution = $this->createInstitution('COMPLETED-LESSON-REASSIGNMENT');
        [, $teacher] = $this->createTeacher($institution);
        $adminUser = $this->createInstitutionAdmin($institution);
        [$studentUser, $student] = $this->createStudent($institution);
        $course = $this->createCourse($institution, $teacher, 'completed-lesson-reassignment');
        $enrollment = $this->createEnrollment($course, $student);
        $completedLesson = $this->createLesson($course, 'Completed lesson');
        $targetLesson = $this->createLesson($course, 'Target lesson');
        $progress = $this->createProgress($enrollment, $completedLesson);

        $this->authenticate($adminUser);
        $this->postJson('/api/lesson-progress/' . $progress->id . '/complete')
            ->assertOk()
            ->assertJsonPath('data.status', 'completed');

        $this->assertSame(50.0, (float) $enrollment->fresh()->progress_percentage);

        $this->authenticate($studentUser);
        $this->patchJson('/api/lesson-progress/' . $progress->id, [
            'lesson_id' => $targetLesson->id,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('lesson_id');

        $this->assertSame(
            (int) $completedLesson->id,
            (int) $progress->fresh()->lesson_id
        );
        $this->assertSame('completed', $progress->fresh()->status);
        $this->assertSame(
            0,
            LessonProgress::where('course_enrollment_id', $enrollment->id)
                ->where('lesson_id', $targetLesson->id)
                ->where('status', 'completed')
                ->count()
        );
        $this->assertSame(50.0, (float) $enrollment->fresh()->progress_percentage);
        $this->assertSame('active', $enrollment->fresh()->status);
    }

    public function test_student_reopening_completed_lesson_resets_progress_and_enrollment_completion(): void
    {
        $institution = $this->createInstitution('REOPEN-COMPLETED-LESSON');
        [, $teacher] = $this->createTeacher($institution);
        $adminUser = $this->createInstitutionAdmin($institution);
        [$studentUser, $student] = $this->createStudent($institution);
        $course = $this->createCourse($institution, $teacher, 'reopen-completed-lesson');
        $enrollment = $this->createEnrollment($course, $student);
        $lesson = $this->createLesson($course, 'Reopened lesson');
        $progress = $this->createProgress($enrollment, $lesson);

        $this->authenticate($adminUser);
        $this->patchJson('/api/lesson-progress/' . $progress->id, [
            'status' => 'completed',
            'progress_percentage' => 35,
        ])->assertOk()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.progress_percentage', '100.00');

        $progress->refresh();
        $this->assertNotNull($progress->completed_at);
        $this->assertSame(100.0, (float) $enrollment->fresh()->progress_percentage);
        $this->assertSame('completed', $enrollment->fresh()->status);
        $this->assertNotNull($enrollment->fresh()->completed_at);

        $this->authenticate($studentUser);
        $this->patchJson('/api/lesson-progress/' . $progress->id, [
            'status' => 'in_progress',
        ])->assertOk()
            ->assertJsonPath('data.status', 'in_progress')
            ->assertJsonPath('data.progress_percentage', '0.00')
            ->assertJsonPath('data.completed_at', null);

        $progress->refresh();
        $this->assertSame('in_progress', $progress->status);
        $this->assertSame(0.0, (float) $progress->progress_percentage);
        $this->assertNull($progress->completed_at);
        $this->assertSame(0.0, (float) $enrollment->fresh()->progress_percentage);
        $this->assertSame('active', $enrollment->fresh()->status);
        $this->assertNull($enrollment->fresh()->completed_at);

        $this->patchJson('/api/lesson-progress/' . $progress->id, [
            'progress_percentage' => 35,
        ])->assertOk()
            ->assertJsonPath('data.status', 'in_progress')
            ->assertJsonPath('data.progress_percentage', '35.00')
            ->assertJsonPath('data.completed_at', null);
    }

    public function test_attached_published_assessments_require_server_recorded_evidence_for_lesson_completion(): void
    {
        $institution = $this->createInstitution('ASSESSMENT-COMPLETION');
        [, $teacher] = $this->createTeacher($institution);
        [$studentUser, $student] = $this->createStudent($institution);
        $course = $this->createCourse($institution, $teacher, 'assessment-completion');
        $enrollment = $this->createEnrollment($course, $student);
        $lesson = $this->createLesson($course, 'Assessment lesson');
        $assignment = $this->createAssignment($course, $teacher, 'Lesson assignment');
        $assignment->update(['lesson_id' => $lesson->id]);
        $urlAssignment = $this->createAssignment($course, $teacher, 'URL assignment');
        $urlAssignment->update(['lesson_id' => $lesson->id]);
        $fileAssignment = $this->createAssignment($course, $teacher, 'File assignment');
        $fileAssignment->update(['lesson_id' => $lesson->id]);
        $quiz = Quiz::create([
            'course_id' => $course->id,
            'lesson_id' => $lesson->id,
            'teacher_profile_id' => $teacher->id,
            'title' => 'Lesson quiz',
            'passing_marks' => 1,
            'total_marks' => 1,
            'status' => 'published',
        ]);

        $this->authenticate($studentUser);
        $this->postJson('/api/lesson-progress', [
            'course_enrollment_id' => $enrollment->id,
            'lesson_id' => $lesson->id,
            'status' => 'in_progress',
            'progress_percentage' => 80,
        ])->assertCreated();

        $progress = LessonProgress::where('course_enrollment_id', $enrollment->id)
            ->where('lesson_id', $lesson->id)
            ->firstOrFail();

        $this->postJson('/api/lesson-progress/' . $progress->id . '/complete')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('assessment');

        QuizAttempt::create([
            'quiz_id' => $quiz->id,
            'student_profile_id' => $student->id,
            'attempt_number' => 1,
            'started_at' => now()->subMinute(),
            'submitted_at' => now(),
            'total_marks' => 1,
            'marks_obtained' => 1,
            'percentage' => 100,
            'result_status' => 'passed',
            'status' => 'evaluated',
        ]);

        $this->postJson('/api/lesson-progress/' . $progress->id . '/complete')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('assessment');

        $emptySubmissionId = $this->postJson('/api/assignment-submissions', [
            'assignment_id' => $assignment->id,
            'student_profile_id' => $student->id,
            'status' => 'submitted',
        ])
            ->assertCreated()
            ->assertJsonPath('data.submission_text', null)
            ->assertJsonPath('data.external_url', null)
            ->assertJsonPath('data.file_path', null)
            ->json('data.id');

        $url = 'https://example.test/lesson-assignment';
        $this->postJson('/api/assignment-submissions', [
            'assignment_id' => $urlAssignment->id,
            'student_profile_id' => $student->id,
            'external_url' => $url,
            'status' => 'submitted',
        ])->assertCreated();

        Storage::fake('public');
        $fileResponse = $this->post('/api/assignment-submissions', [
            'assignment_id' => $fileAssignment->id,
            'student_profile_id' => $student->id,
            'file' => UploadedFile::fake()->create('solution.pdf', 5, 'application/pdf'),
            'status' => 'submitted',
        ], ['Accept' => 'application/json'])->assertCreated();
        $filePath = $fileResponse->json('data.file_path');
        $this->assertStringStartsWith('assignment-submissions/', $filePath);
        Storage::disk('public')->assertExists($filePath);

        $this->postJson('/api/lesson-progress/' . $progress->id . '/complete')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('assessment');

        $this->patchJson('/api/assignment-submissions/' . $emptySubmissionId, [
            'submission_text' => 'My completed response',
        ])->assertOk()
            ->assertJsonPath('data.submission_text', 'My completed response');

        $this->postJson('/api/lesson-progress/' . $progress->id . '/complete')
            ->assertOk()
            ->assertJsonPath('data.status', 'completed');
    }

    public function test_automatic_quiz_attempt_is_evaluated_on_submission_and_unlocks_lesson_with_all_evidence(): void
    {
        $institution = $this->createInstitution('AUTOMATIC-QUIZ-SUBMISSION');
        [, $teacher] = $this->createTeacher($institution);
        [$studentUser, $student] = $this->createStudent($institution);
        $course = $this->createCourse($institution, $teacher, 'automatic-quiz-submission');
        $enrollment = $this->createEnrollment($course, $student);
        $lesson = $this->createLesson($course, 'Automatic quiz lesson');
        $assignment = $this->createAssignment($course, $teacher, 'Required lesson assignment');
        $assignment->update(['lesson_id' => $lesson->id]);

        $quiz = Quiz::create([
            'course_id' => $course->id,
            'lesson_id' => $lesson->id,
            'teacher_profile_id' => $teacher->id,
            'title' => 'Automatic grading quiz',
            'total_marks' => 10,
            'passing_marks' => 5,
            'status' => 'published',
        ]);
        $questions = [];
        $options = [];

        foreach (['First question', 'Second question'] as $index => $questionText) {
            $question = QuestionBank::create([
                'course_id' => $course->id,
                'lesson_id' => $lesson->id,
                'question_text' => $questionText,
                'question_type' => 'mcq',
                'marks' => 5,
                'status' => 'active',
            ]);
            QuizQuestion::create([
                'quiz_id' => $quiz->id,
                'question_bank_id' => $question->id,
                'marks' => 5,
                'sort_order' => $index + 1,
            ]);

            $options[] = [
                'correct' => QuestionOption::create([
                    'question_bank_id' => $question->id,
                    'option_text' => 'Correct',
                    'is_correct' => true,
                    'sort_order' => 1,
                ]),
                'incorrect' => QuestionOption::create([
                    'question_bank_id' => $question->id,
                    'option_text' => 'Incorrect',
                    'is_correct' => false,
                    'sort_order' => 2,
                ]),
            ];
            $questions[] = $question;
        }

        $this->authenticate($studentUser);
        $progressResponse = $this->postJson('/api/lesson-progress', [
            'course_enrollment_id' => $enrollment->id,
            'lesson_id' => $lesson->id,
            'status' => 'in_progress',
            'progress_percentage' => 80,
        ])->assertCreated();
        $progressId = $progressResponse->json('data.id');

        $this->postJson('/api/lesson-progress/' . $progressId . '/complete')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('assessment');

        $failedAttemptId = $this->postJson('/api/quiz-attempts', [
            'quiz_id' => $quiz->id,
            'student_profile_id' => $student->id,
        ])->assertCreated()->json('data.id');

        foreach ($questions as $index => $question) {
            $this->postJson('/api/quiz-answers', [
                'quiz_attempt_id' => $failedAttemptId,
                'question_bank_id' => $question->id,
                'question_option_id' => $options[$index]['incorrect']->id,
            ])->assertCreated();
        }

        $failedAttempt = QuizAttempt::findOrFail($failedAttemptId);
        $this->assertSame('in_progress', $failedAttempt->status);
        $this->assertNull($failedAttempt->submitted_at);
        $this->assertNull($failedAttempt->evaluated_at);

        $this->patchJson('/api/quiz-attempts/' . $failedAttemptId, [
            'status' => 'submitted',
        ])->assertOk()
            ->assertJsonPath('data.status', 'evaluated')
            ->assertJsonPath('data.result_status', 'failed')
            ->assertJsonPath('data.marks_obtained', '0.00')
            ->assertJsonPath('data.percentage', '0.00');

        $failedAttempt->refresh();
        $this->assertNotNull($failedAttempt->submitted_at);
        $this->assertNotNull($failedAttempt->evaluated_at);
        $this->assertTrue(
            $failedAttempt->submitted_at->lessThanOrEqualTo($failedAttempt->evaluated_at)
        );

        $passedAttemptId = $this->postJson('/api/quiz-attempts', [
            'quiz_id' => $quiz->id,
            'student_profile_id' => $student->id,
        ])->assertCreated()->json('data.id');

        $this->postJson('/api/quiz-answers', [
            'quiz_attempt_id' => $passedAttemptId,
            'question_bank_id' => $questions[0]->id,
            'question_option_id' => $options[0]['correct']->id,
        ])->assertCreated();
        $this->postJson('/api/quiz-answers', [
            'quiz_attempt_id' => $passedAttemptId,
            'question_bank_id' => $questions[1]->id,
            'question_option_id' => $options[1]['incorrect']->id,
        ])->assertCreated();

        $passedAttempt = QuizAttempt::findOrFail($passedAttemptId);
        $this->assertSame('in_progress', $passedAttempt->status);
        $this->assertSame('5.00', $passedAttempt->marks_obtained);
        $this->assertNull($passedAttempt->evaluated_at);

        $this->patchJson('/api/quiz-attempts/' . $passedAttemptId, [
            'status' => 'submitted',
        ])->assertOk()
            ->assertJsonPath('data.status', 'evaluated')
            ->assertJsonPath('data.result_status', 'passed')
            ->assertJsonPath('data.marks_obtained', '5.00')
            ->assertJsonPath('data.percentage', '50.00');

        $passedAttempt->refresh();
        $this->assertNotNull($passedAttempt->submitted_at);
        $this->assertNotNull($passedAttempt->evaluated_at);

        $this->postJson('/api/lesson-progress/' . $progressId . '/complete')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('assessment');

        $this->postJson('/api/assignment-submissions', [
            'assignment_id' => $assignment->id,
            'student_profile_id' => $student->id,
            'submission_text' => 'Completed assignment response',
            'status' => 'submitted',
        ])->assertCreated();

        $this->postJson('/api/lesson-progress/' . $progressId . '/complete')
            ->assertOk()
            ->assertJsonPath('data.status', 'completed');
    }

    public function test_manual_is_correct_only_update_is_rejected_without_affecting_zero_or_automatic_grading(): void
    {
        $institution = $this->createInstitution('MANUAL-IS-CORRECT-ONLY');
        [$teacherUser, $teacher] = $this->createTeacher($institution);
        [$studentUser, $student] = $this->createStudent($institution);
        $course = $this->createCourse($institution, $teacher, 'manual-is-correct-only');
        $this->createEnrollment($course, $student);

        $quiz = Quiz::create([
            'course_id' => $course->id,
            'teacher_profile_id' => $teacher->id,
            'title' => 'Manual and automatic grading quiz',
            'total_marks' => 2,
            'passing_marks' => 1,
            'status' => 'published',
        ]);
        $manualQuestion = QuestionBank::create([
            'course_id' => $course->id,
            'question_text' => 'Explain the answer.',
            'question_type' => 'short_answer',
            'marks' => 1,
            'status' => 'active',
        ]);
        $automaticQuestion = QuestionBank::create([
            'course_id' => $course->id,
            'question_text' => 'Select the correct option.',
            'question_type' => 'mcq',
            'marks' => 1,
            'status' => 'active',
        ]);

        foreach ([$manualQuestion, $automaticQuestion] as $index => $question) {
            QuizQuestion::create([
                'quiz_id' => $quiz->id,
                'question_bank_id' => $question->id,
                'marks' => 1,
                'sort_order' => $index + 1,
            ]);
        }

        $correctOption = QuestionOption::create([
            'question_bank_id' => $automaticQuestion->id,
            'option_text' => 'Correct',
            'is_correct' => true,
            'sort_order' => 1,
        ]);

        $this->authenticate($studentUser);
        $attemptId = $this->postJson('/api/quiz-attempts', [
            'quiz_id' => $quiz->id,
            'student_profile_id' => $student->id,
        ])->assertCreated()->json('data.id');
        $manualAnswerId = $this->postJson('/api/quiz-answers', [
            'quiz_attempt_id' => $attemptId,
            'question_bank_id' => $manualQuestion->id,
            'answer_text' => 'Student response.',
        ])->assertCreated()->json('data.id');
        $automaticAnswerId = $this->postJson('/api/quiz-answers', [
            'quiz_attempt_id' => $attemptId,
            'question_bank_id' => $automaticQuestion->id,
            'question_option_id' => $correctOption->id,
        ])->assertCreated()->json('data.id');

        $this->assertDatabaseHas('quiz_answers', [
            'id' => $automaticAnswerId,
            'is_correct' => true,
            'marks_obtained' => 1,
        ]);

        $this->patchJson('/api/quiz-attempts/' . $attemptId, [
            'status' => 'submitted',
        ])->assertOk()
            ->assertJsonPath('data.status', 'submitted')
            ->assertJsonPath('data.result_status', 'pending');

        $this->authenticate($teacherUser);
        $this->patchJson('/api/quiz-answers/' . $manualAnswerId, [
            'is_correct' => true,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('marks_obtained')
            ->assertJsonPath(
                'errors.marks_obtained.0',
                'Explicit marks are required to grade a manual answer.'
            );
        $this->patchJson('/api/quiz-answers/' . $manualAnswerId, [
            'is_correct' => false,
            'marks_obtained' => null,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('marks_obtained');

        $manualAnswer = QuizAnswer::findOrFail($manualAnswerId);
        $this->assertFalse($manualAnswer->is_correct);
        $this->assertSame('0.00', $manualAnswer->marks_obtained);
        $this->assertNull($manualAnswer->manually_graded_at);

        $attempt = QuizAttempt::findOrFail($attemptId);
        $this->assertSame('submitted', $attempt->status);
        $this->assertSame('pending', $attempt->result_status);
        $this->assertNull($attempt->evaluated_at);

        $this->patchJson('/api/quiz-answers/' . $manualAnswerId, [
            'marks_obtained' => 0,
        ])->assertOk()
            ->assertJsonPath('data.marks_obtained', '0.00');

        $manualAnswer->refresh();
        $this->assertFalse($manualAnswer->is_correct);
        $this->assertNotNull($manualAnswer->manually_graded_at);
        $this->assertDatabaseHas('quiz_answers', [
            'id' => $automaticAnswerId,
            'is_correct' => true,
            'marks_obtained' => 1,
        ]);

        $attempt->refresh();
        $this->assertSame('evaluated', $attempt->status);
        $this->assertSame('passed', $attempt->result_status);
        $this->assertSame('1.00', $attempt->marks_obtained);
        $this->assertNotNull($attempt->evaluated_at);
    }

    public function test_pending_manual_grading_redacts_student_answer_and_attempt_responses_for_both_result_settings(): void
    {
        foreach ([false, true] as $showResultImmediately) {
            $suffix = $showResultImmediately ? 'IMMEDIATE' : 'DELAYED';
            $fixture = $this->createQuizResultVisibilityFixture(
                $suffix,
                $showResultImmediately
            );
            $this->authenticate($fixture['student_user']);

            $attemptCreateResponse = $this->postJson('/api/quiz-attempts', [
                'quiz_id' => $fixture['quiz']->id,
                'student_profile_id' => $fixture['student']->id,
            ])->assertCreated()
                ->assertJsonPath('data.status', 'in_progress');
            $this->assertPendingQuizAttemptPayload($attemptCreateResponse->json('data'));
            $attemptId = $attemptCreateResponse->json('data.id');

            $attempt = QuizAttempt::findOrFail($attemptId);
            $pendingAnswer = QuizAnswer::create([
                'quiz_attempt_id' => $attempt->id,
                'question_bank_id' => $fixture['manual_questions']['short_answer']->id,
                'answer_text' => 'Manual response awaiting grading.',
                'is_correct' => true,
                'marks_obtained' => 4,
            ]);
            $attempt->update([
                'marks_obtained' => 12,
                'percentage' => 75,
                'result_status' => 'passed',
            ]);

            $automaticAnswerResponse = $this->postJson('/api/quiz-answers', [
                'quiz_attempt_id' => $attemptId,
                'question_bank_id' => $fixture['automatic_question']->id,
                'question_option_id' => $fixture['correct_option']->id,
            ])->assertCreated();
            $automaticAnswerId = $automaticAnswerResponse->json('data.id');
            $this->assertPendingQuizAnswerPayload(
                $automaticAnswerResponse->json('data')
            );
            $this->assertArrayNotHasKey(
                'is_correct',
                $automaticAnswerResponse->json('data.question_option')
            );
            foreach ($automaticAnswerResponse->json('data.question_bank.options') as $option) {
                $this->assertArrayNotHasKey('is_correct', $option);
            }

            $manualAnswerResponse = $this->postJson('/api/quiz-answers', [
                'quiz_attempt_id' => $attemptId,
                'question_bank_id' => $fixture['manual_questions']['fill_blank']->id,
                'answer_text' => 'Second manual response.',
            ])->assertCreated();
            $this->assertPendingQuizAnswerPayload(
                $manualAnswerResponse->json('data')
            );
            $this->assertSame(
                'Study guidance: organize evidence before answering.',
                $manualAnswerResponse->json('data.question_bank.explanation')
            );

            $this->patchJson('/api/quiz-answers/' . $pendingAnswer->id, [
                'answer_text' => 'Updated manual response.',
            ])->assertOk()->assertJsonMissingPath(
                'data.quiz_attempt.result_status'
            );
            $this->assertPendingQuizAnswerPayload(
                $this->getJson('/api/quiz-answers/' . $pendingAnswer->id)
                    ->assertOk()
                    ->json('data')
            );

            $answerList = $this->getJson('/api/quiz-answers')
                ->assertOk()
                ->json('data.data');
            $this->assertCount(3, $answerList);
            foreach ($answerList as $answer) {
                $this->assertPendingQuizAnswerPayload($answer);
            }

            $submissionResponse = $this->patchJson('/api/quiz-attempts/' . $attemptId, [
                'status' => 'submitted',
            ])->assertOk();
            $this->assertPendingQuizAttemptPayload(
                $submissionResponse->json('data')
            );

            $attemptDetail = $this->getJson('/api/quiz-attempts/' . $attemptId)
                ->assertOk()
                ->json('data');
            $this->assertPendingQuizAttemptPayload($attemptDetail);
            $attemptList = $this->getJson('/api/quiz-attempts')
                ->assertOk()
                ->json('data.data');
            $this->assertCount(1, $attemptList);
            $this->assertPendingQuizAttemptPayload($attemptList[0]);

            $this->assertPendingQuizAnswerPayload(
                $this->getJson('/api/quiz-answers/' . $automaticAnswerId)
                    ->assertOk()
                    ->json('data')
            );
            foreach (
                $this->getJson('/api/quiz-answers')
                    ->assertOk()
                    ->json('data.data') as $answer
            ) {
                $this->assertPendingQuizAnswerPayload($answer);
            }
        }
    }

    public function test_students_cannot_see_in_progress_automatic_quiz_results_but_staff_can(): void
    {
        $fixture = $this->createQuizResultVisibilityFixture('IN-PROGRESS', false);
        $this->authenticate($fixture['student_user']);

        $attemptCreateResponse = $this->postJson('/api/quiz-attempts', [
            'quiz_id' => $fixture['quiz']->id,
            'student_profile_id' => $fixture['student']->id,
        ])->assertCreated();
        $this->assertInProgressQuizAttemptResultsAreHidden(
            $attemptCreateResponse->json('data')
        );
        $attemptId = $attemptCreateResponse->json('data.id');

        $answerCreateResponse = $this->postJson('/api/quiz-answers', [
            'quiz_attempt_id' => $attemptId,
            'question_bank_id' => $fixture['automatic_question']->id,
            'question_option_id' => $fixture['correct_option']->id,
        ])->assertCreated();
        $this->assertInProgressQuizAttemptResultsAreHidden(
            $answerCreateResponse->json('data.quiz_attempt')
        );
        $answerId = $answerCreateResponse->json('data.id');

        $attempt = QuizAttempt::findOrFail($attemptId);
        $this->assertSame('in_progress', $attempt->status);
        $this->assertSame('1.00', $attempt->marks_obtained);
        $this->assertSame('6.25', $attempt->percentage);
        $this->assertSame('failed', $attempt->result_status);

        $attemptList = $this->getJson('/api/quiz-attempts')
            ->assertOk()
            ->json('data.data');
        $this->assertCount(1, $attemptList);
        $this->assertInProgressQuizAttemptResultsAreHidden($attemptList[0]);

        $attemptDetail = $this->getJson('/api/quiz-attempts/' . $attemptId)
            ->assertOk()
            ->json('data');
        $this->assertInProgressQuizAttemptResultsAreHidden($attemptDetail);

        $answerDetail = $this->getJson('/api/quiz-answers/' . $answerId)
            ->assertOk()
            ->json('data');
        $this->assertInProgressQuizAttemptResultsAreHidden(
            $answerDetail['quiz_attempt']
        );
        $this->assertArrayNotHasKey('is_correct', $answerDetail);
        $this->assertArrayNotHasKey('marks_obtained', $answerDetail);

        $this->authenticate($fixture['teacher_user']);

        $staffAttemptList = $this->getJson('/api/quiz-attempts')
            ->assertOk()
            ->json('data.data');
        $this->assertCount(1, $staffAttemptList);
        $this->assertSame('failed', $staffAttemptList[0]['result_status']);
        $this->assertSame('1.00', $staffAttemptList[0]['marks_obtained']);
        $this->assertSame('6.25', $staffAttemptList[0]['percentage']);

        $this->getJson('/api/quiz-attempts/' . $attemptId)
            ->assertOk()
            ->assertJsonPath('data.status', 'in_progress')
            ->assertJsonPath('data.result_status', 'failed')
            ->assertJsonPath('data.marks_obtained', '1.00')
            ->assertJsonPath('data.percentage', '6.25');
        $this->getJson('/api/quiz-answers/' . $answerId)
            ->assertOk()
            ->assertJsonPath('data.is_correct', true)
            ->assertJsonPath('data.marks_obtained', '1.00')
            ->assertJsonPath('data.quiz_attempt.status', 'in_progress')
            ->assertJsonPath('data.quiz_attempt.result_status', 'failed')
            ->assertJsonPath('data.quiz_attempt.marks_obtained', '1.00')
            ->assertJsonPath('data.quiz_attempt.percentage', '6.25');
    }

    public function test_legacy_evaluated_attempt_with_null_manual_marker_is_redacted_only_for_learners(): void
    {
        $fixture = $this->createQuizResultVisibilityFixture('LEGACY', false);
        $attempt = QuizAttempt::create([
            'quiz_id' => $fixture['quiz']->id,
            'student_profile_id' => $fixture['student']->id,
            'attempt_number' => 1,
            'started_at' => now()->subMinutes(10),
            'submitted_at' => now()->subMinutes(5),
            'evaluated_at' => now()->subMinutes(4),
            'total_marks' => 16,
            'marks_obtained' => 12,
            'percentage' => 75,
            'result_status' => 'passed',
            'status' => 'evaluated',
        ]);
        $answer = QuizAnswer::create([
            'quiz_attempt_id' => $attempt->id,
            'question_bank_id' => $fixture['manual_questions']['long_answer']->id,
            'answer_text' => 'Legacy response.',
            'is_correct' => true,
            'marks_obtained' => 5,
            'manually_graded_at' => null,
        ]);

        $this->authenticate($fixture['student_user']);

        $this->assertPendingQuizAttemptPayload(
            $this->getJson('/api/quiz-attempts/' . $attempt->id)
                ->assertOk()
                ->json('data')
        );
        $legacyAttemptList = $this->getJson('/api/quiz-attempts')
            ->assertOk()
            ->json('data.data');
        $this->assertCount(1, $legacyAttemptList);
        $this->assertPendingQuizAttemptPayload($legacyAttemptList[0]);

        $this->assertPendingQuizAnswerPayload(
            $this->getJson('/api/quiz-answers/' . $answer->id)
                ->assertOk()
                ->json('data')
        );
        $legacyAnswerList = $this->getJson('/api/quiz-answers')
            ->assertOk()
            ->json('data.data');
        $this->assertCount(1, $legacyAnswerList);
        $this->assertPendingQuizAnswerPayload($legacyAnswerList[0]);

        $this->authenticate($fixture['teacher_user']);
        $this->getJson('/api/quiz-attempts/' . $attempt->id)
            ->assertOk()
            ->assertJsonPath('data.status', 'evaluated')
            ->assertJsonPath('data.result_status', 'passed')
            ->assertJsonPath('data.marks_obtained', '12.00')
            ->assertJsonPath('data.percentage', '75.00')
            ->assertJsonPath('data.evaluated_at', $attempt->evaluated_at->toISOString());
        $this->getJson('/api/quiz-answers/' . $answer->id)
            ->assertOk()
            ->assertJsonPath('data.is_correct', true)
            ->assertJsonPath('data.marks_obtained', '5.00')
            ->assertJsonPath('data.manually_graded_at', null)
            ->assertJsonPath('data.quiz_attempt.status', 'evaluated')
            ->assertJsonPath('data.quiz_attempt.marks_obtained', '12.00');

        $this->assertNull($answer->fresh()->manually_graded_at);
    }

    public function test_student_and_parent_dashboards_hide_pending_and_legacy_quiz_results(): void
    {
        $fixture = $this->createQuizResultVisibilityFixture('DASHBOARD', false);
        $pendingAttempt = QuizAttempt::create([
            'quiz_id' => $fixture['quiz']->id,
            'student_profile_id' => $fixture['student']->id,
            'attempt_number' => 1,
            'started_at' => now()->subMinutes(10),
            'submitted_at' => now()->subMinutes(5),
            'total_marks' => 16,
            'marks_obtained' => 8,
            'percentage' => 50,
            'result_status' => 'passed',
            'status' => 'submitted',
        ]);
        $legacyAttempt = QuizAttempt::create([
            'quiz_id' => $fixture['quiz']->id,
            'student_profile_id' => $fixture['student']->id,
            'attempt_number' => 2,
            'started_at' => now()->subMinutes(20),
            'submitted_at' => now()->subMinutes(15),
            'evaluated_at' => now()->subMinutes(14),
            'total_marks' => 16,
            'marks_obtained' => 12,
            'percentage' => 75,
            'result_status' => 'passed',
            'status' => 'evaluated',
        ]);
        $pendingAnswer = QuizAnswer::create([
            'quiz_attempt_id' => $pendingAttempt->id,
            'question_bank_id' => $fixture['manual_questions']['short_answer']->id,
            'answer_text' => 'Awaiting grading.',
            'is_correct' => true,
            'marks_obtained' => 4,
        ]);
        $legacyAnswer = QuizAnswer::create([
            'quiz_attempt_id' => $legacyAttempt->id,
            'question_bank_id' => $fixture['manual_questions']['long_answer']->id,
            'answer_text' => 'Legacy answer awaiting marker.',
            'is_correct' => true,
            'marks_obtained' => 5,
            'manually_graded_at' => null,
        ]);
        $parentUser = $this->createParent(
            Institution::findOrFail($fixture['student']->institution_id),
            $fixture['student']
        );

        $this->authenticate($fixture['student_user']);
        $studentAttempts = $this->getJson('/api/student-dashboard/' . $fixture['student']->id)
            ->assertOk()
            ->json('data.quiz_attempts');
        $this->assertCount(2, $studentAttempts);
        foreach ($studentAttempts as $attempt) {
            $this->assertPendingQuizAttemptPayload($attempt);
        }

        $this->authenticate($parentUser);
        $parentDashboard = $this->getJson('/api/parent-dashboard/' . $fixture['student']->id)
            ->assertOk()
            ->json('data');
        $this->assertCount(2, $parentDashboard['quiz_attempts']);
        foreach ($parentDashboard['quiz_attempts'] as $attempt) {
            $this->assertPendingQuizAttemptPayload($attempt);
        }

        $this->authenticate($fixture['teacher_user']);
        $staffAttempts = $this->getJson('/api/student-dashboard/' . $fixture['student']->id)
            ->assertOk()
            ->json('data.quiz_attempts');
        $staffLegacyAttempt = collect($staffAttempts)->firstWhere('id', $legacyAttempt->id);
        $this->assertSame('evaluated', $staffLegacyAttempt['status']);
        $this->assertSame('passed', $staffLegacyAttempt['result_status']);
        $this->assertSame('12.00', $staffLegacyAttempt['marks_obtained']);
        $this->assertSame('75.00', $staffLegacyAttempt['percentage']);

        $this->assertDatabaseHas('quiz_attempts', [
            'id' => $legacyAttempt->id,
            'status' => 'evaluated',
            'result_status' => 'passed',
        ]);
        $this->assertNull($pendingAnswer->fresh()->manually_graded_at);
        $this->assertNull($legacyAnswer->fresh()->manually_graded_at);
    }

    public function test_student_and_parent_gradebooks_exclude_pending_attempts_and_mask_stored_totals(): void
    {
        $fixture = $this->createQuizResultVisibilityFixture('GRADEBOOK-PENDING', false);
        $course = $fixture['quiz']->course;
        $student = $fixture['student'];
        $teacher = TeacherProfile::findOrFail($course->teacher_profile_id);

        $legacyAttempt = QuizAttempt::create([
            'quiz_id' => $fixture['quiz']->id,
            'student_profile_id' => $student->id,
            'attempt_number' => 1,
            'started_at' => now()->subMinutes(10),
            'submitted_at' => now()->subMinutes(5),
            'evaluated_at' => now()->subMinutes(4),
            'total_marks' => 16,
            'marks_obtained' => 8,
            'percentage' => 50,
            'result_status' => 'passed',
            'status' => 'evaluated',
        ]);
        $pendingAnswer = QuizAnswer::create([
            'quiz_attempt_id' => $legacyAttempt->id,
            'question_bank_id' => $fixture['manual_questions']['fill_blank']->id,
            'answer_text' => 'Legacy manual answer.',
            'is_correct' => true,
            'marks_obtained' => 5,
            'manually_graded_at' => null,
        ]);
        $completedAttempt = QuizAttempt::create([
            'quiz_id' => $fixture['quiz']->id,
            'student_profile_id' => $student->id,
            'attempt_number' => 2,
            'started_at' => now()->subMinutes(3),
            'submitted_at' => now()->subMinutes(2),
            'evaluated_at' => now()->subMinute(),
            'total_marks' => 16,
            'marks_obtained' => 6,
            'percentage' => 37.5,
            'result_status' => 'failed',
            'status' => 'evaluated',
        ]);
        QuizAnswer::create([
            'quiz_attempt_id' => $completedAttempt->id,
            'question_bank_id' => $fixture['automatic_question']->id,
            'question_option_id' => $fixture['correct_option']->id,
            'answer_text' => null,
            'is_correct' => true,
            'marks_obtained' => 6,
        ]);

        $assignment = $this->createAssignment($course, $teacher, 'Gradebook assignment');
        $assignment->update(['maximum_marks' => 20]);
        $submission = $this->createSubmission($assignment, $student);
        AssignmentEvaluation::create([
            'assignment_submission_id' => $submission->id,
            'teacher_profile_id' => $teacher->id,
            'marks_obtained' => 10,
            'maximum_marks' => 20,
            'result_status' => 'passed',
            'evaluated_at' => now(),
        ]);

        $gradebook = Gradebook::create([
            'course_id' => $course->id,
            'student_profile_id' => $student->id,
            'assignment_marks' => 10,
            'quiz_marks' => 8,
            'total_marks' => 18,
            'maximum_marks' => 36,
            'percentage' => 50,
            'grade' => 'C',
            'result_status' => 'passed',
        ]);
        $parentUser = $this->createParent(
            Institution::findOrFail($student->institution_id),
            $student
        );

        $this->authenticate($fixture['student_user']);
        $studentGradebookList = $this->getJson('/api/gradebooks')
            ->assertOk()
            ->json('data.data');
        $this->assertCount(1, $studentGradebookList);
        $this->assertPendingGradebookPayload($studentGradebookList[0]);
        $this->assertPendingGradebookPayload(
            $this->getJson('/api/gradebooks/' . $gradebook->id)
                ->assertOk()
                ->json('data')
        );

        $this->authenticate($parentUser);
        $parentDashboard = $this->getJson('/api/parent-dashboard/' . $student->id)
            ->assertOk()
            ->json('data');
        $this->assertCount(1, $parentDashboard['gradebooks']);
        $this->assertPendingGradebookPayload($parentDashboard['gradebooks'][0]);

        $parentGrades = $this->getJson('/api/parent-dashboard/' . $student->id . '/grades')
            ->assertOk()
            ->json('data');
        $this->assertNull($parentGrades['overall_percentage']);
        $this->assertTrue($parentGrades['grading_pending']);
        $this->assertPendingGradebookPayload($parentGrades['gradebooks'][0]);
        $this->getJson('/api/gradebooks')
            ->assertOk()
            ->assertJsonPath('data.data.0.result_status', 'pending')
            ->assertJsonMissingPath('data.data.0.quiz_marks');
        $this->assertPendingGradebookPayload(
            $this->getJson('/api/gradebooks/' . $gradebook->id)
                ->assertOk()
                ->json('data')
        );

        // Learner reads redact stale persisted totals without rewriting history.
        $this->assertDatabaseHas('gradebooks', [
            'id' => $gradebook->id,
            'quiz_marks' => 8,
            'total_marks' => 18,
            'percentage' => 50,
            'result_status' => 'passed',
        ]);
        $this->assertNull($pendingAnswer->fresh()->manually_graded_at);

        // The existing authorized recalculation path refreshes the row from
        // finalized attempts only and leaves it explicitly pending.
        $this->authenticate($fixture['teacher_user']);
        $this->getJson('/api/gradebooks/' . $gradebook->id)
            ->assertOk()
            ->assertJsonPath('data.quiz_marks', '8.00')
            ->assertJsonPath('data.percentage', '50.00')
            ->assertJsonPath('data.result_status', 'passed');
        $this->postJson('/api/gradebooks/recalculate', [
            'course_id' => $course->id,
            'student_profile_id' => $student->id,
        ])->assertOk()
            ->assertJsonPath('data.assignment_marks', '10.00')
            ->assertJsonPath('data.quiz_marks', '6.00')
            ->assertJsonPath('data.total_marks', '16.00')
            ->assertJsonPath('data.maximum_marks', '36.00')
            ->assertJsonPath('data.percentage', '44.44')
            ->assertJsonPath('data.result_status', 'pending');

        $this->assertDatabaseHas('gradebooks', [
            'id' => $gradebook->id,
            'assignment_marks' => 10,
            'quiz_marks' => 6,
            'total_marks' => 16,
            'maximum_marks' => 36,
            'result_status' => 'pending',
        ]);
    }

    public function test_manual_quiz_attempt_stays_pending_until_every_manual_answer_is_graded(): void
    {
        $institution = $this->createInstitution('MANUAL-QUIZ-GRADING');
        [$teacherUser, $teacher] = $this->createTeacher($institution);
        [$studentUser, $student] = $this->createStudent($institution);
        $course = $this->createCourse($institution, $teacher, 'manual-quiz-grading');
        $enrollment = $this->createEnrollment($course, $student);
        $lesson = $this->createLesson($course, 'Manual quiz lesson');

        $quiz = Quiz::create([
            'course_id' => $course->id,
            'lesson_id' => $lesson->id,
            'teacher_profile_id' => $teacher->id,
            'title' => 'Manual grading quiz',
            'total_marks' => 10,
            'passing_marks' => 4,
            'show_result_immediately' => true,
            'status' => 'published',
        ]);

        $shortAnswer = QuestionBank::create([
            'course_id' => $course->id,
            'lesson_id' => $lesson->id,
            'question_text' => 'Explain the first concept.',
            'question_type' => 'short_answer',
            'marks' => 4,
            'status' => 'active',
        ]);
        $longAnswer = QuestionBank::create([
            'course_id' => $course->id,
            'lesson_id' => $lesson->id,
            'question_text' => 'Explain the second concept.',
            'question_type' => 'long_answer',
            'marks' => 4,
            'status' => 'active',
        ]);
        $multipleChoice = QuestionBank::create([
            'course_id' => $course->id,
            'lesson_id' => $lesson->id,
            'question_text' => 'Select the correct option.',
            'question_type' => 'mcq',
            'marks' => 2,
            'status' => 'active',
        ]);

        foreach ([$shortAnswer, $longAnswer, $multipleChoice] as $index => $question) {
            QuizQuestion::create([
                'quiz_id' => $quiz->id,
                'question_bank_id' => $question->id,
                'marks' => $question->marks,
                'sort_order' => $index + 1,
            ]);
        }

        $correctOption = QuestionOption::create([
            'question_bank_id' => $multipleChoice->id,
            'option_text' => 'Correct option',
            'is_correct' => true,
            'sort_order' => 1,
        ]);

        $this->authenticate($studentUser);
        $progressResponse = $this->postJson('/api/lesson-progress', [
            'course_enrollment_id' => $enrollment->id,
            'lesson_id' => $lesson->id,
            'status' => 'in_progress',
            'progress_percentage' => 80,
        ])->assertCreated();
        $progressId = $progressResponse->json('data.id');

        $attemptResponse = $this->postJson('/api/quiz-attempts', [
            'quiz_id' => $quiz->id,
            'student_profile_id' => $student->id,
        ])->assertCreated();
        $attemptId = $attemptResponse->json('data.id');

        $shortAnswerId = $this->postJson('/api/quiz-answers', [
            'quiz_attempt_id' => $attemptId,
            'question_bank_id' => $shortAnswer->id,
            'answer_text' => 'First submitted response',
        ])->assertCreated()->json('data.id');
        $longAnswerId = $this->postJson('/api/quiz-answers', [
            'quiz_attempt_id' => $attemptId,
            'question_bank_id' => $longAnswer->id,
            'answer_text' => 'Second submitted response',
        ])->assertCreated()->json('data.id');
        $multipleChoiceAnswerId = $this->postJson('/api/quiz-answers', [
            'quiz_attempt_id' => $attemptId,
            'question_bank_id' => $multipleChoice->id,
            'question_option_id' => $correctOption->id,
        ])->assertCreated()->json('data.id');

        $this->assertDatabaseHas('quiz_answers', [
            'id' => $multipleChoiceAnswerId,
            'marks_obtained' => 2,
        ]);

        $this->patchJson('/api/quiz-attempts/' . $attemptId, [
            'status' => 'submitted',
        ])->assertOk()->assertJsonPath('data.status', 'submitted');
        $submittedAttempt = QuizAttempt::findOrFail($attemptId);
        $this->assertNotNull($submittedAttempt->submitted_at);
        $this->assertNull($submittedAttempt->evaluated_at);

        $this->authenticate($teacherUser);
        $this->patchJson('/api/quiz-answers/' . $shortAnswerId, [
            'marks_obtained' => 2,
        ])->assertOk();

        $attempt = QuizAttempt::findOrFail($attemptId);
        $this->assertSame('submitted', $attempt->status);
        $this->assertSame('pending', $attempt->result_status);
        $this->assertNull($attempt->evaluated_at);
        $this->assertDatabaseHas('quiz_answers', [
            'id' => $shortAnswerId,
            'marks_obtained' => 2,
        ]);
        $this->assertNotNull(QuizAnswer::findOrFail($shortAnswerId)->manually_graded_at);
        $this->assertNull(QuizAnswer::findOrFail($longAnswerId)->manually_graded_at);

        $this->authenticate($studentUser);
        $this->postJson('/api/lesson-progress/' . $progressId . '/complete')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('assessment');

        $this->authenticate($teacherUser);
        $this->patchJson('/api/quiz-answers/' . $longAnswerId, [
            'marks_obtained' => 0,
        ])->assertOk();

        $attempt->refresh();
        $this->assertSame('evaluated', $attempt->status);
        $this->assertSame('passed', $attempt->result_status);
        $this->assertSame('4.00', $attempt->marks_obtained);
        $this->assertSame('40.00', $attempt->percentage);
        $this->assertNotNull($attempt->evaluated_at);
        $this->assertNotNull(QuizAnswer::findOrFail($longAnswerId)->manually_graded_at);

        $this->authenticate($studentUser);
        $this->postJson('/api/lesson-progress/' . $progressId . '/complete')
            ->assertOk()
            ->assertJsonPath('data.status', 'completed');
    }

    public function test_pending_manual_answers_block_evaluated_evidence_and_can_be_graded(): void
    {
        $institution = $this->createInstitution('UN-GRADED-MANUAL-EVIDENCE');
        [$teacherUser, $teacher] = $this->createTeacher($institution);
        [$studentUser, $student] = $this->createStudent($institution);
        $course = $this->createCourse($institution, $teacher, 'ungraded-manual-evidence');
        $enrollment = $this->createEnrollment($course, $student);
        $lesson = $this->createLesson($course, 'Manual evidence lesson');

        $quiz = Quiz::create([
            'course_id' => $course->id,
            'lesson_id' => $lesson->id,
            'teacher_profile_id' => $teacher->id,
            'title' => 'Manual evidence quiz',
            'total_marks' => 6,
            'passing_marks' => 2,
            'status' => 'published',
        ]);
        $manualQuestion = QuestionBank::create([
            'course_id' => $course->id,
            'lesson_id' => $lesson->id,
            'question_text' => 'Explain this concept.',
            'question_type' => 'short_answer',
            'marks' => 2,
            'status' => 'active',
        ]);
        $secondManualQuestion = QuestionBank::create([
            'course_id' => $course->id,
            'lesson_id' => $lesson->id,
            'question_text' => 'Explain another concept.',
            'question_type' => 'long_answer',
            'marks' => 2,
            'status' => 'active',
        ]);
        $automaticQuestion = QuestionBank::create([
            'course_id' => $course->id,
            'lesson_id' => $lesson->id,
            'question_text' => 'Select the correct option.',
            'question_type' => 'mcq',
            'marks' => 2,
            'status' => 'active',
        ]);

        foreach ([$manualQuestion, $secondManualQuestion, $automaticQuestion] as $index => $question) {
            QuizQuestion::create([
                'quiz_id' => $quiz->id,
                'question_bank_id' => $question->id,
                'marks' => $question->marks,
                'sort_order' => $index + 1,
            ]);
        }

        $attempt = QuizAttempt::create([
            'quiz_id' => $quiz->id,
            'student_profile_id' => $student->id,
            'attempt_number' => 1,
            'started_at' => now()->subMinute(),
            'submitted_at' => now(),
            'total_marks' => 6,
            'marks_obtained' => 2,
            'percentage' => 33.33,
            'result_status' => 'passed',
            'status' => 'evaluated',
        ]);
        $manualAnswer = QuizAnswer::create([
            'quiz_attempt_id' => $attempt->id,
            'question_bank_id' => $manualQuestion->id,
            'answer_text' => 'A submitted manual response',
            'marks_obtained' => 0,
        ]);
        $secondManualAnswer = QuizAnswer::create([
            'quiz_attempt_id' => $attempt->id,
            'question_bank_id' => $secondManualQuestion->id,
            'answer_text' => 'Another submitted manual response',
            'marks_obtained' => 0,
        ]);
        QuizAnswer::create([
            'quiz_attempt_id' => $attempt->id,
            'question_bank_id' => $automaticQuestion->id,
            'marks_obtained' => 2,
            'is_correct' => true,
        ]);

        $this->authenticate($studentUser);
        $progressResponse = $this->postJson('/api/lesson-progress', [
            'course_enrollment_id' => $enrollment->id,
            'lesson_id' => $lesson->id,
            'status' => 'in_progress',
            'progress_percentage' => 80,
        ])->assertCreated();

        $this->postJson('/api/lesson-progress/' . $progressResponse->json('data.id') . '/complete')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('assessment');

        $this->authenticate($teacherUser);
        $this->patchJson('/api/quiz-answers/' . $manualAnswer->id, [
            'marks_obtained' => 2,
        ])->assertOk();

        $attempt->refresh();
        $this->assertSame('submitted', $attempt->status);
        $this->assertSame('pending', $attempt->result_status);

        $this->authenticate($studentUser);
        $this->postJson('/api/lesson-progress/' . $progressResponse->json('data.id') . '/complete')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('assessment');

        $this->authenticate($teacherUser);
        $this->patchJson('/api/quiz-answers/' . $secondManualAnswer->id, [
            'marks_obtained' => 0,
        ])->assertOk();

        $attempt->refresh();
        $this->assertSame('evaluated', $attempt->status);
        $this->assertSame('passed', $attempt->result_status);
        $this->assertNotNull($secondManualAnswer->fresh()->manually_graded_at);

        $this->authenticate($studentUser);
        $this->postJson('/api/lesson-progress/' . $progressResponse->json('data.id') . '/complete')
            ->assertOk()
            ->assertJsonPath('data.status', 'completed');
    }

    public function test_parent_lesson_progress_access_supports_multiple_children_and_blocks_unauthorized_children(): void
    {
        $institution = $this->createInstitution('PROGRESS-MULTI');
        [, $teacher] = $this->createTeacher($institution);

        [, $firstStudent] = $this->createStudent($institution);
        [, $secondStudent] = $this->createStudent($institution);
        [, $unrelatedStudent] = $this->createStudent($institution);
        [, $inactiveStudent] = $this->createStudent($institution);

        $course = $this->createCourse(
            $institution,
            $teacher,
            'progress-multiple-children'
        );

        $firstEnrollment = $this->createEnrollment($course, $firstStudent);
        $secondEnrollment = $this->createEnrollment($course, $secondStudent);
        $unrelatedEnrollment = $this->createEnrollment($course, $unrelatedStudent);
        $inactiveEnrollment = $this->createEnrollment($course, $inactiveStudent);

        $lesson = $this->createLesson($course, 'Shared progress test lesson');

        $firstProgress = $this->createProgress($firstEnrollment, $lesson);
        $secondProgress = $this->createProgress($secondEnrollment, $lesson);
        $unrelatedProgress = $this->createProgress($unrelatedEnrollment, $lesson);
        $inactiveProgress = $this->createProgress($inactiveEnrollment, $lesson);

        $parentUser = $this->createParent($institution, $firstStudent);

        ParentProfile::create([
            'institution_id' => $institution->id,
            'user_id' => $parentUser->id,
            'student_profile_id' => $secondStudent->id,
            'status' => 'active',
        ]);

        ParentProfile::create([
            'institution_id' => $institution->id,
            'user_id' => $parentUser->id,
            'student_profile_id' => $inactiveStudent->id,
            'status' => 'inactive',
        ]);

        $this->authenticate($parentUser);

        $response = $this->getJson('/api/lesson-progress')
            ->assertOk()
            ->assertJsonCount(2, 'data.data');

        $returnedProgressIds = collect($response->json('data.data'))
            ->pluck('id')
            ->all();

        $this->assertEqualsCanonicalizing(
            [$firstProgress->id, $secondProgress->id],
            $returnedProgressIds,
            'The parent must see only progress records for actively linked children.'
        );

        $this->getJson('/api/lesson-progress/' . $firstProgress->id)
            ->assertOk();

        $this->getJson('/api/lesson-progress/' . $secondProgress->id)
            ->assertOk();

        $this->assertForbiddenResponse(
            $this->getJson('/api/lesson-progress/' . $unrelatedProgress->id),
            'parent reading unrelated child lesson progress'
        );

        $this->assertForbiddenResponse(
            $this->getJson('/api/lesson-progress/' . $inactiveProgress->id),
            'parent reading inactive linked child lesson progress'
        );

        $this->assertForbiddenResponse(
            $this->patchJson('/api/lesson-progress/' . $firstProgress->id, [
                'status' => 'in_progress',
            ]),
            'parent updating first linked child lesson progress'
        );

        $this->assertForbiddenResponse(
            $this->patchJson('/api/lesson-progress/' . $secondProgress->id, [
                'status' => 'in_progress',
            ]),
            'parent updating second linked child lesson progress'
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
        $this->getJson('/api/assignment-evaluations/' . $evaluationId)->assertOk();
        $this->assertForbiddenResponse(
            $this->patchJson('/api/assignment-evaluations/' . $evaluationId, [
                'marks_obtained' => 100,
            ]),
            'student updating an evaluation'
        );

        $parentUser = $this->createParent($firstInstitution, $firstStudent);
        $this->authenticate($parentUser);
        $this->getJson('/api/assignment-evaluations/' . $evaluationId)->assertOk();
        $this->assertForbiddenResponse(
            $this->deleteJson('/api/assignment-evaluations/' . $evaluationId),
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
            $this->getJson('/api/assignment-evaluations/' . $secondEvaluation->id),
            'institution admin reading another institution evaluation'
        );
    }

    public function test_parent_assignment_evaluation_access_supports_multiple_children(): void
    {
        $institution = $this->createInstitution('EVAL-PARENT-MULTI');
        $secondInstitution = $this->createInstitution('EVAL-PARENT-CROSS');
        [, $teacher] = $this->createTeacher($institution);
        [, $crossInstitutionTeacher] = $this->createTeacher($secondInstitution);
        [, $firstStudent] = $this->createStudent($institution);
        [, $secondStudent] = $this->createStudent($institution);
        [, $unrelatedStudent] = $this->createStudent($institution);
        [, $inactiveLinkedStudent] = $this->createStudent($institution);
        [, $crossInstitutionStudent] = $this->createStudent($secondInstitution);

        $course = $this->createCourse(
            $institution,
            $teacher,
            'parent-evaluation-access'
        );
        $crossInstitutionCourse = $this->createCourse(
            $secondInstitution,
            $crossInstitutionTeacher,
            'parent-evaluation-cross-institution'
        );

        $this->createEnrollment($course, $firstStudent);
        $this->createEnrollment($course, $secondStudent);
        $this->createEnrollment($course, $unrelatedStudent);
        $this->createEnrollment($course, $inactiveLinkedStudent);
        $this->createEnrollment($crossInstitutionCourse, $crossInstitutionStudent);

        $assignment = $this->createAssignment(
            $course,
            $teacher,
            'Parent evaluation assignment'
        );
        $crossInstitutionAssignment = $this->createAssignment(
            $crossInstitutionCourse,
            $crossInstitutionTeacher,
            'Cross institution parent evaluation assignment'
        );

        $firstEvaluation = $this->createEvaluation(
            $this->createSubmission($assignment, $firstStudent),
            $teacher
        );
        $secondEvaluation = $this->createEvaluation(
            $this->createSubmission($assignment, $secondStudent),
            $teacher
        );
        $unrelatedEvaluation = $this->createEvaluation(
            $this->createSubmission($assignment, $unrelatedStudent),
            $teacher
        );
        $inactiveLinkedEvaluation = $this->createEvaluation(
            $this->createSubmission($assignment, $inactiveLinkedStudent),
            $teacher
        );
        $crossInstitutionEvaluation = $this->createEvaluation(
            $this->createSubmission(
                $crossInstitutionAssignment,
                $crossInstitutionStudent
            ),
            $crossInstitutionTeacher
        );

        $parentUser = $this->createParent($institution, $firstStudent);

        ParentProfile::create([
            'institution_id' => $institution->id,
            'user_id' => $parentUser->id,
            'student_profile_id' => $secondStudent->id,
            'status' => 'active',
        ]);

        ParentProfile::create([
            'institution_id' => $institution->id,
            'user_id' => $parentUser->id,
            'student_profile_id' => $inactiveLinkedStudent->id,
            'status' => 'inactive',
        ]);

        $this->authenticate($parentUser);

        $listedEvaluationIds = $this->getJson('/api/assignment-evaluations')
            ->assertOk()
            ->assertJsonCount(2, 'data.data')
            ->json('data.data.*.id');

        $this->assertEqualsCanonicalizing(
            [$firstEvaluation->id, $secondEvaluation->id],
            $listedEvaluationIds
        );

        $this->getJson('/api/assignment-evaluations/' . $firstEvaluation->id)
            ->assertOk();
        $this->getJson('/api/assignment-evaluations/' . $secondEvaluation->id)
            ->assertOk();

        $this->assertForbiddenResponse(
            $this->getJson('/api/assignment-evaluations/' . $unrelatedEvaluation->id),
            'parent reading unrelated child assignment evaluation'
        );
        $this->assertForbiddenResponse(
            $this->getJson('/api/assignment-evaluations/' . $inactiveLinkedEvaluation->id),
            'parent reading inactive linked child assignment evaluation'
        );
        $this->assertForbiddenResponse(
            $this->getJson('/api/assignment-evaluations/' . $crossInstitutionEvaluation->id),
            'parent reading another institution assignment evaluation'
        );
        $this->assertForbiddenResponse(
            $this->patchJson('/api/assignment-evaluations/' . $firstEvaluation->id, [
                'marks_obtained' => 90,
            ]),
            'parent updating an assignment evaluation'
        );
        $this->assertForbiddenResponse(
            $this->deleteJson('/api/assignment-evaluations/' . $secondEvaluation->id),
            'parent deleting an assignment evaluation'
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

    public function test_inactive_institution_admin_cannot_access_lessons_or_course_enrollments(): void
    {
        $institution = $this->createInstitution('INACTIVE-INSTITUTION-ADMIN');
        [, $teacher] = $this->createTeacher($institution);
        [, $firstStudent] = $this->createStudent($institution);
        [, $secondStudent] = $this->createStudent($institution);
        $adminUser = $this->createInstitutionAdmin($institution);
        $course = $this->createCourse($institution, $teacher, 'inactive-admin-access');
        $lesson = $this->createLesson($course, 'Protected lesson');
        $enrollment = $this->createEnrollment($course, $firstStudent);

        InstitutionUser::where('user_id', $adminUser->id)
            ->update(['status' => 'inactive']);

        $this->authenticate($adminUser);

        $this->getJson('/api/lessons')->assertForbidden();
        $this->getJson('/api/lessons/' . $lesson->id)->assertForbidden();
        $this->postJson('/api/lessons', [
            'course_id' => $course->id,
            'title' => 'Unauthorized lesson',
            'status' => 'draft',
        ])->assertForbidden();
        $this->patchJson('/api/lessons/' . $lesson->id, [
            'title' => 'Unauthorized update',
        ])->assertForbidden();
        $this->deleteJson('/api/lessons/' . $lesson->id)->assertForbidden();

        $this->getJson('/api/course-enrollments')->assertForbidden();
        $this->getJson('/api/course-enrollments/' . $enrollment->id)->assertForbidden();
        $this->postJson('/api/course-enrollments', [
            'course_id' => $course->id,
            'student_profile_id' => $secondStudent->id,
        ])->assertForbidden();
        $this->patchJson('/api/course-enrollments/' . $enrollment->id, [
            'payment_status' => 'paid',
            'amount_paid' => 10,
        ])->assertForbidden();
        $this->deleteJson('/api/course-enrollments/' . $enrollment->id)->assertForbidden();

        $this->assertDatabaseHas('lessons', [
            'id' => $lesson->id,
            'title' => 'Protected lesson',
            'deleted_at' => null,
        ]);
        $this->assertDatabaseHas('course_enrollments', [
            'id' => $enrollment->id,
            'student_profile_id' => $firstStudent->id,
            'deleted_at' => null,
        ]);
        $this->assertDatabaseMissing('course_enrollments', [
            'course_id' => $course->id,
            'student_profile_id' => $secondStudent->id,
        ]);
    }

    public function test_active_institution_admin_and_super_admin_can_access_lessons_and_course_enrollments(): void
    {
        $institution = $this->createInstitution('ACTIVE-INSTITUTION-ADMIN');
        [, $teacher] = $this->createTeacher($institution);
        [, $firstStudent] = $this->createStudent($institution);
        [, $secondStudent] = $this->createStudent($institution);
        $adminUser = $this->createInstitutionAdmin($institution);
        $course = $this->createCourse($institution, $teacher, 'active-admin-access');
        $lesson = $this->createLesson($course, 'Accessible lesson');
        $enrollment = $this->createEnrollment($course, $firstStudent);

        $this->authenticate($adminUser);

        $this->getJson('/api/lessons')->assertOk();
        $this->getJson('/api/lessons/' . $lesson->id)->assertOk();
        $this->getJson('/api/course-enrollments')
            ->assertOk()
            ->assertJsonCount(1, 'data.data');
        $this->getJson('/api/course-enrollments/' . $enrollment->id)->assertOk();

        $this->postJson('/api/lessons', [
            'course_id' => $course->id,
            'title' => 'Created by active admin',
            'status' => 'draft',
        ])->assertCreated();
        $this->patchJson('/api/lessons/' . $lesson->id, [
            'title' => 'Updated by active admin',
        ])->assertOk();
        $this->postJson('/api/course-enrollments', [
            'course_id' => $course->id,
            'student_profile_id' => $secondStudent->id,
        ])->assertCreated();
        $this->patchJson('/api/course-enrollments/' . $enrollment->id, [
            'payment_status' => 'paid',
            'amount_paid' => 10,
        ])->assertOk();

        $this->authenticate($this->createUserWithRole('super-admin'));
        $this->getJson('/api/lessons')->assertOk();
        $this->getJson('/api/course-enrollments')->assertOk();
        $this->patchJson('/api/lessons/' . $lesson->id, [
            'title' => 'Updated by super admin',
        ])->assertOk();
        $this->patchJson('/api/course-enrollments/' . $enrollment->id, [
            'payment_status' => 'paid',
            'amount_paid' => 15,
        ])->assertOk();
    }

    public function test_institution_admin_enrollment_list_and_show_exclude_existing_cross_institution_records(): void
    {
        $firstInstitution = $this->createInstitution('ENROLLMENT-TENANT-A');
        $secondInstitution = $this->createInstitution('ENROLLMENT-TENANT-B');
        [, $firstTeacher] = $this->createTeacher($firstInstitution);
        [, $secondTeacher] = $this->createTeacher($secondInstitution);
        [, $firstStudent] = $this->createStudent($firstInstitution);
        [, $secondStudent] = $this->createStudent($secondInstitution);
        $adminUser = $this->createInstitutionAdmin($firstInstitution);
        $firstCourse = $this->createCourse($firstInstitution, $firstTeacher, 'enrollment-tenant-a');
        $secondCourse = $this->createCourse($secondInstitution, $secondTeacher, 'enrollment-tenant-b');

        $validEnrollment = $this->createEnrollment($firstCourse, $firstStudent);
        $courseScopedCrossTenantEnrollment = $this->createEnrollment(
            $firstCourse,
            $secondStudent
        );
        $studentScopedCrossTenantEnrollment = $this->createEnrollment(
            $secondCourse,
            $firstStudent
        );

        $this->authenticate($adminUser);

        $this->getJson('/api/course-enrollments')
            ->assertOk()
            ->assertJsonCount(1, 'data.data')
            ->assertJsonPath('data.data.0.id', $validEnrollment->id);

        $this->getJson('/api/course-enrollments/' . $validEnrollment->id)->assertOk();
        $this->assertForbiddenResponse(
            $this->getJson('/api/course-enrollments/' . $courseScopedCrossTenantEnrollment->id),
            'institution admin reading enrollment with a foreign student'
        );
        $this->assertForbiddenResponse(
            $this->getJson('/api/course-enrollments/' . $studentScopedCrossTenantEnrollment->id),
            'institution admin reading enrollment for a foreign course'
        );

        $this->authenticate($this->createUserWithRole('super-admin'));
        $this->getJson('/api/course-enrollments')
            ->assertOk()
            ->assertJsonCount(3, 'data.data');
        $this->getJson('/api/course-enrollments/' . $courseScopedCrossTenantEnrollment->id)
            ->assertOk();
    }

    public function test_enrollment_creation_and_update_require_matching_institutions_for_admins_and_super_admins(): void
    {
        $firstInstitution = $this->createInstitution('ENROLLMENT-WRITE-A');
        $secondInstitution = $this->createInstitution('ENROLLMENT-WRITE-B');
        [, $firstTeacher] = $this->createTeacher($firstInstitution);
        [, $secondTeacher] = $this->createTeacher($secondInstitution);
        [, $firstStudent] = $this->createStudent($firstInstitution);
        [, $secondStudent] = $this->createStudent($secondInstitution);
        [, $thirdStudent] = $this->createStudent($firstInstitution);
        [, $fourthStudent] = $this->createStudent($firstInstitution);
        $adminUser = $this->createInstitutionAdmin($firstInstitution);
        $firstCourse = $this->createCourse($firstInstitution, $firstTeacher, 'enrollment-write-a');
        $secondCourse = $this->createCourse($secondInstitution, $secondTeacher, 'enrollment-write-b');
        $enrollment = $this->createEnrollment($firstCourse, $firstStudent);

        $this->authenticate($adminUser);

        $this->postJson('/api/course-enrollments', [
            'course_id' => $firstCourse->id,
            'student_profile_id' => $secondStudent->id,
        ])->assertUnprocessable();
        $this->patchJson('/api/course-enrollments/' . $enrollment->id, [
            'student_profile_id' => $secondStudent->id,
        ])->assertUnprocessable();

        $this->postJson('/api/course-enrollments', [
            'course_id' => $firstCourse->id,
            'student_profile_id' => $thirdStudent->id,
        ])->assertCreated();
        $this->patchJson('/api/course-enrollments/' . $enrollment->id, [
            'payment_status' => 'paid',
            'amount_paid' => 12,
        ])->assertOk();

        $superAdmin = $this->createUserWithRole('super-admin');
        $this->authenticate($superAdmin);

        $this->postJson('/api/course-enrollments', [
            'course_id' => $firstCourse->id,
            'student_profile_id' => $secondStudent->id,
        ])->assertUnprocessable();
        $this->patchJson('/api/course-enrollments/' . $enrollment->id, [
            'course_id' => $secondCourse->id,
        ])->assertUnprocessable();

        $this->assertDatabaseHas('course_enrollments', [
            'id' => $enrollment->id,
            'course_id' => $firstCourse->id,
            'student_profile_id' => $firstStudent->id,
        ]);

        $this->postJson('/api/course-enrollments', [
            'course_id' => $firstCourse->id,
            'student_profile_id' => $fourthStudent->id,
        ])->assertCreated();
        $this->patchJson('/api/course-enrollments/' . $enrollment->id, [
            'payment_status' => 'paid',
            'amount_paid' => 15,
        ])->assertOk();
    }

    private function failCertificateFilePathPersistence(): void
    {
        DB::statement(
            "CREATE TRIGGER fail_certificate_file_path_update " .
            "BEFORE UPDATE OF certificate_file ON certificates " .
            "BEGIN SELECT RAISE(ABORT, 'simulated certificate path persistence failure'); END"
        );
    }

    private function createInstitution(string $code): Institution
    {
        return Institution::create([
            'name' => 'Institution ' . $code,
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
            $context . ': ' . $response->getContent()
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

    private function createPassingGradebook(
        Course $course,
        StudentProfile $studentProfile
    ): Gradebook {
        return Gradebook::create([
            'course_id' => $course->id,
            'student_profile_id' => $studentProfile->id,
            'assignment_marks' => 80,
            'quiz_marks' => 75,
            'total_marks' => 155,
            'maximum_marks' => 200,
            'percentage' => 77.5,
            'grade' => 'B',
            'result_status' => 'passed',
        ]);
    }

    private function createPassingQuizAttempt(Quiz $quiz, StudentProfile $studentProfile): QuizAttempt
    {
        return QuizAttempt::create([
            'quiz_id' => $quiz->id,
            'student_profile_id' => $studentProfile->id,
            'attempt_number' => 1,
            'started_at' => now()->subMinutes(10),
            'submitted_at' => now()->subMinutes(5),
            'evaluated_at' => now()->subMinutes(4),
            'total_marks' => 16,
            'marks_obtained' => 12,
            'percentage' => 75,
            'result_status' => 'passed',
            'status' => 'evaluated',
        ]);
    }

    private function createCertificateSetting(Institution $institution): CertificateSetting
    {
        return CertificateSetting::create([
            'institution_id' => $institution->id,
            'certificate_title' => 'Certificate of Completion',
            'authorized_person_name' => 'Academic Director',
            'authorized_person_designation' => 'Director',
            'secondary_signatory_name' => 'Registrar',
            'secondary_signatory_designation' => 'Registrar',
            'status' => 'active',
        ]);
    }

    private function certificateSnapshot(Certificate $certificate): array
    {
        return [
            'final_percentage' => (string) $certificate->final_percentage,
            'final_grade' => $certificate->final_grade,
            'issued_date' => $certificate->issued_date?->toDateString(),
            'certificate_number' => $certificate->certificate_number,
            'certificate_uuid' => $certificate->certificate_uuid,
            'verification_token' => $certificate->verification_token,
        ];
    }

    private function createCertificateReconciliationFixture(string $suffix): array
    {
        $fixture = $this->createQuizResultVisibilityFixture($suffix, false);
        $attempt = $this->createPassingQuizAttempt(
            $fixture['quiz'],
            $fixture['student']
        );
        $gradebook = Gradebook::create([
            'course_id' => $fixture['quiz']->course_id,
            'student_profile_id' => $fixture['student']->id,
            'assignment_marks' => 0,
            'quiz_marks' => 12,
            'total_marks' => 12,
            'maximum_marks' => 16,
            'percentage' => 75,
            'grade' => 'B+',
            'result_status' => 'passed',
        ]);
        $certificate = $this->createCertificate(
            $fixture['quiz']->course,
            $fixture['student'],
            'CERT-' . strtoupper($suffix) . '-001'
        );
        $certificate->update([
            'gradebook_id' => $gradebook->id,
            'final_percentage' => 75,
            'final_grade' => 'B+',
        ]);

        return array_merge($fixture, [
            'attempt' => $attempt,
            'certificate' => $certificate->fresh(),
            'gradebook' => $gradebook,
            'institution_id' => (int) $fixture['quiz']->course->institution_id,
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

    public function test_student_learning_access_is_scoped_to_enrolled_published_course_content(): void
    {
        $institution = $this->createInstitution('LEARNING-ACCESS');

        [$studentUser, $student] = $this->createStudent($institution);
        [, $otherStudent] = $this->createStudent($institution);
        [, $teacher] = $this->createTeacher($institution);

        $enrolledCourse = $this->createCourse(
            $institution,
            $teacher,
            'student-learning-course'
        );

        $otherCourse = $this->createCourse(
            $institution,
            $teacher,
            'student-other-course'
        );

        $this->createEnrollment($enrolledCourse, $student);

        $this->createEnrollment($otherCourse, $otherStudent);

        $publishedLesson = $this->createLesson(
            $enrolledCourse,
            'Published lesson'
        );

        $otherCourseLesson = $this->createLesson(
            $otherCourse,
            'Other course lesson'
        );

        $draftLesson = $this->createLesson(
            $enrolledCourse,
            'Draft lesson'
        );

        $draftLesson->update([
            'status' => 'draft',
        ]);

        $this->authenticate($studentUser);

        // Student can list lessons from an enrolled course.
        $this->getJson(
            '/api/student-courses/' . $enrolledCourse->id . '/lessons'
        )
            ->assertOk()
            ->assertJsonFragment([
                'id' => $publishedLesson->id,
            ])
            ->assertJsonMissing([
                'id' => $draftLesson->id,
            ]);

        // Student can view an individual published lesson
        // from the enrolled course.
        $this->getJson(
            '/api/student-courses/' .
                $enrolledCourse->id .
                '/lessons/' .
                $publishedLesson->id
        )
            ->assertOk()
            ->assertJsonPath('data.id', $publishedLesson->id);

        // Student cannot access a course in which they are not enrolled.
        $this->assertForbiddenResponse(
            $this->getJson(
                '/api/student-courses/' . $otherCourse->id . '/lessons'
            ),
            'student accessing an unenrolled course'
        );

        // Student cannot access another course's lesson,
        // even if the lesson ID is known.
        $this->getJson(
            '/api/student-courses/' .
                $enrolledCourse->id .
                '/lessons/' .
                $otherCourseLesson->id
        )
            ->assertNotFound();

        // Unpublished lessons must not be exposed.
        $this->getJson(
            '/api/student-courses/' .
                $enrolledCourse->id .
                '/lessons/' .
                $draftLesson->id
        )
            ->assertNotFound();
    }

    public function test_student_lesson_resources_are_scoped_to_enrolled_published_lessons(): void
    {
        $institution = $this->createInstitution('RESOURCE-ACCESS');

        [$studentUser, $student] = $this->createStudent($institution);
        [, $otherStudent] = $this->createStudent($institution);
        [, $teacher] = $this->createTeacher($institution);

        $enrolledCourse = $this->createCourse(
            $institution,
            $teacher,
            'student-resource-course'
        );

        $otherCourse = $this->createCourse(
            $institution,
            $teacher,
            'student-other-resource-course'
        );

        $this->createEnrollment($enrolledCourse, $student);
        $this->createEnrollment($otherCourse, $otherStudent);

        $publishedLesson = $this->createLesson(
            $enrolledCourse,
            'Published resource lesson'
        );

        $otherCourseLesson = $this->createLesson(
            $otherCourse,
            'Other course resource lesson'
        );

        $draftLesson = $this->createLesson(
            $enrolledCourse,
            'Draft resource lesson'
        );

        $draftLesson->update([
            'status' => 'draft',
        ]);

        $publishedResource = \App\Models\LessonResource::create([
            'lesson_id' => $publishedLesson->id,
            'title' => 'Published PDF',
            'resource_type' => 'pdf',
            'content' => 'Published resource content',
            'sort_order' => 1,
            'status' => 'active',
        ]);

        $inactiveResource = \App\Models\LessonResource::create([
            'lesson_id' => $publishedLesson->id,
            'title' => 'Inactive PDF',
            'resource_type' => 'pdf',
            'content' => 'Inactive resource content',
            'sort_order' => 2,
            'status' => 'inactive',
        ]);

        $otherCourseResource = \App\Models\LessonResource::create([
            'lesson_id' => $otherCourseLesson->id,
            'title' => 'Other course PDF',
            'resource_type' => 'pdf',
            'content' => 'Other course resource content',
            'sort_order' => 1,
            'status' => 'active',
        ]);

        $draftLessonResource = \App\Models\LessonResource::create([
            'lesson_id' => $draftLesson->id,
            'title' => 'Draft lesson PDF',
            'resource_type' => 'pdf',
            'content' => 'Draft lesson resource content',
            'sort_order' => 1,
            'status' => 'active',
        ]);

        $this->authenticate($studentUser);

        // Student can access resources for a published lesson
        // from a course in which they are enrolled.
        $this->getJson(
            '/api/student-courses/' .
                $enrolledCourse->id .
                '/lessons/' .
                $publishedLesson->id .
                '/resources'
        )
            ->assertOk()
            ->assertJsonFragment([
                'id' => $publishedResource->id,
            ])
            ->assertJsonMissing([
                'id' => $inactiveResource->id,
            ]);

        // Student cannot access resources from an unenrolled course.
        $this->assertForbiddenResponse(
            $this->getJson(
                '/api/student-courses/' .
                    $otherCourse->id .
                    '/lessons/' .
                    $otherCourseLesson->id .
                    '/resources'
            ),
            'student accessing resources from an unenrolled course'
        );

        // Student cannot access another course's lesson,
        // even if the lesson ID is known.
        $this->getJson(
            '/api/student-courses/' .
                $enrolledCourse->id .
                '/lessons/' .
                $otherCourseLesson->id .
                '/resources'
        )
            ->assertNotFound();

        // Resources from unpublished lessons must not be exposed.
        $this->getJson(
            '/api/student-courses/' .
                $enrolledCourse->id .
                '/lessons/' .
                $draftLesson->id .
                '/resources'
        )
            ->assertNotFound();

        // Keep the variable explicitly used so the test documents
        // that an active resource exists on the draft lesson.
        $this->assertDatabaseHas('lesson_resources', [
            'id' => $draftLessonResource->id,
            'status' => 'active',
        ]);
    }

    public function test_legacy_cross_institution_enrollments_are_hidden_and_denied_to_students(): void
    {
        $firstInstitution = $this->createInstitution('LEGACY-ENROLLMENT-A');
        $secondInstitution = $this->createInstitution('LEGACY-ENROLLMENT-B');
        [, $firstTeacher] = $this->createTeacher($firstInstitution);
        [, $secondTeacher] = $this->createTeacher($secondInstitution);
        [$studentUser, $student] = $this->createStudent($firstInstitution);

        $firstCourse = $this->createCourse(
            $firstInstitution,
            $firstTeacher,
            'legacy-enrollment-valid-course'
        );
        $secondCourse = $this->createCourse(
            $secondInstitution,
            $secondTeacher,
            'legacy-enrollment-foreign-course'
        );
        $firstLesson = $this->createLesson($firstCourse, 'Valid lesson');
        $foreignLesson = $this->createLesson($secondCourse, 'Foreign lesson');
        $foreignProgressLesson = $this->createLesson(
            $secondCourse,
            'Foreign progress mutation lesson'
        );

        $validEnrollment = $this->createEnrollment($firstCourse, $student);
        $legacyEnrollment = $this->createEnrollment($secondCourse, $student);
        $validProgress = $this->createProgress($validEnrollment, $firstLesson);
        $this->createProgress($legacyEnrollment, $foreignLesson);

        $firstQuiz = Quiz::create([
            'course_id' => $firstCourse->id,
            'teacher_profile_id' => $firstTeacher->id,
            'title' => 'Valid course quiz',
            'total_marks' => 1,
            'passing_marks' => 1,
            'status' => 'published',
        ]);
        $foreignQuiz = Quiz::create([
            'course_id' => $secondCourse->id,
            'teacher_profile_id' => $secondTeacher->id,
            'title' => 'Foreign course quiz',
            'total_marks' => 1,
            'passing_marks' => 1,
            'status' => 'published',
        ]);
        $validAttempt = QuizAttempt::create([
            'quiz_id' => $firstQuiz->id,
            'student_profile_id' => $student->id,
            'attempt_number' => 1,
            'started_at' => now()->subMinute(),
            'total_marks' => 1,
            'marks_obtained' => 0,
            'percentage' => 0,
            'result_status' => 'pending',
            'status' => 'in_progress',
        ]);
        QuizAttempt::create([
            'quiz_id' => $foreignQuiz->id,
            'student_profile_id' => $student->id,
            'attempt_number' => 1,
            'started_at' => now()->subMinute(),
            'submitted_at' => now(),
            'total_marks' => 1,
            'marks_obtained' => 1,
            'percentage' => 100,
            'result_status' => 'passed',
            'status' => 'evaluated',
        ]);

        $validAssignment = $this->createAssignment(
            $firstCourse,
            $firstTeacher,
            'Valid course assignment'
        );
        $foreignAssignment = $this->createAssignment(
            $secondCourse,
            $secondTeacher,
            'Foreign course assignment'
        );
        $secondForeignAssignment = $this->createAssignment(
            $secondCourse,
            $secondTeacher,
            'Foreign course assignment for mutation'
        );
        $validSubmission = $this->createSubmission($validAssignment, $student);
        $this->createSubmission($foreignAssignment, $student);

        $this->authenticate($studentUser);

        $this->getJson('/api/student-dashboard/' . $student->id)
            ->assertOk()
            ->assertJsonCount(1, 'data.enrolled_courses')
            ->assertJsonPath('data.enrolled_courses.0.id', $validEnrollment->id);
        $this->getJson('/api/lesson-progress')
            ->assertOk()
            ->assertJsonCount(1, 'data.data')
            ->assertJsonPath('data.data.0.id', $validProgress->id);
        $this->getJson('/api/quiz-attempts')
            ->assertOk()
            ->assertJsonCount(1, 'data.data')
            ->assertJsonPath('data.data.0.id', $validAttempt->id);
        $this->getJson('/api/assignment-submissions')
            ->assertOk()
            ->assertJsonCount(1, 'data.data')
            ->assertJsonPath('data.data.0.id', $validSubmission->id);

        $this->assertForbiddenResponse(
            $this->getJson('/api/student-courses/' . $secondCourse->id . '/lessons'),
            'student listing lessons for a course with a legacy mismatched enrollment'
        );
        $this->assertForbiddenResponse(
            $this->getJson(
                '/api/student-courses/' .
                    $secondCourse->id .
                    '/lessons/' .
                    $foreignLesson->id
            ),
            'student reading a lesson for a course with a legacy mismatched enrollment'
        );
        $this->assertForbiddenResponse(
            $this->postJson('/api/lesson-progress', [
                'course_enrollment_id' => $legacyEnrollment->id,
                'lesson_id' => $foreignProgressLesson->id,
                'status' => 'in_progress',
                'progress_percentage' => 20,
            ]),
            'student creating progress for a legacy mismatched enrollment'
        );
        $this->assertForbiddenResponse(
            $this->postJson('/api/quiz-attempts', [
                'quiz_id' => $foreignQuiz->id,
                'student_profile_id' => $student->id,
            ]),
            'student creating a quiz attempt through a legacy mismatched enrollment'
        );
        $this->assertForbiddenResponse(
            $this->postJson('/api/assignment-submissions', [
                'assignment_id' => $secondForeignAssignment->id,
                'student_profile_id' => $student->id,
                'submission_text' => 'A response for the foreign course.',
                'status' => 'submitted',
            ]),
            'student submitting an assignment through a legacy mismatched enrollment'
        );
    }

    public function test_assignment_creation_rejects_cross_course_relationships_and_keeps_null_relationships_optional(): void
    {
        $institution = $this->createInstitution('ASSIGNMENT-CREATE-RELATIONS');
        [$teacherUser, $teacher] = $this->createTeacher($institution);
        $sourceCourse = $this->createCourse($institution, $teacher, 'assignment-create-source');
        $otherCourse = $this->createCourse($institution, $teacher, 'assignment-create-other');
        $sourceSection = CourseSection::create([
            'course_id' => $sourceCourse->id,
            'title' => 'Source section',
            'sort_order' => 1,
            'status' => 'active',
        ]);
        $otherSection = CourseSection::create([
            'course_id' => $otherCourse->id,
            'title' => 'Other section',
            'sort_order' => 1,
            'status' => 'active',
        ]);
        $sourceLesson = $this->createLesson($sourceCourse, 'Source lesson');
        $otherLesson = $this->createLesson($otherCourse, 'Other lesson');

        $this->authenticate($teacherUser);

        $this->postJson('/api/assignments', [
            'course_id' => $sourceCourse->id,
            'lesson_id' => $otherLesson->id,
            'title' => 'Cross-course lesson assignment',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('lesson_id');

        $this->postJson('/api/assignments', [
            'course_id' => $sourceCourse->id,
            'course_section_id' => $otherSection->id,
            'title' => 'Cross-course section assignment',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('course_section_id');

        $this->postJson('/api/assignments', [
            'course_id' => $sourceCourse->id,
            'course_section_id' => $sourceSection->id,
            'lesson_id' => $sourceLesson->id,
            'title' => 'Valid linked assignment',
        ])->assertCreated()
            ->assertJsonPath('data.course_section_id', $sourceSection->id)
            ->assertJsonPath('data.lesson_id', $sourceLesson->id);

        $this->postJson('/api/assignments', [
            'course_id' => $sourceCourse->id,
            'course_section_id' => null,
            'lesson_id' => null,
            'title' => 'Unlinked assignment',
        ])->assertCreated()
            ->assertJsonPath('data.course_section_id', null)
            ->assertJsonPath('data.lesson_id', null);
    }

    public function test_assignment_updates_validate_effective_course_and_reject_invalid_changes_atomically(): void
    {
        $institution = $this->createInstitution('ASSIGNMENT-UPDATE-RELATIONS');
        [$teacherUser, $teacher] = $this->createTeacher($institution);
        $sourceCourse = $this->createCourse($institution, $teacher, 'assignment-update-source');
        $targetCourse = $this->createCourse($institution, $teacher, 'assignment-update-target');
        $sourceSection = CourseSection::create([
            'course_id' => $sourceCourse->id,
            'title' => 'Source section',
            'sort_order' => 1,
            'status' => 'active',
        ]);
        $targetSection = CourseSection::create([
            'course_id' => $targetCourse->id,
            'title' => 'Target section',
            'sort_order' => 1,
            'status' => 'active',
        ]);
        $sourceLesson = $this->createLesson($sourceCourse, 'Source lesson');
        $targetLesson = $this->createLesson($targetCourse, 'Target lesson');

        $this->authenticate($teacherUser);
        $assignmentId = $this->postJson('/api/assignments', [
            'course_id' => $sourceCourse->id,
            'course_section_id' => $sourceSection->id,
            'lesson_id' => $sourceLesson->id,
            'title' => 'Existing assignment',
        ])->assertCreated()->json('data.id');

        $this->patchJson('/api/assignments/' . $assignmentId, [
            'title' => 'Valid partial update',
        ])->assertOk()
            ->assertJsonPath('data.course_section_id', $sourceSection->id)
            ->assertJsonPath('data.lesson_id', $sourceLesson->id);

        $this->patchJson('/api/assignments/' . $assignmentId, [
            'title' => 'Must not persist after invalid lesson',
            'lesson_id' => $targetLesson->id,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('lesson_id');
        $this->assertDatabaseHas('assignments', [
            'id' => $assignmentId,
            'course_id' => $sourceCourse->id,
            'course_section_id' => $sourceSection->id,
            'lesson_id' => $sourceLesson->id,
            'title' => 'Valid partial update',
        ]);

        $this->patchJson('/api/assignments/' . $assignmentId, [
            'title' => 'Must not persist after invalid section',
            'course_section_id' => $targetSection->id,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('course_section_id');
        $this->assertDatabaseHas('assignments', [
            'id' => $assignmentId,
            'course_id' => $sourceCourse->id,
            'course_section_id' => $sourceSection->id,
            'lesson_id' => $sourceLesson->id,
            'title' => 'Valid partial update',
        ]);

        $this->patchJson('/api/assignments/' . $assignmentId, [
            'title' => 'Must not persist after course move',
            'course_id' => $targetCourse->id,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['course_section_id', 'lesson_id']);
        $this->assertDatabaseHas('assignments', [
            'id' => $assignmentId,
            'course_id' => $sourceCourse->id,
            'course_section_id' => $sourceSection->id,
            'lesson_id' => $sourceLesson->id,
            'title' => 'Valid partial update',
        ]);

        $this->patchJson('/api/assignments/' . $assignmentId, [
            'course_id' => $targetCourse->id,
            'lesson_id' => $targetLesson->id,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('course_section_id');
        $this->assertDatabaseHas('assignments', [
            'id' => $assignmentId,
            'course_id' => $sourceCourse->id,
            'course_section_id' => $sourceSection->id,
            'lesson_id' => $sourceLesson->id,
            'title' => 'Valid partial update',
        ]);

        $this->patchJson('/api/assignments/' . $assignmentId, [
            'course_id' => $targetCourse->id,
            'course_section_id' => $targetSection->id,
            'lesson_id' => $targetLesson->id,
        ])->assertOk()
            ->assertJsonPath('data.course_id', $targetCourse->id)
            ->assertJsonPath('data.course_section_id', $targetSection->id)
            ->assertJsonPath('data.lesson_id', $targetLesson->id);

        $this->patchJson('/api/assignments/' . $assignmentId, [
            'course_section_id' => null,
            'lesson_id' => null,
        ])->assertOk()
            ->assertJsonPath('data.course_section_id', null)
            ->assertJsonPath('data.lesson_id', null);
    }

    public function test_institution_admin_assignment_access_is_tenant_scoped_and_super_admin_access_is_preserved(): void
    {
        $firstInstitution = $this->createInstitution('ASSIGNMENT-AUTH-A');
        $secondInstitution = $this->createInstitution('ASSIGNMENT-AUTH-B');
        [, $firstTeacher] = $this->createTeacher($firstInstitution);
        [$secondTeacherUser, $secondTeacher] = $this->createTeacher($secondInstitution);
        $adminUser = $this->createInstitutionAdmin($firstInstitution);
        $firstCourse = $this->createCourse($firstInstitution, $firstTeacher, 'assignment-auth-a');
        $secondCourse = $this->createCourse($secondInstitution, $secondTeacher, 'assignment-auth-b');
        $localAssignment = $this->createAssignment(
            $firstCourse,
            $firstTeacher,
            'Local assignment'
        );
        $foreignAssignment = $this->createAssignment(
            $secondCourse,
            $secondTeacher,
            'Foreign assignment'
        );

        $this->authenticate($adminUser);

        $this->getJson('/api/assignments')
            ->assertOk()
            ->assertJsonFragment(['id' => $localAssignment->id])
            ->assertJsonMissingExact(['id' => $foreignAssignment->id]);
        $this->assertForbiddenResponse(
            $this->getJson('/api/assignments/' . $foreignAssignment->id),
            'institution admin reading a foreign assignment'
        );
        $this->assertForbiddenResponse(
            $this->patchJson('/api/assignments/' . $foreignAssignment->id, [
                'title' => 'Unauthorized changed title',
            ]),
            'institution admin updating a foreign assignment'
        );
        $this->assertForbiddenResponse(
            $this->deleteJson('/api/assignments/' . $foreignAssignment->id),
            'institution admin deleting a foreign assignment'
        );
        $this->assertDatabaseHas('assignments', [
            'id' => $foreignAssignment->id,
            'title' => 'Foreign assignment',
            'deleted_at' => null,
        ]);

        $this->getJson('/api/assignments/' . $localAssignment->id)->assertOk();
        $this->patchJson('/api/assignments/' . $localAssignment->id, [
            'title' => 'Updated local assignment',
        ])->assertOk();
        $this->deleteJson('/api/assignments/' . $localAssignment->id)->assertOk();

        $this->authenticate($secondTeacherUser);
        $this->getJson('/api/assignments/' . $foreignAssignment->id)->assertOk();
        $this->patchJson('/api/assignments/' . $foreignAssignment->id, [
            'title' => 'Updated by course teacher',
        ])->assertOk();

        $this->authenticate($this->createUserWithRole('super-admin'));
        $this->getJson('/api/assignments/' . $foreignAssignment->id)->assertOk();
        $this->patchJson('/api/assignments/' . $foreignAssignment->id, [
            'title' => 'Updated by super admin',
        ])->assertOk();
    }

    public function test_inactive_institution_admin_cannot_read_or_grade_assessments(): void
    {
        $institution = $this->createInstitution('INACTIVE-ASSESSMENT-ADMIN');
        [$teacherUser, $teacher] = $this->createTeacher($institution);
        [, $student] = $this->createStudent($institution);
        $adminUser = $this->createInstitutionAdmin($institution);
        $course = $this->createCourse($institution, $teacher, 'inactive-assessment-admin');
        $lesson = $this->createLesson($course, 'Protected assessment lesson');
        $assignment = $this->createAssignment($course, $teacher, 'Protected assignment');
        $submission = $this->createSubmission($assignment, $student);
        $quiz = Quiz::create([
            'course_id' => $course->id,
            'lesson_id' => $lesson->id,
            'teacher_profile_id' => $teacher->id,
            'title' => 'Protected manual quiz',
            'total_marks' => 1,
            'passing_marks' => 1,
            'status' => 'published',
        ]);
        $manualQuestion = QuestionBank::create([
            'course_id' => $course->id,
            'lesson_id' => $lesson->id,
            'question_text' => 'Explain the answer.',
            'question_type' => 'short_answer',
            'marks' => 1,
            'status' => 'active',
        ]);
        QuizQuestion::create([
            'quiz_id' => $quiz->id,
            'question_bank_id' => $manualQuestion->id,
            'marks' => 1,
            'sort_order' => 1,
        ]);
        $attempt = QuizAttempt::create([
            'quiz_id' => $quiz->id,
            'student_profile_id' => $student->id,
            'attempt_number' => 1,
            'started_at' => now()->subMinute(),
            'submitted_at' => now(),
            'total_marks' => 1,
            'marks_obtained' => 0,
            'percentage' => 0,
            'result_status' => 'pending',
            'status' => 'submitted',
        ]);
        $answer = QuizAnswer::create([
            'quiz_attempt_id' => $attempt->id,
            'question_bank_id' => $manualQuestion->id,
            'answer_text' => 'Student response.',
            'marks_obtained' => 0,
        ]);

        InstitutionUser::where('user_id', $adminUser->id)
            ->update(['status' => 'inactive']);
        $this->authenticate($adminUser);

        $this->assertForbiddenResponse($this->getJson('/api/assignments'), 'inactive admin listing assignments');
        $this->assertForbiddenResponse(
            $this->getJson('/api/assignments/' . $assignment->id),
            'inactive admin reading an assignment'
        );
        $this->assertForbiddenResponse(
            $this->patchJson('/api/assignments/' . $assignment->id, [
                'title' => 'Unauthorized assignment update',
            ]),
            'inactive admin updating an assignment'
        );
        $this->assertForbiddenResponse(
            $this->deleteJson('/api/assignments/' . $assignment->id),
            'inactive admin deleting an assignment'
        );
        $this->assertForbiddenResponse($this->getJson('/api/quizzes'), 'inactive admin listing quizzes');
        $this->assertForbiddenResponse(
            $this->getJson('/api/quizzes/' . $quiz->id),
            'inactive admin reading a quiz'
        );
        $this->assertForbiddenResponse(
            $this->patchJson('/api/quizzes/' . $quiz->id, ['title' => 'Unauthorized update']),
            'inactive admin updating a quiz'
        );
        $this->assertForbiddenResponse($this->getJson('/api/quiz-attempts'), 'inactive admin listing attempts');
        $this->assertForbiddenResponse(
            $this->getJson('/api/quiz-attempts/' . $attempt->id),
            'inactive admin reading a quiz attempt'
        );
        $this->assertForbiddenResponse(
            $this->deleteJson('/api/quiz-attempts/' . $attempt->id),
            'inactive admin deleting a quiz attempt'
        );
        $this->assertForbiddenResponse($this->getJson('/api/quiz-answers'), 'inactive admin listing quiz answers');
        $this->assertForbiddenResponse(
            $this->patchJson('/api/quiz-answers/' . $answer->id, ['marks_obtained' => 1]),
            'inactive admin grading a manual quiz answer'
        );
        $this->assertForbiddenResponse(
            $this->getJson('/api/assignment-submissions'),
            'inactive admin listing assignment submissions'
        );
        $this->assertForbiddenResponse(
            $this->getJson('/api/assignment-submissions/' . $submission->id),
            'inactive admin reading an assignment submission'
        );

        $this->assertNull($answer->fresh()->manually_graded_at);

        InstitutionUser::where('user_id', $adminUser->id)
            ->update(['status' => 'active']);
        $this->authenticate($adminUser);

        $this->getJson('/api/assignments')->assertOk();
        $this->getJson('/api/quizzes')->assertOk();
        $this->getJson('/api/quiz-attempts')->assertOk();
        $this->getJson('/api/quiz-answers')->assertOk();
        $this->getJson('/api/assignment-submissions')->assertOk();
        $this->patchJson('/api/quiz-answers/' . $answer->id, [
            'marks_obtained' => 1,
        ])->assertOk();
        $this->assertNotNull($answer->fresh()->manually_graded_at);
    }

    private function createEvaluation(
        AssignmentSubmission $submission,
        TeacherProfile $teacherProfile,
        float $marksObtained = 80
    ): AssignmentEvaluation {
        return AssignmentEvaluation::create([
            'assignment_submission_id' => $submission->id,
            'teacher_profile_id' => $teacherProfile->id,
            'marks_obtained' => $marksObtained,
            'maximum_marks' => 100,
            'result_status' => 'passed',
            'evaluated_at' => now(),
        ]);
    }

    private function assertPendingCertificatePayload(array $certificate): void
    {
        $this->assertTrue($certificate['grading_pending']);
        $this->assertSame('pending', $certificate['status']);
        $this->assertSame('pending', $certificate['verification_status']);

        foreach ([
            'final_percentage',
            'final_grade',
            'certificate_file',
            'certificate_file_url',
        ] as $hiddenField) {
            $this->assertArrayNotHasKey($hiddenField, $certificate);
        }

        if (isset($certificate['gradebook'])) {
            $this->assertSame('pending', $certificate['gradebook']['result_status']);

            foreach ([
                'assignment_marks',
                'quiz_marks',
                'total_marks',
                'maximum_marks',
                'percentage',
                'grade',
            ] as $hiddenField) {
                $this->assertArrayNotHasKey($hiddenField, $certificate['gradebook']);
            }
        }
    }

    private function createQuizResultVisibilityFixture(
        string $suffix,
        bool $showResultImmediately
    ): array {
        $institution = $this->createInstitution('QUIZ-VISIBILITY-' . $suffix);
        [$teacherUser, $teacher] = $this->createTeacher($institution);
        [$studentUser, $student] = $this->createStudent($institution);
        $course = $this->createCourse(
            $institution,
            $teacher,
            'quiz-result-visibility-' . strtolower($suffix)
        );
        $this->createEnrollment($course, $student);

        $quiz = Quiz::create([
            'course_id' => $course->id,
            'teacher_profile_id' => $teacher->id,
            'title' => 'Quiz result visibility ' . $suffix,
            'total_marks' => 16,
            'passing_marks' => 8,
            'show_result_immediately' => $showResultImmediately,
            'status' => 'published',
        ]);

        $manualQuestions = [];
        foreach (['short_answer', 'long_answer', 'fill_blank'] as $index => $type) {
            $question = QuestionBank::create([
                'course_id' => $course->id,
                'question_text' => 'Manual ' . str_replace('_', ' ', $type) . ' question.',
                'question_type' => $type,
                'marks' => 5,
                'explanation' => 'Study guidance: organize evidence before answering.',
                'status' => 'active',
            ]);
            $manualQuestions[$type] = $question;
            QuizQuestion::create([
                'quiz_id' => $quiz->id,
                'question_bank_id' => $question->id,
                'marks' => 5,
                'sort_order' => $index + 1,
            ]);
        }

        $automaticQuestion = QuestionBank::create([
            'course_id' => $course->id,
            'question_text' => 'Select the correct option.',
            'question_type' => 'mcq',
            'marks' => 1,
            'explanation' => 'Study guidance for the automatic question.',
            'status' => 'active',
        ]);
        QuizQuestion::create([
            'quiz_id' => $quiz->id,
            'question_bank_id' => $automaticQuestion->id,
            'marks' => 1,
            'sort_order' => 4,
        ]);
        $correctOption = QuestionOption::create([
            'question_bank_id' => $automaticQuestion->id,
            'option_text' => 'Correct option',
            'is_correct' => true,
            'sort_order' => 1,
        ]);

        return [
            'teacher_user' => $teacherUser,
            'student_user' => $studentUser,
            'student' => $student,
            'quiz' => $quiz,
            'manual_questions' => $manualQuestions,
            'automatic_question' => $automaticQuestion,
            'correct_option' => $correctOption,
        ];
    }

    private function assertPendingQuizAnswerPayload(array $answer): void
    {
        $this->assertArrayNotHasKey('is_correct', $answer);
        $this->assertArrayNotHasKey('marks_obtained', $answer);
        $this->assertArrayNotHasKey('manually_graded_at', $answer);

        $attempt = $answer['quiz_attempt'];
        $this->assertPendingQuizAttemptPayload($attempt);

        if (isset($answer['question_option'])) {
            $this->assertArrayNotHasKey('is_correct', $answer['question_option']);
        }

        foreach ($answer['question_bank']['options'] ?? [] as $option) {
            $this->assertArrayNotHasKey('is_correct', $option);
        }
    }

    private function assertPendingQuizAttemptPayload(array $attempt): void
    {
        $this->assertContains($attempt['status'], ['in_progress', 'submitted']);
        $this->assertArrayNotHasKey('marks_obtained', $attempt);
        $this->assertArrayNotHasKey('percentage', $attempt);
        $this->assertArrayNotHasKey('evaluated_at', $attempt);
        $this->assertArrayNotHasKey('has_pending_manual_answers', $attempt);

        if ($attempt['status'] === 'in_progress') {
            $this->assertArrayNotHasKey('result_status', $attempt);

            return;
        }

        $this->assertSame('pending', $attempt['result_status']);
    }

    private function assertInProgressQuizAttemptResultsAreHidden(array $attempt): void
    {
        $this->assertSame('in_progress', $attempt['status']);

        foreach ([
            'marks_obtained',
            'percentage',
            'result_status',
            'evaluated_at',
        ] as $hiddenField) {
            $this->assertArrayNotHasKey($hiddenField, $attempt);
        }
    }

    private function assertPendingGradebookPayload(array $gradebook): void
    {
        $this->assertSame('pending', $gradebook['result_status']);
        $this->assertArrayNotHasKey('assignment_marks', $gradebook);
        $this->assertArrayNotHasKey('quiz_marks', $gradebook);
        $this->assertArrayNotHasKey('total_marks', $gradebook);
        $this->assertArrayNotHasKey('maximum_marks', $gradebook);
        $this->assertArrayNotHasKey('percentage', $gradebook);
        $this->assertArrayNotHasKey('grade', $gradebook);
        $this->assertArrayNotHasKey('has_pending_manual_grading', $gradebook);
    }
}
