<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\ContentStatus;
use App\Models\Publication;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validate the JSON:API document that creates a publication (§4.5).
 *
 * Rule keys are paths into the request document, so the error key and the
 * `source.pointer` are the same path with `.` replaced by `/` (§4.6).
 */
class StorePublicationRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * An absent session is a `401` from the `api-admin` group's `auth`
     * middleware, so a `false` here is always a `403` (§4.11).
     */
    public function authorize(): bool
    {
        return $this->user()?->can('create', Publication::class) ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * `title`, `venue` and `issued_on` are the NOT NULL columns with no
     * default; `doi` stores the identifier alone — `doi_url` is a derived
     * accessor, not a column — so it is pinned to the §2.3 pattern rather than
     * validated as a URL.
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

            'data.attributes.venue' => ['required', 'string', 'max:255'],
            'data.attributes.authors' => ['nullable', 'string', 'max:255'],
            'data.attributes.doi' => ['nullable', 'string', 'max:100', 'regex:#^10\.\d{4,9}/\S+$#'],
            'data.attributes.issued_on' => ['required', 'date'],

            'data.relationships' => ['sometimes', 'array'],
            'data.relationships.tags' => ['sometimes', 'array'],
            'data.relationships.tags.data' => ['sometimes', 'array'],
            'data.relationships.tags.data.*.type' => ['sometimes', 'string', Rule::in(['tags'])],
            'data.relationships.tags.data.*.id' => ['required', 'integer', 'exists:tags,id'],
        ];
    }
}
