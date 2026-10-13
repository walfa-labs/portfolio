<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\ContentStatus;
use App\Models\WorkExperience;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validate the JSON:API document that creates a work experience (§4.5).
 *
 * The body is a JSON:API resource object, so every rule key is the path of the
 * member inside that document (`data.attributes.employer`). Error keys are
 * therefore RFC 6901 pointers with `/` in place of `.`, which is exactly what
 * `source.pointer` carries (§4.6) — `data.attributes.slug` reports
 * `/data/attributes/slug`.
 */
class StoreWorkExperienceRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * The `api-admin` group has already run `auth`, so an absent session is a
     * `401` before this method is reached; a `false` here is always the policy
     * denial, and therefore a `403` (§4.11).
     */
    public function authorize(): bool
    {
        return $this->user()?->can('create', WorkExperience::class) ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * `title`, `employer` and `start_date` are the NOT NULL columns with no
     * default, so a create cannot omit them (§2.2, §2.3).
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

            'data.attributes.employer' => ['required', 'string', 'max:150'],
            'data.attributes.employer_url' => ['nullable', 'url', 'max:2048'],
            'data.attributes.location' => ['nullable', 'string', 'max:100'],
            'data.attributes.start_date' => ['required', 'date'],
            'data.attributes.end_date' => ['nullable', 'date'],
            'data.attributes.highlights' => ['nullable', 'array'],
            'data.attributes.highlights.*' => ['string', 'max:250'],

            'data.relationships' => ['sometimes', 'array'],
            'data.relationships.tags' => ['sometimes', 'array'],
            'data.relationships.tags.data' => ['sometimes', 'array'],
            'data.relationships.tags.data.*.type' => ['sometimes', 'string', Rule::in(['tags'])],
            'data.relationships.tags.data.*.id' => ['required', 'integer', 'exists:tags,id'],
        ];
    }
}
