<?php

namespace App\Http\Controllers\Api;

use App\Models\Quiz;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Course;
use App\Models\Lesson;
use App\Models\InstitutionUser;
use App\Models\CourseSection;
use Illuminate\Support\Facades\Auth;

class QuizController extends Controller
{
    public function index(): JsonResponse
    {
        /** @var User $user */
        $user = Auth::user();

        $query = Quiz::with([
            'course',
            'courseSection',
            'lesson',
            'teacherProfile.user'
        ]);

        /*
    |--------------------------------------------------------------------------
    | Institution Admin
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
                    'Institution profile not found.'
                );
            }

            $query->whereHas(
                'course',
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
    | Teacher
    |--------------------------------------------------------------------------
    */ elseif ($user->hasRole('teacher')) {

            $teacherProfile = $user->teacherProfile;

            if (!$teacherProfile) {

                abort(
                    403,
                    'Teacher profile not found.'
                );
            }

            $query->whereHas(
                'course',
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
    | Super Admin
    |--------------------------------------------------------------------------
    */ elseif (!$user->hasRole('super-admin')) {

            abort(
                403,
                'Unauthorized role.'
            );
        }

        $quizzes = $query
            ->latest()
            ->paginate(20);

        return response()->json([
            'message' => 'Quizzes fetched successfully.',
            'data' => $quizzes,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = Auth::user();

        $validated = $request->validate([
            'course_id' => ['required', 'exists:courses,id'],
            'course_section_id' => ['nullable', 'exists:course_sections,id'],
            'lesson_id' => ['nullable', 'exists:lessons,id'],
            'teacher_profile_id' => ['nullable', 'exists:teacher_profiles,id'],

            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],

            'duration_minutes' => ['nullable', 'integer', 'min:1'],
            'total_marks' => ['nullable', 'numeric', 'min:0'],
            'passing_marks' => ['nullable', 'numeric', 'min:0'],

            'shuffle_questions' => ['boolean'],
            'show_result_immediately' => ['boolean'],

            'available_from' => ['nullable', 'date'],
            'available_until' => ['nullable', 'date', 'after:available_from'],

            'status' => ['nullable', 'in:draft,published,closed'],
        ]);

        /*
    |--------------------------------------------------------------------------
    | Course Validation
    |--------------------------------------------------------------------------
    */
        $course = Course::findOrFail(
            $validated['course_id']
        );

        $this->authorizeQuizAccess(
            course: $course
        );

        /*
    |--------------------------------------------------------------------------
    | Teacher Ownership Protection
    |--------------------------------------------------------------------------
    */
        if ($user->hasRole('teacher')) {

            $teacherProfile = $user->teacherProfile;

            if (!$teacherProfile) {

                abort(
                    403,
                    'Teacher profile not found.'
                );
            }

            $validated['teacher_profile_id'] =
                $teacherProfile->id;
        }

        /*
    |--------------------------------------------------------------------------
    | Institution Admin Protection
    |--------------------------------------------------------------------------
    */
        if ($user->hasRole('institution-admin')) {

            $validated['teacher_profile_id'] =
                $course->teacher_profile_id;
        }

        /*
    |--------------------------------------------------------------------------
    | Relationship Validation
    |--------------------------------------------------------------------------
    */
        $this->validateQuizRelations(
            $validated,
            $course
        );

        /*
    |--------------------------------------------------------------------------
    | Default Teacher Assignment
    |--------------------------------------------------------------------------
    */
        if (empty($validated['teacher_profile_id'])) {

            $validated['teacher_profile_id'] =
                $course->teacher_profile_id;
        }

        $quiz = Quiz::create(
            $validated
        );

        return response()->json([
            'message' => 'Quiz created successfully.',
            'data' => $quiz->load([
                'course',
                'courseSection',
                'lesson',
                'teacherProfile.user'
            ]),
        ], 201);
    }

    public function show(Quiz $quiz): JsonResponse
    {
        $this->authorizeQuizAccess(
            quiz: $quiz
        );

        return response()->json([
            'message' => 'Quiz fetched successfully.',
            'data' => $quiz->load([
                'course',
                'courseSection',
                'lesson',
                'teacherProfile.user'
            ]),
        ]);
    }

    public function update(Request $request, Quiz $quiz): JsonResponse
    {
        /*
    |--------------------------------------------------------------------------
    | Existing Quiz Authorization
    |--------------------------------------------------------------------------
    */
        $this->authorizeQuizAccess(
            quiz: $quiz
        );

        /** @var User $user */
        $user = Auth::user();

        $validated = $request->validate([
            'course_id' => ['sometimes', 'exists:courses,id'],
            'course_section_id' => ['nullable', 'exists:course_sections,id'],
            'lesson_id' => ['nullable', 'exists:lessons,id'],
            'teacher_profile_id' => ['nullable', 'exists:teacher_profiles,id'],

            'title' => ['sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string'],

            'duration_minutes' => ['nullable', 'integer', 'min:1'],
            'total_marks' => ['nullable', 'numeric', 'min:0'],
            'passing_marks' => ['nullable', 'numeric', 'min:0'],

            'shuffle_questions' => ['boolean'],
            'show_result_immediately' => ['boolean'],

            'available_from' => ['nullable', 'date'],
            'available_until' => ['nullable', 'date', 'after:available_from'],

            'status' => ['nullable', 'in:draft,published,closed'],
        ]);

        /*
    |--------------------------------------------------------------------------
    | Target Course Validation
    |--------------------------------------------------------------------------
    */
        $course = isset($validated['course_id'])
            ? Course::findOrFail($validated['course_id'])
            : $quiz->course;

        $this->authorizeQuizAccess(
            course: $course
        );

        /*
    |--------------------------------------------------------------------------
    | Teacher Ownership Protection
    |--------------------------------------------------------------------------
    */
        if ($user->hasRole('teacher')) {

            $teacherProfile = $user->teacherProfile;

            if (!$teacherProfile) {

                abort(
                    403,
                    'Teacher profile not found.'
                );
            }

            $validated['teacher_profile_id'] =
                $teacherProfile->id;
        }

        /*
    |--------------------------------------------------------------------------
    | Institution Admin Protection
    |--------------------------------------------------------------------------
    */
        if ($user->hasRole('institution-admin')) {

            $validated['teacher_profile_id'] =
                $course->teacher_profile_id;
        }

        /*
    |--------------------------------------------------------------------------
    | Relationship Validation
    |--------------------------------------------------------------------------
    */
        $this->validateQuizRelations(
            $validated,
            $course
        );

        /*
    |--------------------------------------------------------------------------
    | Default Teacher Assignment
    |--------------------------------------------------------------------------
    */
        if (
            !isset($validated['teacher_profile_id'])
        ) {

            $validated['teacher_profile_id'] =
                $course->teacher_profile_id;
        }

        $quiz->update(
            $validated
        );

        return response()->json([
            'message' => 'Quiz updated successfully.',
            'data' => $quiz
                ->fresh()
                ->load([
                    'course',
                    'courseSection',
                    'lesson',
                    'teacherProfile.user'
                ]),
        ]);
    }

    public function destroy(Quiz $quiz): JsonResponse
    {
        /*
    |--------------------------------------------------------------------------
    | Authorization
    |--------------------------------------------------------------------------
    */
        $this->authorizeQuizAccess(
            quiz: $quiz
        );

        $quiz->delete();

        return response()->json([
            'message' => 'Quiz deleted successfully.',
        ]);
    }

    private function authorizeQuizAccess(
        ?Quiz $quiz = null,
        ?Course $course = null
    ): void {
        /** @var User $user */
        $user = Auth::user();

        /*
    |--------------------------------------------------------------------------
    | Super Admin
    |--------------------------------------------------------------------------
    */
        if ($user->hasRole('super-admin')) {
            return;
        }

        /*
    |--------------------------------------------------------------------------
    | Institution Admin
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
                    'Institution profile not found.'
                );
            }

            if ($quiz) {
                $quiz->loadMissing('course');
            }

            $institutionId =
                $quiz?->course?->institution_id
                ?? $course?->institution_id;

            if (
                !$institutionId ||
                (int)$institutionId !==
                (int)$institutionUser->institution_id
            ) {

                abort(
                    403,
                    'Unauthorized institution access.'
                );
            }

            return;
        }

        /*
    |--------------------------------------------------------------------------
    | Teacher
    |--------------------------------------------------------------------------
    */
        if ($user->hasRole('teacher')) {

            $teacherProfile = $user->teacherProfile;

            if (!$teacherProfile) {

                abort(
                    403,
                    'Teacher profile not found.'
                );
            }

            if ($quiz) {
                $quiz->loadMissing('course');
            }

            $teacherId =
                $quiz?->course?->teacher_profile_id
                ?? $course?->teacher_profile_id;

            if (
                !$teacherId ||
                (int)$teacherId !==
                (int)$teacherProfile->id
            ) {

                abort(
                    403,
                    'Unauthorized quiz access.'
                );
            }

            return;
        }

        abort(
            403,
            'Unauthorized role.'
        );
    }

    private function validateQuizRelations(
        array $validated,
        Course $course
    ): void {

        /*
    |--------------------------------------------------------------------------
    | Course Section Validation
    |--------------------------------------------------------------------------
    */
        if (!empty($validated['course_section_id'])) {

            $section = CourseSection::where(
                'id',
                $validated['course_section_id']
            )
                ->where(
                    'course_id',
                    $course->id
                )
                ->first();

            if (!$section) {

                abort(
                    422,
                    'Selected course section does not belong to the course.'
                );
            }
        }

        /*
    |--------------------------------------------------------------------------
    | Lesson Validation
    |--------------------------------------------------------------------------
    */
        if (!empty($validated['lesson_id'])) {

            $lesson = Lesson::where(
                'id',
                $validated['lesson_id']
            )
                ->where(
                    'course_id',
                    $course->id
                )
                ->first();

            if (!$lesson) {

                abort(
                    422,
                    'Selected lesson does not belong to the course.'
                );
            }
        }

        /*
    |--------------------------------------------------------------------------
    | Passing Marks Validation
    |--------------------------------------------------------------------------
    */
        $totalMarks = $validated['total_marks'] ?? null;
        $passingMarks = $validated['passing_marks'] ?? null;

        if (
            $totalMarks !== null &&
            $passingMarks !== null &&
            $passingMarks > $totalMarks
        ) {

            abort(
                422,
                'Passing marks cannot exceed total marks.'
            );
        }
    }
}
