<?php

namespace App\Http\Requests\Api\V1;

use App\Models\Tag;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use LogicException;

/**
 * Validate the JSON:API document that replaces or updates a tag (§4.5).
 *
 * One request serves both `PUT` (a full replacement) and `PATCH` (a partial
 * update), because `Route::apiResource` maps both verbs to `update`. A tag's
 * only writable members are `name`, `description` and `is_placeholder`; the
 * content attributes are prohibited rather than ignored (§2.5).
 */
class UpdateTagRequest extends FormRequest
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
        return $this->user()?->can('update', $this->tag()) ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'data.attributes.name' => $this->requiredColumn(['string', 'max:60']),
            'data.attributes.description' => ['nullable', 'string'],
            'data.attributes.is_placeholder' => ['sometimes', 'required', 'boolean'],

            'data.attributes.id' => ['prohibited'],
            'data.attributes.slug' => ['prohibited'],
            'data.attributes.created_at' => ['prohibited'],
            'data.attributes.updated_at' => ['prohibited'],
            'data.attributes.deleted_at' => ['prohibited'],

            'data.attributes.title' => ['prohibited'],
            'data.attributes.summary' => ['prohibited'],
            'data.attributes.body' => ['prohibited'],
            'data.attributes.status' => ['prohibited'],
            'data.attributes.published_at' => ['prohibited'],
            'data.attributes.meta_title' => ['prohibited'],
            'data.attributes.meta_description' => ['prohibited'],
            'data.attributes.sort_order' => ['prohibited'],
        ];
    }

    /**
     * Get the tag bound to the route.
     *
     * Route model binding leaves a `Tag` here when the controller type-hints
     * one; a route that still carries the raw id is resolved by primary key.
     * `TagController` takes the id, and the API addresses a tag by its numeric
     * id even though the public route binds the slug (§2.4). Anything else
     * means the route declaration and this request have drifted apart — a bug
     * rather than a client error, so it is not answered with a `404`.
     *
     * @throws LogicException
     */
    protected function tag(): Tag
    {
        $tag = $this->route('tag');

        if ($tag instanceof Tag) {
            return $tag;
        }

        if (! is_string($tag)) {
            throw new LogicException('The tag route parameter is neither an App\Models\Tag nor an id.');
        }

        return Tag::findOrFail($tag);
    }

    /**
     * Rules for a NOT NULL column that has no database default.
     *
     * §4.5 states the principle — a member the column cannot reset by omission
     * is a `422` when a `PUT` omits it — and names `title` as the example. For
     * a tag that column is `name`. A `PATCH` leaves omitted members untouched,
     * so `name` is required only when the member is present at all.
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
