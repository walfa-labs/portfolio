<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\ContentStatus;
use App\Models\Award;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use LogicException;

/**
 * Validate the JSON:API document that replaces or updates an award.
 *
 * One request serves both `PUT` (a full replacement) and `PATCH` (a partial
 * update), because `Route::apiResource` maps both verbs to `update` (§4.5).
 * Rule keys are paths into the request document, so the error key and the
 * `source.pointer` are the same path with `.` replaced by `/` (§4.6).
 */
class UpdateAwardRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * An absent session is a `401` from the `api-admin` group's `auth`
     * middleware, so a `false` here is always the policy denial, and therefore
     * a `403` (§4.11).
     */
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->award()) ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'data.attributes.title' => $this->requiredColumn(['string', 'max:150']),
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

            'data.attributes.awarding_body' => $this->requiredColumn(['string', 'max:150']),
            'data.attributes.awarded_at' => $this->requiredColumn(['date']),

            'data.relationships' => ['sometimes', 'array'],
            'data.relationships.tags' => ['sometimes', 'array'],
            'data.relationships.tags.data' => ['sometimes', 'array'],
            'data.relationships.tags.data.*.type' => ['sometimes', 'string', Rule::in(['tags'])],
            'data.relationships.tags.data.*.id' => ['required', 'integer', 'exists:tags,id'],
        ];
    }

    /**
     * Get the award bound to the route.
     *
     * Route model binding leaves an `Award` here when the controller type-hints
     * one; a route that still carries the raw id is resolved by primary key.
     * Anything else means the route declaration and this request have drifted
     * apart — a bug rather than a client error, so it is not answered with a
     * `404`.
     *
     * @throws LogicException
     */
    protected function award(): Award
    {
        $award = $this->route('award');

        if ($award instanceof Award) {
            return $award;
        }

        if (! is_string($award)) {
            throw new LogicException('The award route parameter is neither an App\Models\Award nor an id.');
        }

        return Award::findOrFail($award);
    }

    /**
     * Rules for a NOT NULL column that has no database default.
     *
     * §4.5 states the principle — a member the column cannot reset by omission
     * is a `422` when a `PUT` omits it — and names `title` as the example. The
     * same principle covers every per-type NOT NULL column (`awarding_body`,
     * `awarded_at`, …), which §4.5's "every other writable attribute is
     * nullable" sentence overlooks. A `PATCH` leaves omitted members untouched,
     * so the same column is required only when the member is present at all.
     *
     * @param  list<mixed>  $rules
     * @return list<mixed>
     */
    protected function requiredColumn(array $rules = []): array
    {
        return $this->isMethod('PUT')
            ? ['required', ...$rules]
            : ['sometimes', 'required', ...$rules];
    }
}
