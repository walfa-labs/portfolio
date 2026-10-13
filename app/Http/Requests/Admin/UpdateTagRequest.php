<?php

namespace App\Http\Requests\Admin;

use App\Models\Tag;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use LogicException;

/**
 * Validate the admin edit form for a tag (§5.9, §5.10).
 *
 * Rule keys are flat form field names; see `StoreTagRequest` for why the API's
 * requests cannot be reused, and why `slug` and the content attributes are not
 * writable here.
 */
class UpdateTagRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * The `admin` middleware has already refused a non-administrator, so a
     * `false` here is the policy's answer, and therefore a `403` (§5.7).
     */
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->tag()) ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * `name` is required even though the route is a `PATCH`: the column is NOT
     * NULL with no default, so an omission cannot mean "reset to the default"
     * (§4.5's carve-out, applied to the tag's key column). No other column on
     * this table is NOT NULL without a default, so every remaining rule is
     * optional or nullable — a `PATCH` leaves an omitted field untouched
     * (§5.8).
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

    /**
     * Get the tag bound to the route.
     *
     * Route model binding leaves a `Tag` here because the admin route parameter
     * is `{tag}` and the controller type-hints the model (§5.8). A `Tag` binds
     * by slug, which is what `/tags/{tag:slug}` uses publicly (§3.7), so a
     * route that still carries the raw parameter is resolved by slug or, when
     * that matches nothing, by primary key — the admin list links by key, the
     * public site links by slug, and neither has to know which one arrived.
     * Anything else means the route declaration and this request have drifted
     * apart — a bug rather than a client error, so it is not answered with a
     * `404`.
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
            throw new LogicException('The tag route parameter is neither an App\Models\Tag nor a route key.');
        }

        return Tag::query()
            ->where('slug', $tag)
            ->orWhereKey($tag)
            ->firstOrFail();
    }
}
