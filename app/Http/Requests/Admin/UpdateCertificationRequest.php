<?php

namespace App\Http\Requests\Admin;

use App\Enums\ContentStatus;
use App\Models\Certification;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use LogicException;

/**
 * Validate the admin edit form for a certification (§5.9, §5.10).
 *
 * Rule keys are flat form field names; see `StoreCertificationRequest` for why
 * the API's requests cannot be reused, and why `slug` is not writable.
 */
class UpdateCertificationRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * The `admin` middleware has already refused a non-administrator, so a
     * `false` here is the policy's answer, and therefore a `403` (§5.7).
     */
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->certification()) ?? false;
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

            'issuer' => ['sometimes', 'required', 'string', 'max:150'],
            'credential_id' => ['nullable', 'string', 'max:100', 'regex:/^[A-Za-z0-9-]{1,100}$/'],
            'credential_url' => ['nullable', 'url', 'max:2048'],
            'issued_on' => ['sometimes', 'required', 'date'],
            'expires_on' => ['nullable', 'date'],

            'tag_ids' => ['sometimes', 'array'],
            'tag_ids.*' => ['required', 'integer', 'exists:tags,id'],
        ];
    }

    /**
     * Get the certification bound to the route.
     *
     * Route model binding leaves a `Certification` here because the route
     * parameter is `{certification}` (§5.8); a route that still carries the raw
     * id is resolved by primary key. Anything else means the route declaration
     * and this request have drifted apart — a bug rather than a client error,
     * so it is not answered with a `404`.
     *
     * @throws LogicException
     */
    protected function certification(): Certification
    {
        $certification = $this->route('certification');

        if ($certification instanceof Certification) {
            return $certification;
        }

        if (! is_string($certification)) {
            throw new LogicException('The certification route parameter is neither an App\Models\Certification nor an id.');
        }

        return Certification::findOrFail($certification);
    }
}
