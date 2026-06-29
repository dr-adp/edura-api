<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateLiveClassRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'institution_id' => ['nullable', 'exists:institutions,id'],
            'course_id' => ['sometimes', 'exists:courses,id'],
            'course_section_id' => ['nullable', 'exists:course_sections,id'],
            'lesson_id' => ['nullable', 'exists:lessons,id'],
            'teacher_profile_id' => ['nullable', 'exists:teacher_profiles,id'],
            'batch_id' => ['nullable', 'exists:batches,id'],

            'title' => ['sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string'],

            'platform' => ['nullable', 'in:google_meet,zoom,jitsi,microsoft_teams,other'],
            'meeting_url' => ['sometimes', 'string', 'max:1000'],
            'meeting_id' => ['nullable', 'string', 'max:255'],
            'meeting_password' => ['nullable', 'string', 'max:255'],

            'scheduled_start_time' => ['sometimes', 'date'],
            'scheduled_end_time' => ['nullable', 'date', 'after:scheduled_start_time'],

            'recording_url' => ['nullable', 'string', 'max:1000'],
            'status' => ['nullable', 'in:scheduled,live,completed,cancelled'],
        ];
    }
}