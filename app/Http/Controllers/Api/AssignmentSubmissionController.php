<?php

namespace App\Http\Controllers\Api;

use App\Models\Assignment;
use App\Models\AssignmentSubmission;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\Rule;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Auth;
use App\Models\User;
use App\Models\InstitutionUser;

class AssignmentSubmissionController extends Controller
{
    public function index(): JsonResponse
    {
        /** @var User $user */
        $user = Auth::user();

        $query = AssignmentSubmission::with([
            'assignment.course',
            'studentProfile.user',
            'studentProfile.batch'
        ])->latest();

        /*
|--------------------------------------------------------------------------
| Student Role
|--------------------------------------------------------------------------
*/
        if ($user->hasRole('student')) {

            $studentProfile = $user->studentProfile;

            if (!$studentProfile) {

                abort(
                    403,
                    'Unauthorized: Student profile not found.'
                );
            }

            $query->where(
                'student_profile_id',
                $studentProfile->id
            );
        }

        /*
|--------------------------------------------------------------------------
| Institution Admin Role
|--------------------------------------------------------------------------
*/
        if ($user->hasRole('institution-admin')) {

            $institutionUser = InstitutionUser::where(
                'user_id',
                $user->id
            )->first();

            if (!$institutionUser) {

                abort(
                    403,
                    'Unauthorized: Institution profile not found.'
                );
            }

            $query->whereHas(
                'studentProfile',
                function ($q) use ($institutionUser) {

                    $q->where(
                        'institution_id',
                        $institutionUser->institution_id
                    );
                }
            );
        }

        /*
|--------------------------------------------------------------------------
| Teacher Role
|--------------------------------------------------------------------------
*/
        if ($user->hasRole('teacher')) {

            $teacherProfile = $user->teacherProfile;

            if (!$teacherProfile) {

                abort(
                    403,
                    'Unauthorized: Teacher profile not found.'
                );
            }

            $query->whereHas(
                'assignment',
                function ($q) use ($teacherProfile) {

                    $q->where(
                        'teacher_profile_id',
                        $teacherProfile->id
                    );
                }
            );
        }

        /*
|--------------------------------------------------------------------------
| Parent Role
|--------------------------------------------------------------------------
*/
        if ($user->hasRole('parent')) {

            $parentProfile = $user->parentProfile;

            if (!$parentProfile) {

                abort(
                    403,
                    'Unauthorized: Parent profile not found.'
                );
            }

            $query->where(
                'student_profile_id',
                $parentProfile->student_profile_id
            );
        }

        /*
|--------------------------------------------------------------------------
| Unknown Role Protection
|--------------------------------------------------------------------------
*/
        if (
            !$user->hasAnyRole([
                'super-admin',
                'institution-admin',
                'teacher',
                'student',
                'parent'
            ])
        ) {

            abort(
                403,
                'Unauthorized role.'
            );
        }

        return response()->json([
            'message' => 'Assignment submissions fetched successfully.',
            'data' => $query->paginate(20),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = Auth::user();
        /*
|--------------------------------------------------------------------------
| Role Protection
|--------------------------------------------------------------------------
*/
        if (
            !$user->hasAnyRole([
                'super-admin',
                'student'
            ])
        ) {

            abort(
                403,
                'Unauthorized role.'
            );
        }
        $validated = $request->validate([
            'assignment_id' => ['required', 'exists:assignments,id'],
            'student_profile_id' => [
                'required',
                'exists:student_profiles,id',
                Rule::unique('assignment_submissions', 'student_profile_id')
                    ->where('assignment_id', $request->assignment_id),
            ],
            'submission_text' => ['nullable', 'string'],
            'external_url' => ['nullable', 'string', 'max:1000'],
            'file' => [
                'nullable',
                'file',
                'mimes:pdf,doc,docx,ppt,pptx,jpg,jpeg,png,webp,zip,rar,txt,mp4,mov,avi,mkv,webm',
                'max:512000'
            ],
            'status' => ['nullable', 'in:draft,submitted,reviewed,returned'],
        ]);

        /*
|--------------------------------------------------------------------------
| Student Ownership Check
|--------------------------------------------------------------------------
*/
        if ($user->hasRole('student')) {

            $studentProfile = $user->studentProfile;

            if (!$studentProfile) {

                abort(
                    403,
                    'Student profile not found.'
                );
            }

            if (
                $validated['student_profile_id'] !=
                $studentProfile->id
            ) {

                abort(
                    403,
                    'You can only submit your own assignments.'
                );
            }
        }

        /*
|--------------------------------------------------------------------------
| Enrollment Check
|--------------------------------------------------------------------------
*/
        $assignment = Assignment::findOrFail(
            $validated['assignment_id']
        );

        if ($user->hasRole('student')) {

            $isEnrolled = $studentProfile
                ->courseEnrollments()
                ->where(
                    'course_id',
                    $assignment->course_id
                )
                ->exists();

            if (!$isEnrolled) {

                abort(
                    403,
                    'You are not enrolled in this course.'
                );
            }
        }

        /*
|--------------------------------------------------------------------------
| Due Date Check
|--------------------------------------------------------------------------
*/
        if (
            $user->hasRole('student')
            && $assignment->due_date
            && now()->greaterThan($assignment->due_date)
            && !$assignment->allow_late_submission
        ) {

            abort(
                403,
                'Submission deadline has passed.'
            );
        }

        if ($request->hasFile('file')) {
            $validated['file_path'] = $request->file('file')->store('assignment-submissions', 'public');
        }

        unset($validated['file']);

        if (($validated['status'] ?? null) === 'submitted') {
            $validated['submitted_at'] = now();
            $validated['is_late'] = $this->checkIfLate($validated['assignment_id']);
        }

        /*
|--------------------------------------------------------------------------
| Prevent Duplicate / Reviewed Submission
|--------------------------------------------------------------------------
*/
        $existingSubmission = AssignmentSubmission::where(
            'assignment_id',
            $validated['assignment_id']
        )
            ->where(
                'student_profile_id',
                $validated['student_profile_id']
            )
            ->first();

        if ($existingSubmission) {

            if (
                in_array(
                    $existingSubmission->status,
                    ['reviewed']
                )
            ) {

                abort(
                    403,
                    'Assignment has already been reviewed.'
                );
            }

            abort(
                403,
                'Assignment has already been submitted.'
            );
        }

        $submission = AssignmentSubmission::create($validated);

        return response()->json([
            'message' => 'Assignment submission created successfully.',
            'data' => $submission->load([
                'assignment.course',
                'studentProfile.user',
                'studentProfile.batch'
            ]),
        ], 201);
    }

    public function show(
        AssignmentSubmission $assignmentSubmission
    ): JsonResponse {

        /** @var User $user */
        $user = Auth::user();

        $assignmentSubmission->load([
            'assignment.course',
            'studentProfile.user',
            'studentProfile.batch'
        ]);

        /*
    |--------------------------------------------------------------------------
    | Student Role
    |--------------------------------------------------------------------------
    */
        if ($user->hasRole('student')) {

            $studentProfile = $user->studentProfile;

            if (
                !$studentProfile ||
                $assignmentSubmission->student_profile_id !== $studentProfile->id
            ) {

                abort(
                    403,
                    'Unauthorized: You can only view your own submissions.'
                );
            }
        }

        /*
    |--------------------------------------------------------------------------
    | Parent Role
    |--------------------------------------------------------------------------
    */
        if ($user->hasRole('parent')) {

            $parentProfile = $user->parentProfile;

            if (
                !$parentProfile ||
                $assignmentSubmission->student_profile_id !==
                $parentProfile->student_profile_id
            ) {

                abort(
                    403,
                    'Unauthorized: You can only view your child submissions.'
                );
            }
        }

        /*
    |--------------------------------------------------------------------------
    | Institution Admin Role
    |--------------------------------------------------------------------------
    */
        if ($user->hasRole('institution-admin')) {

            $institutionUser = InstitutionUser::where(
                'user_id',
                $user->id
            )->first();

            if (
                !$institutionUser ||
                $assignmentSubmission
                ->studentProfile
                ->institution_id !== $institutionUser->institution_id
            ) {

                abort(
                    403,
                    'Unauthorized: Student does not belong to your institution.'
                );
            }
        }

        /*
    |--------------------------------------------------------------------------
    | Teacher Role
    |--------------------------------------------------------------------------
    */
        if ($user->hasRole('teacher')) {

            $teacherProfile = $user->teacherProfile;

            if (!$teacherProfile) {

                abort(
                    403,
                    'Unauthorized: Teacher profile not found.'
                );
            }

            if (
                $assignmentSubmission
                ->assignment
                ->teacher_profile_id !== $teacherProfile->id
            ) {

                abort(
                    403,
                    'Unauthorized: This submission does not belong to your assignments.'
                );
            }
        }

        /*
    |--------------------------------------------------------------------------
    | Unknown Role Protection
    |--------------------------------------------------------------------------
    */
        if (
            !$user->hasAnyRole([
                'super-admin',
                'institution-admin',
                'teacher',
                'student',
                'parent'
            ])
        ) {

            abort(
                403,
                'Unauthorized role.'
            );
        }

        return response()->json([
            'message' => 'Assignment submission fetched successfully.',
            'data' => $assignmentSubmission,
        ]);
    }

    public function update(
        Request $request,
        AssignmentSubmission $assignmentSubmission
    ): JsonResponse {

        /** @var User $user */
        $user = Auth::user();

        /*
    |--------------------------------------------------------------------------
    | Role Protection
    |--------------------------------------------------------------------------
    */
        if (
            !$user->hasAnyRole([
                'super-admin',
                'student'
            ])
        ) {

            abort(
                403,
                'Unauthorized role.'
            );
        }

        /*
    |--------------------------------------------------------------------------
    | Student Ownership Check
    |--------------------------------------------------------------------------
    */
        if ($user->hasRole('student')) {

            $studentProfile = $user->studentProfile;

            if (
                !$studentProfile ||
                $assignmentSubmission->student_profile_id != $studentProfile->id
            ) {

                abort(
                    403,
                    'You can update only your own submission.'
                );
            }
        }

        /*
    |--------------------------------------------------------------------------
    | Prevent Editing Reviewed Submission
    |--------------------------------------------------------------------------
    */
        if (
            $assignmentSubmission->status === 'reviewed'
        ) {

            abort(
                403,
                'Reviewed assignments cannot be modified.'
            );
        }

        /*
    |--------------------------------------------------------------------------
    | Due Date Check
    |--------------------------------------------------------------------------
    */
        $assignment = $assignmentSubmission->assignment;

        if (
            $user->hasRole('student')
            && $assignment->due_date
            && now()->greaterThan($assignment->due_date)
            && !$assignment->allow_late_submission
        ) {

            abort(
                403,
                'Submission deadline has passed.'
            );
        }

        /*
    |--------------------------------------------------------------------------
    | Validation
    |--------------------------------------------------------------------------
    */
        $validated = $request->validate([
            'submission_text' => ['nullable', 'string'],
            'external_url' => ['nullable', 'string', 'max:1000'],
            'file' => [
                'nullable',
                'file',
                'mimes:pdf,doc,docx,ppt,pptx,jpg,jpeg,png,webp,zip,rar,txt,mp4,mov,avi,mkv,webm',
                'max:512000'
            ],
            'status' => [
                'nullable',
                'in:draft,submitted,reviewed,returned'
            ],
        ]);

        /*
    |--------------------------------------------------------------------------
    | Replace File
    |--------------------------------------------------------------------------
    */
        if ($request->hasFile('file')) {

            if (
                $assignmentSubmission->file_path &&
                Storage::disk('public')->exists(
                    $assignmentSubmission->file_path
                )
            ) {

                Storage::disk('public')->delete(
                    $assignmentSubmission->file_path
                );
            }

            $validated['file_path'] = $request
                ->file('file')
                ->store(
                    'assignment-submissions',
                    'public'
                );
        }

        /*
    |--------------------------------------------------------------------------
    | Submitted Timestamp
    |--------------------------------------------------------------------------
    */
        if (
            ($validated['status'] ?? null) === 'submitted'
            && !$assignmentSubmission->submitted_at
        ) {

            $validated['submitted_at'] = now();

            $validated['is_late'] =
                $this->checkIfLate(
                    $assignmentSubmission->assignment_id
                );
        }

        $assignmentSubmission->update(
            $validated
        );

        return response()->json([
            'message' => 'Assignment submission updated successfully.',
            'data' => $assignmentSubmission
                ->fresh()
                ->load([
                    'assignment.course',
                    'studentProfile.user',
                    'studentProfile.batch'
                ]),
        ]);
    }

    public function destroy(
        AssignmentSubmission $assignmentSubmission
    ): JsonResponse {

        /** @var User $user */
        $user = Auth::user();

        /*
    |--------------------------------------------------------------------------
    | Role Protection
    |--------------------------------------------------------------------------
    */
        if (
            !$user->hasAnyRole([
                'super-admin',
                'student'
            ])
        ) {

            abort(
                403,
                'Unauthorized role.'
            );
        }

        /*
    |--------------------------------------------------------------------------
    | Student Ownership Check
    |--------------------------------------------------------------------------
    */
        if ($user->hasRole('student')) {

            $studentProfile = $user->studentProfile;

            if (
                !$studentProfile ||
                $assignmentSubmission->student_profile_id != $studentProfile->id
            ) {

                abort(
                    403,
                    'You can delete only your own submission.'
                );
            }
        }

        /*
    |--------------------------------------------------------------------------
    | Prevent Deletion After Submission/Review
    |--------------------------------------------------------------------------
    */
        if (
            in_array(
                $assignmentSubmission->status,
                ['submitted', 'reviewed']
            )
        ) {

            abort(
                403,
                'Submitted or reviewed assignments cannot be deleted.'
            );
        }

        /*
    |--------------------------------------------------------------------------
    | Delete Attached File
    |--------------------------------------------------------------------------
    */
        if (
            $assignmentSubmission->file_path &&
            Storage::disk('public')->exists(
                $assignmentSubmission->file_path
            )
        ) {

            Storage::disk('public')->delete(
                $assignmentSubmission->file_path
            );
        }

        $assignmentSubmission->delete();

        return response()->json([
            'message' => 'Assignment submission deleted successfully.',
        ]);
    }

    private function checkIfLate(int $assignmentId): bool
    {
        $dueDate = Assignment::where(
            'id',
            $assignmentId
        )->value('due_date');

        return $dueDate
            ? now()->greaterThan($dueDate)
            : false;
    }
}
