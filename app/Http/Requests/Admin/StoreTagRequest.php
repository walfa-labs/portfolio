<?php

namespace App\Http\Requests\Admin;

use App\Models\Tag;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validate the admin create form for a tag (§5.9, §5.10).
 *
 * The admin surface is flat: rule keys are the form field names, unlike
 * `App\Http\Requests\Api\V1`, whose keys are paths into a JSON:API document
 * (§4.4). The two families therefore cannot be shared even though they express
 * the same rules; the policies are the shared layer (§5.7).
 *
 * A tag is not a content type: it has `name` rather than `title`, no
 * `status`/`published_at` pair and no `sort_order` (§2.5), so the content
 * attributes are prohibited rather than ignored. `slug` is derived from `name`
 * and is never a form field (§2.4 item 2), and tags carry no pivot of their
 * own, so there is no `tag_ids` here either.
 */
class StoreTagRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * The `admin` middleware has already refused a non-administrator, so a
     * `false` here is the policy's answer, and therefore a `403` (§5.7).
     */
    public function authorize(): bool
    {
        return $this->user()?->can('create', Tag::class) ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * `name` is the only NOT NULL column without a default; `description` is
     * nullable and `is_placeholder` carries a `false` default (§2.5).
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:60'],
            'description' => ['nullable', 'string'],
            'is_placeholder' => ['sometimes', 'required', 'boolean'],
            'slug' => ['prohibited'],

            'title' => ['prohibited'],
            'summary' => ['prohibited'],
            'body' => ['prohibited'],
            'status' => ['prohibited'],
            'published_at' => ['prohibited'],
            'meta_title' => ['prohibited'],
            'meta_description' => ['prohibited'],
            'sort_order' => ['prohibited'],
        ];
    }
}
