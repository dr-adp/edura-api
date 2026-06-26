<?php

namespace App\Http\Controllers\Api;

use App\Models\LessonResource;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Http\Controllers\Api\BaseApiController;
use Illuminate\Support\Facades\Storage;
use App\Models\Lesson;
use App\Models\InstitutionUser;
use App\Models\TeacherProfile;
use App\Models\User;
use Illuminate\Support\Facades\Auth;

class LessonResourceController extends BaseApiController
{

    private function authorizeLessonResourceAccess(
        LessonResource $lessonResource
    ): void {

        /** @var User $user */
        $user = Auth::user();

        if ($user->hasRole('super-admin')) {
            return;
        }

        $lessonResource->loadMissing(
            'lesson.course'
        );

        if ($user->hasRole('institution-admin')) {

            $institutionUser = InstitutionUser::where(
                'user_id',
                $user->id
            )->first();

            if (
                !$institutionUser ||
                (int) $lessonResource->lesson->course->institution_id !==
                (int) $institutionUser->institution_id
            ) {

                abort(
                    403,
                    'Unauthorized institution access.'
                );
            }

            return;
        }

        if ($user->hasRole('teacher')) {

            $teacherProfile = TeacherProfile::where(
                'user_id',
                $user->id
            )->first();

            if (
                !$teacherProfile ||
                (int) $lessonResource->lesson->course->teacher_profile_id !==
                (int) $teacherProfile->id
            ) {

                abort(
                    403,
                    'Unauthorized lesson resource.'
                );
            }

            return;
        }

        abort(
            403,
            'Unauthorized.'
        );
    }

    private function authorizeLessonResourceManagement(
        Lesson $lesson
    ): void {

        /** @var User $user */
        $user = Auth::user();

        if ($user->hasRole('super-admin')) {
            return;
        }

        $lesson->loadMissing(
            'course'
        );

        if ($user->hasRole('institution-admin')) {

            $institutionUser = InstitutionUser::where(
                'user_id',
                $user->id
            )->first();

            if (
                !$institutionUser ||
                (int) $lesson->course->institution_id !==
                (int) $institutionUser->institution_id
            ) {

                abort(
                    403,
                    'Unauthorized institution access.'
                );
            }

            return;
        }

        if ($user->hasRole('teacher')) {

            $teacherProfile = TeacherProfile::where(
                'user_id',
                $user->id
            )->first();

            if (
                !$teacherProfile ||
                (int) $lesson->course->teacher_profile_id !==
                (int) $teacherProfile->id
            ) {

                abort(
                    403,
                    'Unauthorized lesson.'
                );
            }

            return;
        }

        abort(
            403,
            'Unauthorized.'
        );
    }

    public function index(): JsonResponse
    {
        /** @var User $user */
        $user = Auth::user();

        $query = LessonResource::with([
            'lesson.course',
        ]);

        if ($user->hasRole('super-admin')) {

            // Full access

        } elseif ($user->hasRole('institution-admin')) {

            $institutionUser = InstitutionUser::where(
                'user_id',
                $user->id
            )->first();

            if (!$institutionUser) {
                abort(403, 'Institution profile not found.');
            }

            $query->whereHas(
                'lesson.course',
                function ($q) use ($institutionUser) {
                    $q->where(
                        'institution_id',
                        $institutionUser->institution_id
                    );
                }
            );
        } elseif ($user->hasRole('teacher')) {

            $teacherProfile = TeacherProfile::where(
                'user_id',
                $user->id
            )->first();

            if (!$teacherProfile) {
                abort(403, 'Teacher profile not found.');
            }

            $query->whereHas(
                'lesson.course',
                function ($q) use ($teacherProfile) {
                    $q->where(
                        'teacher_profile_id',
                        $teacherProfile->id
                    );
                }
            );
        } else {

            abort(403, 'Unauthorized.');
        }

        $resources = $query
            ->latest()
            ->paginate(20);

        return $this->successResponse(
            $resources,
            'Lesson resources fetched successfully.'
        );
    }

    public function store(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = Auth::user();

        /*
    |--------------------------------------------------------------------------
    | Validation
    |--------------------------------------------------------------------------
    */
        $validated = $request->validate([
            'lesson_id' => ['required', 'exists:lessons,id'],
            'title' => ['required', 'string', 'max:255'],

            'resource_type' => ['nullable', 'in:text,pdf,video,image,link,document,other'],
            'video_provider' => ['nullable', 'in:local,youtube,vimeo,bunny,cloudflare,external'],

            'content' => ['nullable', 'string'],
            'external_url' => ['nullable', 'string', 'max:1000'],

            'video_duration_minutes' => ['nullable', 'integer', 'min:1'],
            'video_size_mb' => ['nullable', 'numeric', 'min:0'],

            'file' => [
                'nullable',
                'file',
                'mimes:pdf,doc,docx,ppt,pptx,jpg,jpeg,png,webp,mp4,mov,avi,mkv,webm',
                'max:512000',
            ],

            'sort_order' => ['nullable', 'integer'],
            'status' => ['nullable', 'in:active,inactive'],
        ]);

        /*
    |--------------------------------------------------------------------------
    | Load Lesson
    |--------------------------------------------------------------------------
    */
        $lesson = Lesson::with('course')->findOrFail(
            $validated['lesson_id']
        );

        /*
    |--------------------------------------------------------------------------
    | Authorization
    |--------------------------------------------------------------------------
    */
        $this->authorizeLessonResourceManagement(
            $lesson
        );

        /*
    |--------------------------------------------------------------------------
    | File Upload
    |--------------------------------------------------------------------------
    */
        if ($request->hasFile('file')) {
            $validated['file_path'] = $request
                ->file('file')
                ->store('lesson-resources', 'public');
        }

        unset($validated['file']);

        /*
    |--------------------------------------------------------------------------
    | Create
    |--------------------------------------------------------------------------
    */
        $lessonResource = LessonResource::create(
            $validated
        );

        return $this->successResponse(
            $lessonResource->load([
                'lesson'
            ]),
            'Lesson resource created successfully.',
            201
        );
    }

    public function show(
        LessonResource $lessonResource
    ): JsonResponse {

        /*
    |--------------------------------------------------------------------------
    | Authorization
    |--------------------------------------------------------------------------
    */
        $this->authorizeLessonResourceAccess(
            $lessonResource
        );

        return $this->successResponse(
            $lessonResource->load([
                'lesson'
            ]),
            'Lesson resource fetched successfully.'
        );
    }

    public function update(
        Request $request,
        LessonResource $lessonResource
    ): JsonResponse {

        /*
    |--------------------------------------------------------------------------
    | Authorization
    |--------------------------------------------------------------------------
    */
        $this->authorizeLessonResourceAccess(
            $lessonResource
        );

        /*
    |--------------------------------------------------------------------------
    | Validation
    |--------------------------------------------------------------------------
    */
        $validated = $request->validate([
            'lesson_id' => ['sometimes', 'exists:lessons,id'],
            'title' => ['sometimes', 'string', 'max:255'],

            'resource_type' => ['nullable', 'in:text,pdf,video,image,link,document,other'],
            'video_provider' => ['nullable', 'in:local,youtube,vimeo,bunny,cloudflare,external'],

            'content' => ['nullable', 'string'],
            'external_url' => ['nullable', 'string', 'max:1000'],

            'video_duration_minutes' => ['nullable', 'integer', 'min:1'],
            'video_size_mb' => ['nullable', 'numeric', 'min:0'],

            'file' => [
                'nullable',
                'file',
                'mimes:pdf,doc,docx,ppt,pptx,jpg,jpeg,png,webp,mp4,mov,avi,mkv,webm',
                'max:512000',
            ],

            'sort_order' => ['nullable', 'integer'],
            'status' => ['nullable', 'in:active,inactive'],
        ]);

        /*
    |--------------------------------------------------------------------------
    | Lesson Change Authorization
    |--------------------------------------------------------------------------
    */
        if (isset($validated['lesson_id'])) {

            $lesson = Lesson::with('course')->findOrFail(
                $validated['lesson_id']
            );

            $this->authorizeLessonResourceManagement(
                $lesson
            );
        }

        /*
    |--------------------------------------------------------------------------
    | File Upload
    |--------------------------------------------------------------------------
    */
        if ($request->hasFile('file')) {

            $validated['file_path'] = $request
                ->file('file')
                ->store('lesson-resources', 'public');
        }

        unset($validated['file']);

        /*
    |--------------------------------------------------------------------------
    | Update
    |--------------------------------------------------------------------------
    */
        $lessonResource->update(
            $validated
        );

        return $this->successResponse(
            $lessonResource
                ->fresh()
                ->load([
                    'lesson'
                ]),
            'Lesson resource updated successfully.'
        );
    }

    public function destroy(
        LessonResource $lessonResource
    ): JsonResponse {

        /*
    |--------------------------------------------------------------------------
    | Authorization
    |--------------------------------------------------------------------------
    */
        $this->authorizeLessonResourceAccess(
            $lessonResource
        );

        /*
    |--------------------------------------------------------------------------
    | Soft Delete
    |--------------------------------------------------------------------------
    */
        $lessonResource->delete();

        return $this->successResponse(
            null,
            'Lesson resource deleted successfully.'
        );
    }
}
