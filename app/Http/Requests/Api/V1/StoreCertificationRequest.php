<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\ContentStatus;
use App\Models\Certification;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validate the JSON:API document that creates a certification (§4.5).
 *
 * Rule keys are paths into the request document, so the error key and the
 * `source.pointer` are the same path with `.` replaced by `/` (§4.6).
 */
class StoreCertificationRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * An absent session is a `401` from the `api-admin` group's `auth`
     * middleware, so a `false` here is always a `403` (§4.11).
     */
    public function authorize(): bool
    {
        return $this->user()?->can('create', Certification::class) ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * `title`, `issuer` and `issued_on` are the NOT NULL columns with no
     * default (§2.3). `credential_id` is an opaque identifier rather than a
     * URL, so it is pinned to the §2.3 pattern; `credential_url` is the URL.
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

            'data.attributes.issuer' => ['required', 'string', 'max:150'],
            'data.attributes.credential_id' => ['nullable', 'string', 'max:100', 'regex:/^[A-Za-z0-9-]{1,100}$/'],
            'data.attributes.credential_url' => ['nullable', 'url', 'max:2048'],
            'data.attributes.issued_on' => ['required', 'date'],
            'data.attributes.expires_on' => ['nullable', 'date'],

            'data.relationships' => ['sometimes', 'array'],
            'data.relationships.tags' => ['sometimes', 'array'],
            'data.relationships.tags.data' => ['sometimes', 'array'],
            'data.relationships.tags.data.*.type' => ['sometimes', 'string', Rule::in(['tags'])],
            'data.relationships.tags.data.*.id' => ['required', 'integer', 'exists:tags,id'],
        ];
    }
}
