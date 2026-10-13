<?php

namespace App\Http\Requests\Admin;

use App\Enums\ContentStatus;
use App\Models\WorkExperience;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use LogicException;

/**
 * Validate the admin edit form for a work experience (§5.9, §5.10).
 *
 * Rule keys are flat form field names; see `StoreWorkExperienceRequest` for why
 * the API's requests cannot be reused, and why `slug` is not writable.
 */
class UpdateWorkExperienceRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * The `admin` middleware has already refused a non-administrator, so a
     * `false` here is the policy's answer, and therefore a `403` (§5.7).
     */
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->workExperience()) ?? false;
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

            'employer' => ['sometimes', 'required', 'string', 'max:150'],
            'employer_url' => ['nullable', 'url', 'max:2048'],
            'location' => ['nullable', 'string', 'max:100'],
            'start_date' => ['sometimes', 'required', 'date'],
            'end_date' => ['nullable', 'date'],
            'highlights' => ['nullable', 'array'],
            'highlights.*' => ['string', 'max:250'],

            'tag_ids' => ['sometimes', 'array'],
            'tag_ids.*' => ['required', 'integer', 'exists:tags,id'],
        ];
    }

    /**
     * Get the work experience bound to the route.
     *
     * Route model binding leaves a `WorkExperience` here because the route
     * parameter is `{work_experience}` (§5.8); a route that still carries the
     * raw id is resolved by primary key. Anything else means the route
     * declaration and this request have drifted apart — a bug rather than a
     * client error, so it is not answered with a `404`.
     *
     * @throws LogicException
     */
    protected function workExperience(): WorkExperience
    {
        $workExperience = $this->route('work_experience');

        if ($workExperience instanceof WorkExperience) {
            return $workExperience;
        }

        if (! is_string($workExperience)) {
            throw new LogicException('The work_experience route parameter is neither an App\Models\WorkExperience nor an id.');
        }

        return WorkExperience::findOrFail($workExperience);
    }
}
