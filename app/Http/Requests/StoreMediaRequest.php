<?php

namespace App\Http\Requests;

use App\Models\Media;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Validate an admin media upload (§9.6, §9.10).
 *
 * The admin surface is a `web` route, so the rules are keyed by the form field
 * names rather than JSON:API paths. Two things are deliberately not trusted
 * from the client:
 *
 * - the `mimetypes` rule is finfo-based, so neither the declared type nor the
 *   file extension can talk the request past the allow-list; and
 * - `mediable_type` / `mediable_id` are only *hints* — `mediable()` resolves
 *   the morph target through the registered morph map, so a client can never
 *   name an arbitrary class. Owner media (`avatar`, `cv`) must resolve to the
 *   requesting user's own `User` row.
 *
 * The `alt` / `decorative` contract from §9.2 is enforced in `after()`: for
 * `cover` and `inline` a non-empty `alt` **or** `decorative: true` is required
 * (neither is a `422` on `alt`); for `logo`, `badge` and `avatar` an omitted
 * `alt` is fine and stores `NULL`. `alt()` is the single place the stored value
 * is derived, so `decorative: true` always stores `''` and never `NULL`.
 */
class StoreMediaRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('create', Media::class) ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $collection = $this->collection();
        $maxKb = (int) config("media.collections.{$collection}.max_kb", 1024);

        /** @var array<int, string> $mimetypes */
        $mimetypes = (array) config("media.collections.{$collection}.mimetypes", []);

        $fileRules = ['required', 'file', 'max:'.$maxKb];

        if ($mimetypes !== []) {
            $fileRules[] = 'mimetypes:'.implode(',', $mimetypes);
        }

        return [
            'collection' => ['required', 'string', Rule::in(array_keys((array) config('media.collections', [])))],
            'file' => $fileRules,
            'alt' => ['nullable', 'string', 'max:255'],
            'decorative' => ['sometimes', 'boolean'],
            'mediable_type' => ['required', 'string', Rule::in(array_keys(Relation::morphMap()))],
            'mediable_id' => ['required', 'integer', 'min:1'],
        ];
    }

    /**
     * Get the "after" validation callbacks.
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $collection = $this->collection();

                if (! array_key_exists($collection, (array) config('media.collections', []))) {
                    return;
                }

                if (
                    in_array($collection, ['cover', 'inline'], true)
                    && ! $this->hasAuthoredAltText()
                    && ! $this->boolean('decorative')
                ) {
                    $validator->errors()->add('alt', 'Provide alternative text, or mark the image as decorative.');
                }

                $mediable = $this->mediable();

                if (! $mediable instanceof Model) {
                    $validator->errors()->add('mediable_id', 'The selected media owner does not exist.');

                    return;
                }

                if (in_array($collection, ['avatar', 'cv'], true) && ! $mediable->is($this->user())) {
                    $validator->errors()->add('mediable_id', 'Owner media can only be attached to your own account.');
                }
            },
        ];
    }

    /**
     * The requested collection, or `''` when absent.
     */
    public function collection(): string
    {
        $collection = $this->input('collection');

        return is_string($collection) ? $collection : '';
    }

    /**
     * Resolve the morph target server-side through the registered morph map.
     *
     * Returns `null` for an unknown alias or a missing row; it never
     * instantiates a client-supplied class name.
     */
    public function mediable(): ?Model
    {
        $type = $this->input('mediable_type');
        $id = $this->input('mediable_id');

        if (! is_string($type) || ! is_numeric($id)) {
            return null;
        }

        $class = Relation::getMorphedModel($type);

        if ($class === null || ! is_subclass_of($class, Model::class)) {
            return null;
        }

        return $class::query()->find((int) $id);
    }

    /**
     * The value to store in `media.alt` (§9.2).
     *
     * `decorative: true` stores `''` — an explicit, recorded decision — never
     * `NULL`. An omitted `alt` stores `NULL`, which is permitted only for the
     * collections whose §7.8 rule is deterministic; the validator has already
     * refused `cover`/`inline` when neither an `alt` nor the flag was given.
     */
    public function alt(): ?string
    {
        if ($this->boolean('decorative')) {
            return '';
        }

        $alt = $this->input('alt');

        return is_string($alt) && $alt !== '' ? $alt : null;
    }

    /**
     * Whether a non-empty `alt` was supplied.
     */
    protected function hasAuthoredAltText(): bool
    {
        $alt = $this->input('alt');

        return is_string($alt) && trim($alt) !== '';
    }
}
