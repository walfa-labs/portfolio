<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\ContentStatus;
use App\Models\Writing;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validate the JSON:API document that creates a writing (§4.5).
 *
 * Rule keys are paths into the request document, so the error key and the
 * `source.pointer` are the same path with `.` replaced by `/` (§4.6).
 */
class StoreWritingRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * An absent session is a `401` from the `api-admin` group's `auth`
     * middleware, so a `false` here is always a `403` (§4.11).
     */
    public function authorize(): bool
    {
        return $this->user()?->can('create', Writing::class) ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * `title` is the only NOT NULL column with no default. `body_html` and
     * `reading_time_minutes` are derived from `body` on save (§2.6), so they
     * are prohibited here as well as never being writable (§4.5).
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'data.attributes.title' => ['required', 'string', 'max:150'],
            'data.attributes.summary' => ['nullable', 'string'],
            'data.attributes.body' => ['nullable', 'string'],
            'data.attributes.status' => ['sometimes', 'required', Rule::enum(ContentStatus::class)],
            'data.attributes.published_at' => ['nullable', 'date'],
            'data.attributes.meta_title' => ['nullable', 'string', 'max:60'],
            'data.attributes.meta_description' => ['nullable', 'string', 'max:160'],
            'data.attributes.sort_order' => ['sometimes', 'required', 'integer', 'min:0'],
            'data.attributes.is_placeholder' => ['sometimes', 'required', 'boolean'],

            'data.attributes.id' => ['prohibited'],
            'data.attributes.slug' => ['prohibited'],
            'data.attributes.created_at' => ['prohibited'],
            'data.attributes.updated_at' => ['prohibited'],
            'data.attributes.deleted_at' => ['prohibited'],
            'data.attributes.body_html' => ['prohibited'],
            'data.attributes.reading_time_minutes' => ['prohibited'],

            'data.attributes.excerpt' => ['nullable', 'string', 'max:280'],
            'data.attributes.series_id' => ['nullable', 'integer', 'exists:writing_series,id'],
            'data.attributes.og_image_path' => ['nullable', 'string', 'max:2048'],

            'data.relationships' => ['sometimes', 'array'],
            'data.relationships.tags' => ['sometimes', 'array'],
            'data.relationships.tags.data' => ['sometimes', 'array'],
            'data.relationships.tags.data.*.type' => ['sometimes', 'string', Rule::in(['tags'])],
            'data.relationships.tags.data.*.id' => ['required', 'integer', 'exists:tags,id'],
        ];
    }
}
