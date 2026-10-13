<?php

namespace App\Http\Requests\Admin;

use App\Enums\ContentStatus;
use App\Models\Project;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use LogicException;

/**
 * Validate the admin edit form for a project (§5.9, §5.10).
 *
 * Rule keys are flat form field names; see `StoreProjectRequest` for why the
 * API's requests cannot be reused, and why `slug` is not writable.
 */
class UpdateProjectRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * The `admin` middleware has already refused a non-administrator, so a
     * `false` here is the policy's answer, and therefore a `403` (§5.7).
     */
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->project()) ?? false;
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

            'repo_url' => ['nullable', 'url', 'max:2048'],
            'live_url' => ['nullable', 'url', 'max:2048'],
            'started_on' => ['nullable', 'date'],
            'ended_on' => ['nullable', 'date'],
            'is_featured' => ['sometimes', 'required', 'boolean'],

            'tag_ids' => ['sometimes', 'array'],
            'tag_ids.*' => ['required', 'integer', 'exists:tags,id'],
        ];
    }

    /**
     * Get the project bound to the route.
     *
     * Route model binding leaves a `Project` here because the route parameter
     * is `{project}` (§5.8); a route that still carries the raw id is resolved
     * by primary key. Anything else means the route declaration and this
     * request have drifted apart — a bug rather than a client error, so it is
     * not answered with a `404`.
     *
     * @throws LogicException
     */
    protected function project(): Project
    {
        $project = $this->route('project');

        if ($project instanceof Project) {
            return $project;
        }

        if (! is_string($project)) {
            throw new LogicException('The project route parameter is neither an App\Models\Project nor an id.');
        }

        return Project::findOrFail($project);
    }
}
