<?php

namespace App\Http\Requests\Api\V1;

use App\Models\Tag;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validate the JSON:API document that creates a tag (§4.5).
 *
 * A tag is a full resource with the same six methods as the seven content
 * types (§4.2), but its table is its own: `name`, `description` and
 * `is_placeholder` are the only writable members, so the content attributes
 * (`status`, `published_at`, `meta_*`, `sort_order`, …) do not exist here and
 * supplying one is a `422` rather than a silent drop (§2.5).
 *
 * Rule keys are paths into the request document, so the error key and the
 * `source.pointer` are the same path with `.` replaced by `/` (§4.6).
 */
class StoreTagRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * An absent session is a `401` from the `api-admin` group's `auth`
     * middleware, so a `false` here is always a `403` (§4.11).
     */
    public function authorize(): bool
    {
        return $this->user()?->can('create', Tag::class) ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'data.attributes.name' => ['required', 'string', 'max:60'],
            'data.attributes.description' => ['nullable', 'string'],
            'data.attributes.is_placeholder' => ['sometimes', 'required', 'boolean'],

            'data.attributes.id' => ['prohibited'],
            'data.attributes.slug' => ['prohibited'],
            'data.attributes.created_at' => ['prohibited'],
            'data.attributes.updated_at' => ['prohibited'],
            'data.attributes.deleted_at' => ['prohibited'],

            'data.attributes.title' => ['prohibited'],
            'data.attributes.summary' => ['prohibited'],
            'data.attributes.body' => ['prohibited'],
            'data.attributes.status' => ['prohibited'],
            'data.attributes.published_at' => ['prohibited'],
            'data.attributes.meta_title' => ['prohibited'],
            'data.attributes.meta_description' => ['prohibited'],
            'data.attributes.sort_order' => ['prohibited'],
        ];
    }
}
