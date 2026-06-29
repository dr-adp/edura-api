<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreLessonResourceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
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
        ];
    }
}
