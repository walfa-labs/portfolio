<?php

namespace App\Http\Requests\Admin;

use App\Enums\ContentStatus;
use App\Models\Writing;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validate the admin create form for a writing (§5.9, §5.10).
 *
 * The admin surface is flat: rule keys are the form field names, unlike
 * `App\Http\Requests\Api\V1`, whose keys are paths into a JSON:API document
 * (§4.4). The two families therefore cannot be shared even though they express
 * the same rules; the policies are the shared layer (§5.7).
 *
 * `slug` is derived server-side from `title` and is never a form field (§2.4
 * item 2, §5.9) — supplying one is a validation failure rather than a silently
 * ignored input, so a client can never believe it set the slug.
 *
 * The image `alt` fields §5.10 puts on the writing form are attributes of the
 * `media` rows, not of this table (§7.8, §9.2), and are validated by
 * `StoreMediaRequest`; `og_image_path` is the only image column here and it
 * carries a path, not text.
 */
class StoreWritingRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * The `admin` middleware has already refused a non-administrator, so a
     * `false` here is the policy's answer, and therefore a `403` (§5.7).
     */
    public function authorize(): bool
    {
        return $this->user()?->can('create', Writing::class) ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * `title` is the only NOT NULL column without a default: the rest of the
     * per-type columns are nullable (§2.3). `body` is the Markdown source and
     * `body_html` / `reading_time_minutes` are derived from it on save, so they
     * are prohibited rather than ignored — a client that sends them is told
     * they are not its to set.
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

            'excerpt' => ['nullable', 'string', 'max:280'],
            'series_id' => ['nullable', 'integer', 'exists:writing_series,id'],
            'og_image_path' => ['nullable', 'string', 'max:2048'],
            'body_html' => ['prohibited'],
            'reading_time_minutes' => ['prohibited'],

            'tag_ids' => ['sometimes', 'array'],
            'tag_ids.*' => ['required', 'integer', 'exists:tags,id'],
        ];
    }
}
