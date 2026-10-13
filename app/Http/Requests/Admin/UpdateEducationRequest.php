<?php

namespace App\Http\Requests\Admin;

use App\Enums\ContentStatus;
use App\Models\Education;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use LogicException;

/**
 * Validate the admin edit form for an education (§5.9, §5.10).
 *
 * Rule keys are flat form field names; see `StoreEducationRequest` for why the
 * API's requests cannot be reused, and why `slug` is not writable.
 */
class UpdateEducationRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * The `admin` middleware has already refused a non-administrator, so a
     * `false` here is the policy's answer, and therefore a `403` (§5.7).
     */
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->education()) ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * `title` is required even though the route is a `PATCH`: the column is NOT
     * NULL with no default, so an omission cannot mean "reset to the default"
     * (§4.5's carve-out). Every other NOT NULL column is required only when it
     * is present, because a `PATCH` leaves an omitted field untouched — the
     * admin form submits the whole payload, but the route is partial by
     * contract (§5.8).
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

            'institution' => ['sometimes', 'required', 'string', 'max:150'],
            'institution_url' => ['nullable', 'url', 'max:2048'],
            'field_of_study' => ['nullable', 'string', 'max:150'],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date'],

            'tag_ids' => ['sometimes', 'array'],
            'tag_ids.*' => ['required', 'integer', 'exists:tags,id'],
        ];
    }

    /**
     * Get the education bound to the route.
     *
     * Route model binding leaves an `Education` here because the route
     * parameter is `{education}` (§5.8); a route that still carries the raw id
     * is resolved by primary key. Anything else means the route declaration and
     * this request have drifted apart — a bug rather than a client error, so it
     * is not answered with a `404`.
     *
     * @throws LogicException
     */
    protected function education(): Education
    {
        $education = $this->route('education');

        if ($education instanceof Education) {
            return $education;
        }

        if (! is_string($education)) {
            throw new LogicException('The education route parameter is neither an App\Models\Education nor an id.');
        }

        return Education::findOrFail($education);
    }
}
