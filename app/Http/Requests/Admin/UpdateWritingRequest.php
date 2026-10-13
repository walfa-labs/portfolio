<?php

namespace App\Http\Requests\Admin;

use App\Enums\ContentStatus;
use App\Models\Writing;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use LogicException;

/**
 * Validate the admin edit form for a writing (§5.9, §5.10).
 *
 * Rule keys are flat form field names; see `StoreWritingRequest` for why the
 * API's requests cannot be reused, why `slug` is not writable, and why the
 * media `alt` fields do not appear here.
 */
class UpdateWritingRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * The `admin` middleware has already refused a non-administrator, so a
     * `false` here is the policy's answer, and therefore a `403` (§5.7).
     */
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->writing()) ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * `title` is required even though the route is a `PATCH`: the column is NOT
     * NULL with no default, so an omission cannot mean "reset to the default"
     * (§4.5's carve-out). No other column on this table is NOT NULL without a
     * default, so every remaining rule is optional or nullable — a `PATCH`
     * leaves an omitted field untouched (§5.8).
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

    /**
     * Get the writing bound to the route.
     *
     * Route model binding leaves a `Writing` here because the route parameter
     * is `{writing}` (§5.8); a route that still carries the raw id is resolved
     * by primary key. The public route binds the slug instead (§2.4 item 2),
     * but the admin area addresses the row by its primary key so a rename
     * cannot retarget the edit form. Anything else means the route declaration
     * and this request have drifted apart — a bug rather than a client error,
     * so it is not answered with a `404`.
     *
     * @throws LogicException
     */
    protected function writing(): Writing
    {
        $writing = $this->route('writing');

        if ($writing instanceof Writing) {
            return $writing;
        }

        if (! is_string($writing)) {
            throw new LogicException('The writing route parameter is neither an App\Models\Writing nor an id.');
        }

        return Writing::findOrFail($writing);
    }
}
