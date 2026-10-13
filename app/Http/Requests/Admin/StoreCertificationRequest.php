<?php

namespace App\Http\Requests\Admin;

use App\Enums\ContentStatus;
use App\Models\Certification;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validate the admin create form for a certification (§5.9, §5.10).
 *
 * The admin surface is flat: rule keys are the form field names, unlike
 * `App\Http\Requests\Api\V1`, whose keys are paths into a JSON:API document
 * (§4.4). The two families therefore cannot be shared even though they express
 * the same rules; the policies are the shared layer (§5.7).
 *
 * `slug` is derived server-side from `title` and is never a form field (§2.4
 * item 2, §5.9) — supplying one is a validation failure rather than a silently
 * ignored input, so a client can never believe it set the slug.
 */
class StoreCertificationRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * The `admin` middleware has already refused a non-administrator, so a
     * `false` here is the policy's answer, and therefore a `403` (§5.7).
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
     * `tag_ids` is the admin contract for the pivot: a flat list of existing
     * ids, not the JSON:API relationship object the API accepts (§5.9).
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:150'],
            'summary' => ['nullable', 'string'],
            'body' => ['nullable', 'string'],
            'status' => ['sometimes', 'required', Rule::enum(ContentStatus::class)],
            'published_at' => ['nullable', 'date'],
            'is_placeholder' => ['sometimes', 'required', 'boolean'],
            'meta_title' => ['nullable', 'string', 'max:60'],
            'meta_description' => ['nullable', 'string', 'max:160'],
            'sort_order' => ['sometimes', 'required', 'integer', 'min:0'],
            'slug' => ['prohibited'],

            'issuer' => ['required', 'string', 'max:150'],
            'credential_id' => ['nullable', 'string', 'max:100', 'regex:/^[A-Za-z0-9-]{1,100}$/'],
            'credential_url' => ['nullable', 'url', 'max:2048'],
            'issued_on' => ['required', 'date'],
            'expires_on' => ['nullable', 'date'],

            'tag_ids' => ['sometimes', 'array'],
            'tag_ids.*' => ['required', 'integer', 'exists:tags,id'],
        ];
    }
}
