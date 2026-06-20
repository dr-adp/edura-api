# EDURA — Complete Fix Implementation Guide

This document provides exact code solutions for every issue identified in the Project Audit Report.

---

## Table of Contents
1. [🔴 CRITICAL: Student Dashboard IDOR Fix](#1-student-dashboard-idor-fix)
2. [🔴 CRITICAL: Auth Controller Fixes](#2-auth-controller-fixes)
3. [🔴 CRITICAL: Quiz Pass/Fail Logic Bug](#3-quiz-passfail-logic-bug)
4. [🔴 CRITICAL: Gradebook Authorization Fix](#4-gradebook-authorization-fix)
5. [🔴 CRITICAL: Rate Limiting Setup](#5-rate-limiting-setup)
6. [🟠 HIGH: Laravel Policies Creation](#6-laravel-policies-creation)
7. [🟠 HIGH: Institution Scoping Middleware](#7-institution-scoping-middleware)
8. [🟠 HIGH: Missing Dashboard Routes](#8-missing-dashboard-routes)
9. [🟠 HIGH: Soft Deletes Migration](#9-soft-deletes-migration)
10. [🟠 HIGH: CORS Configuration](#10-cors-configuration)
11. [🟠 MEDIUM: Registration Role Restriction](#11-registration-role-restriction)
12. [🟠 MEDIUM: Password Complexity](#12-password-complexity)
13. [🟠 MEDIUM: My-Gradebooks/My-Certificates Filtering](#13-my-gradebooksmy-certificates-filtering)

---

## 1. Student Dashboard IDOR Fix

**File:** `app/Http/Controllers/Api/StudentDashboardController.php`

**Problem:** Any authenticated user can view any student's dashboard by passing any `studentProfile` ID.

**Fix:** Add ownership check at the beginning of the `show` method.

```php
<?php

namespace App\Http\Controllers\Api;

use App\Models\StudentProfile;
use App\Models\Assignment;
use App\Models\LiveClass;
use Illuminate\Http\JsonResponse;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Auth;

class StudentDashboardController extends Controller
{
    public function show(StudentProfile $studentProfile): JsonResponse
    {
        // 🔐 OWNERSHIP CHECK: Ensure the student owns this profile
        $user = Auth::user();
        
        // Get the student profile associated with the authenticated user
        $authStudentProfile = StudentProfile::where('user_id', $user->id)->first();
        
        // If user is not a super-admin or institution-admin, enforce ownership
        if (!$user->hasRole(['super-admin', 'institution-admin'])) {
            if (!$authStudentProfile || $authStudentProfile->id !== $studentProfile->id) {
                abort(403, 'Unauthorized: You can only view your own dashboard.');
            }
        }
        
        // If user is institution-admin, ensure student belongs to same institution
        if ($user->hasRole('institution-admin')) {
            $authInstProfile = \App\Models\InstitutionUser::where('user_id', $user->id)->first();
            if ($authInstProfile && $studentProfile->institution_id !== $authInstProfile->institution_id) {
                abort(403, 'Unauthorized: Student does not belong to your institution.');
            }
        }

        $studentProfile->load([
            'user',
            'institution',
            'department',
            'batch',
            'courseEnrollments.course',
            'certificates',
            'assignmentSubmissions.assignment',
            'quizAttempts.quiz'
        ]);

        $enrolledCourses = $studentProfile->courseEnrollments;

        $completedCourses = $enrolledCourses
            ->where('status', 'completed')
            ->values();

        $pendingAssignments = Assignment::whereDoesntHave(
            'submissions',
            function ($query) use ($studentProfile) {
                $query->where(
                    'student_profile_id',
                    $studentProfile->id
                );
            }
        )
            ->with('course')
            ->get();

        $submittedAssignments = $studentProfile->assignmentSubmissions;

        $upcomingLiveClasses = LiveClass::where(
            'scheduled_start_time',
            '>=',
            now()
        )
            ->with([
                'course',
                'teacherProfile'
            ])
            ->orderBy('scheduled_start_time')
            ->get();

        $quizAttempts = $studentProfile->quizAttempts;

        $certificates = $studentProfile->certificates;

        $overallProgress = round(
            $enrolledCourses->avg('progress_percentage') ?? 0,
            2
        );

        return response()->json([
            'message' => 'Student dashboard fetched successfully.',
            'data' => [
                'student' => $studentProfile,
                'enrolled_courses' => $enrolledCourses,
                'completed_courses' => $completedCourses,
                'pending_assignments' => $pendingAssignments,
                'submitted_assignments' => $submittedAssignments,
                'upcoming_live_classes' => $upcomingLiveClasses,
                'quiz_attempts' => $quizAttempts,
                'certificates' => $certificates,
                'overall_progress' => $overallProgress,
            ],
        ]);
    }
}
```

---

## 2. Auth Controller Fixes

**File:** `app/Http/Controllers/Api/AuthController.php`

**Problems:**
1. `tokens()->delete()` on login revokes ALL tokens (no multi-device support)
2. Registration allows self-assigning any role (except super-admin)
3. No password complexity requirements

**Fix:**

```php
<?php

namespace App\Http\Controllers\Api;

use App\Models\Role;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function register(Request $request): JsonResponse
    {
        $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => [
                'required',
                'string',
                'min:8',
                'confirmed',
                'regex:/[A-Z]/',       // At least one uppercase
                'regex:/[a-z]/',       // At least one lowercase
                'regex:/[0-9]/',       // At least one number
                'regex:/[@$!%*#?&]/',  // At least one special character
            ],
            // 🔐 FIX: Restrict registration to 'student' role only for security
            // Remove this line entirely to force student-only registration
            // 'role' => ['nullable', 'string', 'in:institution-admin,teacher,student,parent'],
        ]);

        // 🔐 FIX: Force all self-registrations to be 'student' role
        // Other roles (super-admin, institution-admin, teacher, parent)
        // must be created by an admin or via seeders
        $roleName = 'student';

        $role = Role::where('name', $roleName)->first();

        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => $request->password,
            'role_id' => $role?->id,
        ]);

        $user->assignRole($roleName);

        $token = $user->createToken('edura-api-token', ['*'])->plainTextToken;

        return response()->json([
            'message' => 'Registration successful.',
            'token' => $token,
            'user' => $user->load('role'),
            'roles' => $user->getRoleNames(),
            'permissions' => $user->getAllPermissions()->pluck('name'),
        ], 201);
    }

    public function login(Request $request): JsonResponse
    {
        $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = User::with('role')->where('email', $request->email)->first();

        if (!$user || !Hash::check($request->password, $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['Invalid email or password.'],
            ]);
        }

        // 🔐 FIX: Do NOT delete all tokens — allows multi-device login
        // Old code: $user->tokens()->delete();
        // Only delete expired tokens older than 30 days instead
        $user->tokens()
            ->where('created_at', '<', now()->subDays(30))
            ->delete();

        $token = $user->createToken('edura-api-token', ['*'])->plainTextToken;

        return response()->json([
            'message' => 'Login successful.',
            'token' => $token,
            'token_type' => 'Bearer',
            'user' => $user,
            'roles' => $user->getRoleNames(),
            'permissions' => $user->getAllPermissions()->pluck('name'),
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user()->load('role');

        return response()->json([
            'user' => $user,
            'roles' => $user->getRoleNames(),
            'permissions' => $user->getAllPermissions()->pluck('name'),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'message' => 'Logout successful.',
        ]);
    }
}
```

---

## 3. Quiz Pass/Fail Logic Bug

### 3a. QuizAttemptController Fix

**File:** `app/Http/Controllers/Api/QuizAttemptController.php`

**Problem:** Compares raw marks against passing marks instead of percentage against passing percentage.

**Fix:**

```php
<?php

namespace App\Http\Controllers\Api;

use App\Models\Quiz;
use App\Models\QuizAttempt;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Auth;

class QuizAttemptController extends Controller
{
    public function index(): JsonResponse
    {
        $attempts = QuizAttempt::with([
            'quiz.course',
            'studentProfile.user',
            'studentProfile.batch'
        ])
            ->latest()
            ->paginate(20);

        return response()->json([
            'message' => 'Quiz attempts fetched successfully.',
            'data' => $attempts,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'quiz_id' => ['required', 'exists:quizzes,id'],
            'student_profile_id' => ['required', 'exists:student_profiles,id'],
        ]);

        // 🔐 OWNERSHIP CHECK: Student can only start attempts for themselves
        $user = Auth::user();
        if (!$user->hasRole(['super-admin', 'institution-admin', 'teacher'])) {
            $studentProfile = \App\Models\StudentProfile::where('user_id', $user->id)->first();
            if (!$studentProfile || (int) $studentProfile->id !== (int) $validated['student_profile_id']) {
                abort(403, 'Unauthorized: You can only attempt quizzes for yourself.');
            }
        }

        $quiz = Quiz::findOrFail($validated['quiz_id']);

        // ⏰ TIMER CHECK: Ensure quiz is available
        if ($quiz->available_from && now()->lt($quiz->available_from)) {
            abort(403, 'This quiz is not yet available.');
        }
        if ($quiz->available_until && now()->gt($quiz->available_until)) {
            abort(403, 'This quiz has expired.');
        }

        $lastAttemptNumber = QuizAttempt::where('quiz_id', $validated['quiz_id'])
            ->where('student_profile_id', $validated['student_profile_id'])
            ->max('attempt_number');

        $attempt = QuizAttempt::create([
            'quiz_id' => $validated['quiz_id'],
            'student_profile_id' => $validated['student_profile_id'],
            'attempt_number' => ($lastAttemptNumber ?? 0) + 1,
            'started_at' => now(),
            'total_marks' => $quiz->total_marks,
            'marks_obtained' => 0,
            'percentage' => 0,
            'result_status' => 'pending',
            'status' => 'in_progress',
        ]);

        return response()->json([
            'message' => 'Quiz attempt started successfully.',
            'data' => $attempt->load([
                'quiz.course',
                'studentProfile.user',
                'studentProfile.batch'
            ]),
        ], 201);
    }

    public function show(QuizAttempt $quizAttempt): JsonResponse
    {
        // 🔐 OWNERSHIP CHECK
        $this->authorizeQuizAttemptAccess($quizAttempt);

        return response()->json([
            'message' => 'Quiz attempt fetched successfully.',
            'data' => $quizAttempt->load([
                'quiz.course',
                'studentProfile.user',
                'studentProfile.batch'
            ]),
        ]);
    }

    public function update(Request $request, QuizAttempt $quizAttempt): JsonResponse
    {
        // 🔐 OWNERSHIP CHECK
        $user = Auth::user();
        if (!$user->hasRole(['super-admin', 'institution-admin', 'teacher'])) {
            $studentProfile = \App\Models\StudentProfile::where('user_id', $user->id)->first();
            if (!$studentProfile || (int) $studentProfile->id !== (int) $quizAttempt->student_profile_id) {
                abort(403, 'Unauthorized: You can only update your own quiz attempts.');
            }
        }

        $validated = $request->validate([
            'marks_obtained' => ['nullable', 'numeric', 'min:0'],
            'status' => ['nullable', 'in:in_progress,submitted,evaluated,cancelled'],
        ]);

        // ⏰ TIMER ENFORCEMENT: Check if quiz duration has expired on submit
        if (($validated['status'] ?? null) === 'submitted' && $quizAttempt->quiz->duration_minutes) {
            $startTime = $quizAttempt->started_at;
            $elapsedMinutes = $startTime ? now()->diffInMinutes($startTime) : 0;
            if ($elapsedMinutes > $quizAttempt->quiz->duration_minutes) {
                // Auto-submit with whatever answers they have
                $validated['status'] = 'submitted';
                $validated['submitted_at'] = now();
            }
        }

        if (isset($validated['marks_obtained'])) {
            $totalMarks = $quizAttempt->total_marks > 0 ? $quizAttempt->total_marks : 1;

            $validated['percentage'] = round(($validated['marks_obtained'] / $totalMarks) * 100, 2);

            // 🔐 FIX: Use PERCENTAGE-based comparison, not raw marks
            $passingMarks = $quizAttempt->quiz->passing_marks ?? 0;
            $passPercentage = $totalMarks > 0 ? ($passingMarks / $totalMarks) * 100 : 0;

            $validated['result_status'] = $validated['percentage'] >= $passPercentage
                ? 'passed'
                : 'failed';
        }

        if (($validated['status'] ?? null) === 'submitted') {
            $validated['submitted_at'] = now();
        }

        if (($validated['status'] ?? null) === 'evaluated' && !$quizAttempt->submitted_at) {
            $validated['submitted_at'] = now();
        }

        $quizAttempt->update($validated);

        return response()->json([
            'message' => 'Quiz attempt updated successfully.',
            'data' => $quizAttempt->fresh()->load([
                'quiz.course',
                'studentProfile.user',
                'studentProfile.batch'
            ]),
        ]);
    }

    public function destroy(QuizAttempt $quizAttempt): JsonResponse
    {
        // 🔐 OWNERSHIP CHECK
        $user = Auth::user();
        if (!$user->hasRole(['super-admin', 'institution-admin'])) {
            abort(403, 'Unauthorized: Only admins can delete quiz attempts.');
        }

        $quizAttempt->delete();

        return response()->json([
            'message' => 'Quiz attempt deleted successfully.',
        ]);
    }

    /**
     * Authorize access to a quiz attempt.
     */
    private function authorizeQuizAttemptAccess(QuizAttempt $quizAttempt): void
    {
        $user = Auth::user();

        // Super-admin and institution-admin can view any attempt
        if ($user->hasRole(['super-admin', 'institution-admin'])) {
            return;
        }

        // Teacher can view attempts for their courses
        if ($user->hasRole('teacher')) {
            $teacherProfile = \App\Models\TeacherProfile::where('user_id', $user->id)->first();
            if ($teacherProfile && $quizAttempt->quiz->teacher_profile_id === $teacherProfile->id) {
                return;
            }
            abort(403, 'Unauthorized: This quiz is not assigned to you.');
        }

        // Student can only view their own attempts
        $studentProfile = \App\Models\StudentProfile::where('user_id', $user->id)->first();
        if (!$studentProfile || (int) $studentProfile->id !== (int) $quizAttempt->student_profile_id) {
            abort(403, 'Unauthorized: You can only view your own quiz attempts.');
        }
    }
}
```

### 3b. QuizAnswerController Fix

**File:** `app/Http/Controllers/Api/QuizAnswerController.php`

**Problem:** Same pass/fail bug in `recalculateQuizAttempt` method.

**Fix (targeted):** Replace the `recalculateQuizAttempt` method:

```php
private function recalculateQuizAttempt(QuizAttempt $attempt): void
{
    $marksObtained = QuizAnswer::where('quiz_attempt_id', $attempt->id)->sum('marks_obtained');
    $totalMarks = $attempt->total_marks > 0 ? $attempt->total_marks : 1;

    $percentage = round(($marksObtained / $totalMarks) * 100, 2);

    // 🔐 FIX: Use PERCENTAGE-based comparison
    $passingMarks = $attempt->quiz->passing_marks ?? 0;
    $passPercentage = $totalMarks > 0 ? ($passingMarks / $totalMarks) * 100 : 0;

    $resultStatus = $percentage >= $passPercentage ? 'passed' : 'failed';

    $attempt->update([
        'marks_obtained' => $marksObtained,
        'percentage' => $percentage,
        'result_status' => $resultStatus,
    ]);
}
```

Also add ownership checks to the `store` method to ensure students can only add answers to their own attempts:

```php
public function store(Request $request): JsonResponse
{
    $validated = $request->validate([
        'quiz_attempt_id' => ['required', 'exists:quiz_attempts,id'],
        'question_bank_id' => [
            'required',
            'exists:question_banks,id',
            Rule::unique('quiz_answers', 'question_bank_id')
                ->where('quiz_attempt_id', $request->quiz_attempt_id),
        ],
        'question_option_id' => ['nullable', 'exists:question_options,id'],
        'answer_text' => ['nullable', 'string'],
    ]);

    $attempt = QuizAttempt::with('quiz')->findOrFail($validated['quiz_attempt_id']);

    // 🔐 OWNERSHIP CHECK: Student can only answer their own attempts
    $user = Auth::user();
    if (!$user->hasRole(['super-admin', 'institution-admin', 'teacher'])) {
        $studentProfile = \App\Models\StudentProfile::where('user_id', $user->id)->first();
        if (!$studentProfile || (int) $studentProfile->id !== (int) $attempt->student_profile_id) {
            abort(403, 'Unauthorized: You can only answer your own quiz attempts.');
        }
    }

    // ⏰ TIMER CHECK: Ensure quiz hasn't expired
    if ($attempt->quiz->duration_minutes) {
        $startTime = $attempt->started_at;
        $elapsedMinutes = $startTime ? now()->diffInMinutes($startTime) : 0;
        if ($elapsedMinutes > $attempt->quiz->duration_minutes) {
            abort(403, 'Quiz time has expired. You cannot submit additional answers.');
        }
    }

    // ... rest of the method remains the same
```

---

## 4. Gradebook Authorization Fix

**File:** `app/Http/Controllers/Api/GradebookController.php`

**Problem:** No authorization checks — any teacher/admin can read/update/delete/recalculate any gradebook record.

**Fix:**

```php
<?php

namespace App\Http\Controllers\Api;

use App\Models\Course;
use App\Models\Gradebook;
use App\Models\QuizAttempt;
use App\Models\StudentProfile;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Models\AssignmentEvaluation;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class GradebookController extends Controller
{
    public function index(): JsonResponse
    {
        $user = Auth::user();

        $gradebooks = Gradebook::with([
            'course',
            'studentProfile.user',
            'studentProfile.batch'
        ]);

        // 🔐 SCOPING: Filter by user's role
        if ($user->hasRole('super-admin')) {
            // Super-admin sees all
        } elseif ($user->hasRole('institution-admin')) {
            // Institution admin sees their institution's gradebooks
            $institutionUser = \App\Models\InstitutionUser::where('user_id', $user->id)->first();
            if ($institutionUser) {
                $gradebooks->whereHas('course', function ($q) use ($institutionUser) {
                    $q->where('institution_id', $institutionUser->institution_id);
                });
            }
        } elseif ($user->hasRole('teacher')) {
            // Teacher sees their own courses' gradebooks
            $teacherProfile = \App\Models\TeacherProfile::where('user_id', $user->id)->first();
            if ($teacherProfile) {
                $gradebooks->whereHas('course', function ($q) use ($teacherProfile) {
                    $q->where('teacher_profile_id', $teacherProfile->id);
                });
            } else {
                $gradebooks->whereRaw('1 = 0'); // No results
            }
        } elseif ($user->hasRole('student')) {
            // Student sees their own gradebooks
            $studentProfile = StudentProfile::where('user_id', $user->id)->first();
            if ($studentProfile) {
                $gradebooks->where('student_profile_id', $studentProfile->id);
            } else {
                $gradebooks->whereRaw('1 = 0');
            }
        } elseif ($user->hasRole('parent')) {
            // Parent sees their child's gradebooks
            $parentProfile = \App\Models\ParentProfile::where('user_id', $user->id)->first();
            if ($parentProfile && $parentProfile->student_profile_id) {
                $gradebooks->where('student_profile_id', $parentProfile->student_profile_id);
            } else {
                $gradebooks->whereRaw('1 = 0');
            }
        }

        return response()->json([
            'message' => 'Gradebook records fetched successfully.',
            'data' => $gradebooks->latest()->paginate(20),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'course_id' => ['required', 'exists:courses,id'],
            'student_profile_id' => ['required', 'exists:student_profiles,id'],
        ]);

        // 🔐 AUTHORIZATION: Verify teacher owns this course
        $this->authorizeCourseAccess((int) $validated['course_id']);

        $gradebook = $this->calculateGradebook(
            (int) $validated['course_id'],
            (int) $validated['student_profile_id']
        );

        return response()->json([
            'message' => 'Gradebook calculated successfully.',
            'data' => $gradebook->load([
                'course',
                'studentProfile.user',
                'studentProfile.batch'
            ]),
        ], 201);
    }

    public function show(Gradebook $gradebook): JsonResponse
    {
        // 🔐 AUTHORIZATION
        $this->authorizeGradebookAccess($gradebook);

        return response()->json([
            'message' => 'Gradebook record fetched successfully.',
            'data' => $gradebook->load([
                'course',
                'studentProfile.user',
                'studentProfile.batch'
            ]),
        ]);
    }

    public function update(Request $request, Gradebook $gradebook): JsonResponse
    {
        // 🔐 AUTHORIZATION: Only teachers who own the course can update
        $this->authorizeCourseAccess((int) $gradebook->course_id);

        $validated = $request->validate([
            'assignment_marks' => ['nullable', 'numeric', 'min:0'],
            'quiz_marks' => ['nullable', 'numeric', 'min:0'],
            'maximum_marks' => ['nullable', 'numeric', 'min:0'],
        ]);

        $assignmentMarks = $validated['assignment_marks'] ?? $gradebook->assignment_marks;
        $quizMarks = $validated['quiz_marks'] ?? $gradebook->quiz_marks;
        $maximumMarks = $validated['maximum_marks'] ?? $gradebook->maximum_marks;

        $totalMarks = $assignmentMarks + $quizMarks;
        $percentage = $maximumMarks > 0
            ? round(($totalMarks / $maximumMarks) * 100, 2)
            : 0;

        $gradebook->update([
            'assignment_marks' => $assignmentMarks,
            'quiz_marks' => $quizMarks,
            'total_marks' => $totalMarks,
            'maximum_marks' => $maximumMarks,
            'percentage' => $percentage,
            'grade' => $this->calculateGrade($percentage),
            'result_status' => $percentage >= 40 ? 'passed' : 'failed',
        ]);

        return response()->json([
            'message' => 'Gradebook record updated successfully.',
            'data' => $gradebook->fresh()->load([
                'course',
                'studentProfile.user',
                'studentProfile.batch'
            ]),
        ]);
    }

    public function destroy(Gradebook $gradebook): JsonResponse
    {
        // 🔐 AUTHORIZATION: Only teachers who own the course can delete
        $this->authorizeCourseAccess((int) $gradebook->course_id);

        $gradebook->delete();

        return response()->json([
            'message' => 'Gradebook record deleted successfully.',
        ]);
    }

    public function recalculate(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'course_id' => ['required', 'exists:courses,id'],
            'student_profile_id' => ['required', 'exists:student_profiles,id'],
        ]);

        // 🔐 AUTHORIZATION: Verify teacher owns this course
        $this->authorizeCourseAccess((int) $validated['course_id']);

        $gradebook = $this->calculateGradebook(
            (int) $validated['course_id'],
            (int) $validated['student_profile_id']
        );

        return response()->json([
            'message' => 'Gradebook recalculated successfully.',
            'data' => $gradebook->load([
                'course',
                'studentProfile.user',
                'studentProfile.batch'
            ]),
        ]);
    }

    // ... (keep calculateGradebook and calculateGrade methods as they are)

    /**
     * Authorize that the authenticated user has access to this course.
     */
    private function authorizeCourseAccess(int $courseId): void
    {
        $user = Auth::user();

        if ($user->hasRole('super-admin')) {
            return; // Super-admin can access all
        }

        $course = Course::find($courseId);
        if (!$course) {
            abort(404, 'Course not found.');
        }

        if ($user->hasRole('institution-admin')) {
            $institutionUser = \App\Models\InstitutionUser::where('user_id', $user->id)->first();
            if ($institutionUser && (int) $course->institution_id === (int) $institutionUser->institution_id) {
                return;
            }
            abort(403, 'Unauthorized: Course does not belong to your institution.');
        }

        if ($user->hasRole('teacher')) {
            $teacherProfile = \App\Models\TeacherProfile::where('user_id', $user->id)->first();
            if ($teacherProfile && (int) $course->teacher_profile_id === (int) $teacherProfile->id) {
                return;
            }
            abort(403, 'Unauthorized: This course is not assigned to you.');
        }

        abort(403, 'Unauthorized: You do not have permission to manage this gradebook.');
    }

    /**
     * Authorize access to view a gradebook record.
     */
    private function authorizeGradebookAccess(Gradebook $gradebook): void
    {
        $user = Auth::user();

        if ($user->hasRole(['super-admin', 'institution-admin'])) {
            return;
        }

        if ($user->hasRole('teacher')) {
            $this->authorizeCourseAccess((int) $gradebook->course_id);
            return;
        }

        if ($user->hasRole('student')) {
            $studentProfile = StudentProfile::where('user_id', $user->id)->first();
            if ($studentProfile && (int) $studentProfile->id === (int) $gradebook->student_profile_id) {
                return;
            }
            abort(403, 'Unauthorized: You can only view your own gradebook.');
        }

        if ($user->hasRole('parent')) {
            $parentProfile = \App\Models\ParentProfile::where('user_id', $user->id)->first();
            if ($parentProfile && (int) $parentProfile->student_profile_id === (int) $gradebook->student_profile_id) {
                return;
            }
            abort(403, 'Unauthorized: You can only view your child\'s gradebook.');
        }

        abort(403, 'Unauthorized.');
    }
}
```

---

## 5. Rate Limiting Setup

### 5a. Update `bootstrap/app.php`

**File:** `bootstrap/app.php`

```php
<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__ . '/../routes/web.php',
        api: __DIR__ . '/../routes/api.php',
        commands: __DIR__ . '/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {

        $middleware->alias([
            'role' => \Spatie\Permission\Middleware\RoleMiddleware::class,
            'permission' => \Spatie\Permission\Middleware\PermissionMiddleware::class,
            'role_or_permission' => \Spatie\Permission\Middleware\RoleOrPermissionMiddleware::class,
        ]);
        
        // 🔐 Add throttle middleware to auth routes
        $middleware->api(append: [
            // You can add global middleware here
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {

        $exceptions->render(function (
            \Spatie\Permission\Exceptions\UnauthorizedException $e,
            \Illuminate\Http\Request $request
        ) {
            if ($request->is('api/*')) {
                return response()->json([
                    'message' => 'Access denied.',
                ], 403);
            }
        });
    })
    ->create();
```

### 5b. Register Rate Limiters in `AppServiceProvider`

**File:** `app/Providers/AppServiceProvider.php`

```php
<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

class AppServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // 🔐 RATE LIMITING: Protect auth endpoints from brute force
        RateLimiter::for('auth', function (Request $request) {
            return Limit::perMinute(5)->by($request->ip());
        });

        // General API rate limit
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
        });
    }
}
```

### 5c. Apply Rate Limiting to Routes

**File:** `routes/api.php` — Add throttle middleware to public routes

```php
Route::post('/register', [AuthController::class, 'register'])
    ->middleware('throttle:auth');
    
Route::post('/login', [AuthController::class, 'login'])
    ->middleware('throttle:auth');
```

---

## 6. Laravel Policies Creation

### 6a. Create CoursePolicy

**File:** `app/Policies/CoursePolicy.php`

```php
<?php

namespace App\Policies;

use App\Models\Course;
use App\Models\User;
use App\Models\TeacherProfile;
use App\Models\InstitutionUser;

class CoursePolicy
{
    /**
     * Determine whether the user can view any courses.
     */
    public function viewAny(User $user): bool
    {
        return true; // Any authenticated user can list courses
    }

    /**
     * Determine whether the user can view the course.
     */
    public function view(User $user, Course $course): bool
    {
        if ($user->hasRole(['super-admin', 'institution-admin'])) {
            return true;
        }

        if ($user->hasRole('teacher')) {
            $teacherProfile = TeacherProfile::where('user_id', $user->id)->first();
            return $teacherProfile && (int) $course->teacher_profile_id === (int) $teacherProfile->id;
        }

        if ($user->hasRole('student')) {
            // Student can view courses they're enrolled in
            return $course->enrollments()
                ->whereHas('studentProfile', fn($q) => $q->where('user_id', $user->id))
                ->exists();
        }

        return false;
    }

    /**
     * Determine whether the user can create courses.
     */
    public function create(User $user): bool
    {
        return $user->hasRole(['super-admin', 'institution-admin', 'teacher']);
    }

    /**
     * Determine whether the user can update the course.
     */
    public function update(User $user, Course $course): bool
    {
        if ($user->hasRole('super-admin')) {
            return true;
        }

        if ($user->hasRole('institution-admin')) {
            $institutionUser = InstitutionUser::where('user_id', $user->id)->first();
            return $institutionUser && (int) $course->institution_id === (int) $institutionUser->institution_id;
        }

        if ($user->hasRole('teacher')) {
            $teacherProfile = TeacherProfile::where('user_id', $user->id)->first();
            return $teacherProfile && (int) $course->teacher_profile_id === (int) $teacherProfile->id;
        }

        return false;
    }

    /**
     * Determine whether the user can delete the course.
     */
    public function delete(User $user, Course $course): bool
    {
        return $this->update($user, $course); // Same authorization
    }
}
```

### 6b. Create GradebookPolicy

**File:** `app/Policies/GradebookPolicy.php`

```php
<?php

namespace App\Policies;

use App\Models\Gradebook;
use App\Models\User;
use App\Models\StudentProfile;
use App\Models\TeacherProfile;
use App\Models\ParentProfile;

class GradebookPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasRole(['super-admin', 'institution-admin', 'teacher', 'student', 'parent']);
    }

    public function view(User $user, Gradebook $gradebook): bool
    {
        if ($user->hasRole(['super-admin', 'institution-admin'])) {
            return true;
        }

        if ($user->hasRole('teacher')) {
            $teacherProfile = TeacherProfile::where('user_id', $user->id)->first();
            return $teacherProfile && (int) $gradebook->course->teacher_profile_id === (int) $teacherProfile->id;
        }

        if ($user->hasRole('student')) {
            $studentProfile = StudentProfile::where('user_id', $user->id)->first();
            return $studentProfile && (int) $gradebook->student_profile_id === (int) $studentProfile->id;
        }

        if ($user->hasRole('parent')) {
            $parentProfile = ParentProfile::where('user_id', $user->id)->first();
            return $parentProfile && (int) $parentProfile->student_profile_id === (int) $gradebook->student_profile_id;
        }

        return false;
    }

    public function create(User $user): bool
    {
        return $user->hasRole(['super-admin', 'institution-admin', 'teacher']);
    }

    public function update(User $user, Gradebook $gradebook): bool
    {
        if ($user->hasRole(['super-admin', 'institution-admin'])) {
            return true;
        }

        if ($user->hasRole('teacher')) {
            $teacherProfile = TeacherProfile::where('user_id', $user->id)->first();
            return $teacherProfile && (int) $gradebook->course->teacher_profile_id === (int) $teacherProfile->id;
        }

        return false;
    }

    public function delete(User $user, Gradebook $gradebook): bool
    {
        return $this->update($user, $gradebook);
    }
}
```

### 6c. Create other Policies (template pattern)

Create similar policies for: `AssignmentPolicy`, `QuizPolicy`, `LessonPolicy`, `LiveClassPolicy`, `AttendanceRecordPolicy`, `CertificatePolicy`

**File:** `app/Policies/AssignmentPolicy.php`

```php
<?php

namespace App\Policies;

use App\Models\Assignment;
use App\Models\User;
use App\Models\TeacherProfile;

class AssignmentPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Assignment $assignment): bool
    {
        if ($user->hasRole(['super-admin', 'institution-admin'])) return true;
        
        if ($user->hasRole('teacher')) {
            $teacherProfile = TeacherProfile::where('user_id', $user->id)->first();
            return $teacherProfile && (int) $assignment->teacher_profile_id === (int) $teacherProfile->id;
        }

        // Student can view assignments for their courses
        if ($user->hasRole('student')) {
            return $assignment->course->enrollments()
                ->whereHas('studentProfile', fn($q) => $q->where('user_id', $user->id))
                ->exists();
        }

        return false;
    }

    public function create(User $user): bool
    {
        return $user->hasRole(['super-admin', 'institution-admin', 'teacher']);
    }

    public function update(User $user, Assignment $assignment): bool
    {
        if ($user->hasRole(['super-admin', 'institution-admin'])) return true;
        
        if ($user->hasRole('teacher')) {
            $teacherProfile = TeacherProfile::where('user_id', $user->id)->first();
            return $teacherProfile && (int) $assignment->teacher_profile_id === (int) $teacherProfile->id;
        }

        return false;
    }

    public function delete(User $user, Assignment $assignment): bool
    {
        return $this->update($user, $assignment);
    }
}
```

---

## 7. Institution Scoping Middleware

**File:** `app/Http/Middleware/ScopeByInstitution.php`

```php
<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use App\Models\InstitutionUser;
use Symfony\Component\HttpFoundation\Response;

class ScopeByInstitution
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (!$user) {
            abort(401, 'Unauthenticated.');
        }

        // Super-admin can access all institutions
        if ($user->hasRole('super-admin')) {
            return $next($request);
        }

        // Get institution ID for institution-admin
        if ($user->hasRole('institution-admin')) {
            $institutionUser = InstitutionUser::where('user_id', $user->id)->first();
            
            if (!$institutionUser) {
                abort(403, 'You are not associated with any institution.');
            }

            // Store institution ID in request for controllers to use
            $request->merge(['scope_institution_id' => $institutionUser->institution_id]);
            
            return $next($request);
        }

        // For other roles, pass through (they have their own scoping)
        return $next($request);
    }
}
```

**Register the middleware** in `bootstrap/app.php`:

```php
$middleware->alias([
    'role' => \Spatie\Permission\Middleware\RoleMiddleware::class,
    'permission' => \Spatie\Permission\Middleware\PermissionMiddleware::class,
    'role_or_permission' => \Spatie\Permission\Middleware\RoleOrPermissionMiddleware::class,
    'scope.institution' => \App\Http\Middleware\ScopeByInstitution::class,
]);
```

**Apply to routes** in `routes/api.php`:

```php
Route::middleware(['role:super-admin|institution-admin', 'scope.institution'])->group(function () {
    Route::apiResource('departments', DepartmentController::class);
    Route::apiResource('batches', BatchController::class);
    // ... other institution-admin routes
});
```

Then update `DepartmentController@index` to auto-scope:

```php
public function index(): JsonResponse
{
    $query = Department::query();
    
    if (request()->has('scope_institution_id')) {
        $query->where('institution_id', request('scope_institution_id'));
    }
    
    return response()->json([
        'message' => 'Departments fetched successfully.',
        'data' => $query->latest()->paginate(10),
    ]);
}
```

---

## 8. Missing Dashboard Routes

**File:** `routes/api.php` — Add teacher and parent dashboard routes

Add these inside the existing role middleware groups:

```php
// In the common access group (all roles)
Route::middleware([
    'role:super-admin|institution-admin|teacher|student|parent'
])->group(function () {
    // ... existing routes ...

    // Dashboard Routes
    Route::get('/dashboard/student/{studentProfile}', [StudentDashboardController::class, 'show']);
    Route::get('/dashboard/teacher', [TeacherDashboardController::class, 'show']);
    Route::get('/dashboard/parent', [ParentDashboardController::class, 'show']);
    
    // Parent child-specific endpoints
    Route::get('/parent/children', [ParentDashboardController::class, 'children']);
    Route::get('/parent/children/{studentProfile}/attendance', [ParentDashboardController::class, 'childAttendance']);
    Route::get('/parent/children/{studentProfile}/grades', [ParentDashboardController::class, 'childGrades']);
    Route::get('/parent/children/{studentProfile}/assignments', [ParentDashboardController::class, 'childAssignments']);
    Route::get('/parent/children/{studentProfile}/courses', [ParentDashboardController::class, 'childCourses']);
    Route::get('/parent/children/{studentProfile}/live-classes', [ParentDashboardController::class, 'childLiveClasses']);

    // Teacher-specific dashboard endpoints
    Route::get('/teacher/my-courses', [TeacherDashboardController::class, 'myCourses']);
    Route::get('/teacher/courses/{course}/students', [TeacherDashboardController::class, 'courseStudents']);
    Route::get('/teacher/courses/{course}/stats', [TeacherDashboardController::class, 'courseStats']);
});
```

---

## 9. Soft Deletes Migration

**File:** `database/migrations/2026_06_17_000000_add_soft_deletes_to_major_tables.php`

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('institutions', fn(Blueprint $t) => $t->softDeletes());
        Schema::table('departments', fn(Blueprint $t) => $t->softDeletes());
        Schema::table('batches', fn(Blueprint $t) => $t->softDeletes());
        Schema::table('courses', fn(Blueprint $t) => $t->softDeletes());
        Schema::table('course_sections', fn(Blueprint $t) => $t->softDeletes());
        Schema::table('lessons', fn(Blueprint $t) => $t->softDeletes());
        Schema::table('assignments', fn(Blueprint $t) => $t->softDeletes());
        Schema::table('quizzes', fn(Blueprint $t) => $t->softDeletes());
        Schema::table('question_banks', fn(Blueprint $t) => $t->softDeletes());
        Schema::table('gradebooks', fn(Blueprint $t) => $t->softDeletes());
        Schema::table('certificates', fn(Blueprint $t) => $t->softDeletes());
    }

    public function down(): void
    {
        Schema::table('institutions', fn(Blueprint $t) => $t->dropSoftDeletes());
        Schema::table('departments', fn(Blueprint $t) => $t->dropSoftDeletes());
        Schema::table('batches', fn(Blueprint $t) => $t->dropSoftDeletes());
        Schema::table('courses', fn(Blueprint $t) => $t->dropSoftDeletes());
        Schema::table('course_sections', fn(Blueprint $t) => $t->dropSoftDeletes());
        Schema::table('lessons', fn(Blueprint $t) => $t->dropSoftDeletes());
        Schema::table('assignments', fn(Blueprint $t) => $t->dropSoftDeletes());
        Schema::table('quizzes', fn(Blueprint $t) => $t->dropSoftDeletes());
        Schema::table('question_banks', fn(Blueprint $t) => $t->dropSoftDeletes());
        Schema::table('gradebooks', fn(Blueprint $t) => $t->dropSoftDeletes());
        Schema::table('certificates', fn(Blueprint $t) => $t->dropSoftDeletes());
    }
};
```

Then add `use Illuminate\Database\Eloquent\SoftDeletes;` to each model.

---

## 10. CORS Configuration

**File:** `config/cors.php` (create if not exists)

```php
<?php

return [
    'paths' => ['api/*', 'sanctum/csrf-cookie'],
    'allowed_methods' => ['*'],
    'allowed_origins' => [
        env('FRONTEND_URL', 'http://localhost:3000'),
        env('ADMIN_URL', 'http://localhost:5173'),
    ],
    'allowed_origins_patterns' => [],
    'allowed_headers' => ['*'],
    'exposed_headers' => [],
    'max_age' => 0,
    'supports_credentials' => true,
];
```

Add to `.env.example`:

```
FRONTEND_URL=http://localhost:3000
ADMIN_URL=http://localhost:5173
```

---

## 11. Registration Role Restriction

Already included in fix #2 above. The change is removing the `role` validation field and forcing `$roleName = 'student'`.

---

## 12. Password Complexity

Already included in fix #2 above. The validation rules are:

```php
'password' => [
    'required',
    'string',
    'min:8',
    'confirmed',
    'regex:/[A-Z]/',       // At least one uppercase
    'regex:/[a-z]/',       // At least one lowercase
    'regex:/[0-9]/',       // At least one number
    'regex:/[@$!%*#?&]/',  // At least one special character
],
```

---

## 13. My-Gradebooks/My-Certificates Filtering

**File:** `app/Http/Controllers/Api/GradebookController@index` — Already scoped in fix #4 above.

**File:** `app/Http/Controllers/Api/CertificateController@index` — Add scoping:

```php
public function index(): JsonResponse
{
    $user = Auth::user();
    
    $query = Certificate::with(['course', 'studentProfile.user']);
    
    if ($user->hasRole('student')) {
        $studentProfile = StudentProfile::where('user_id', $user->id)->first();
        if ($studentProfile) {
            $query->where('student_profile_id', $studentProfile->id);
        } else {
            $query->whereRaw('1 = 0');
        }
    } elseif ($user->hasRole('parent')) {
        $parentProfile = ParentProfile::where('user_id', $user->id)->first();
        if ($parentProfile && $parentProfile->student_profile_id) {
            $query->where('student_profile_id', $parentProfile->student_profile_id);
        } else {
            $query->whereRaw('1 = 0');
        }
    } elseif ($user->hasRole('teacher')) {
        $teacherProfile = TeacherProfile::where('user_id', $user->id)->first();
        if ($teacherProfile) {
            $query->whereHas('course', fn($q) => $q->where('teacher_profile_id', $teacherProfile->id));
        } else {
            $query->whereRaw('1 = 0');
        }
    }
    
    return response()->json([
        'message' => 'Certificates fetched successfully.',
        'data' => $query->latest()->paginate(20),
    ]);
}
```

---

## Implementation Order

Follow this sequence to apply fixes safely:

### Week 1 — Critical Security (Do FIRST)
1. ✅ AuthController fixes (token, registration, password)
2. ✅ StudentDashboardController ownership check
3. ✅ GradebookController authorization
4. ✅ QuizAttemptController pass/fail fix + timer enforcement
5. ✅ QuizAnswerController pass/fail fix + ownership checks
6. ✅ Rate limiting on auth routes

### Week 2 — Authorization Framework
7. Create Laravel Policies (Course, Gradebook, Assignment, Quiz)
8. Create institution-scoping middleware
9. Add missing dashboard routes
10. Filter `/my-gradebooks` and `/my-certificates`

### Week 3 — Data Integrity
11. Create soft-deletes migration
12. Configure CORS
13. Add audit logging middleware

### Week 4 — Testing & Verification
14. Write tests for all fixed endpoints
15. Run full security scan
16. Verify all role-based access

---

## Quick Verification Commands

```bash
# Test registration (should only allow student role)
curl -X POST http://localhost:8000/api/register \
  -H "Content-Type: application/json" \
  -d '{"name":"Test","email":"test@test.com","password":"Test@1234","password_confirmation":"Test@1234","role":"teacher"}'
# Should ignore "teacher" and create student

# Test rate limiting
for i in {1..10}; do curl -X POST http://localhost:8000/api/login -d "email=test@test.com&password=wrong"; done
# After 5 attempts, should return 429 Too Many Requests

# Test multi-device login
TOKEN1=$(curl -X POST http://localhost:8000/api/login -d "email=admin@edura.com&password=password" | jq -r '.token')
TOKEN2=$(curl -X POST http://localhost:8000/api/login -d "email=admin@edura.com&password=password" | jq -r '.token')
# Both tokens should be valid simultaneously

# Test student dashboard IDOR protection
STUDENT_TOKEN="..." # Student A's token
curl -H "Authorization: Bearer $STUDENT_TOKEN" \
  http://localhost:8000/api/student-dashboard/2
# Should fail with 403 if student A doesn't own profile ID 2