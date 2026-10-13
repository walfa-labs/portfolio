<?php

namespace App\Http\Requests\Public;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ContactMessageRequest extends FormRequest
{
    /**
     * The request types the form offers, which are also the triage buckets §8.8
     * uses to answer a data-subject request without a follow-up email.
     *
     * @var list<string>
     */
    public const REQUEST_TYPES = ['access', 'rectification', 'erasure', 'objection', 'other'];

    /**
     * The shortest time a human plausibly spends on the form, in seconds (§3.11).
     */
    private const MINIMUM_FORM_AGE_SECONDS = 2;

    /**
     * Determine if the user is authorized to make this request.
     *
     * The contact form is the only public write on the site (§3.2), so it is
     * anonymous by design and the rate limiter carries the abuse cost.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'string', 'email', 'max:254'],
            'message' => ['required', 'string', 'min:10', 'max:5000'],
            'request_type' => ['required', 'string', Rule::in(self::REQUEST_TYPES)],
            'website' => ['prohibited'],
            '_ts' => ['required', 'integer', 'max:'.(time() - self::MINIMUM_FORM_AGE_SECONDS)],
        ];
    }

    /**
     * Get the custom validation messages.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'website.prohibited' => 'This field must be left empty.',
            '_ts.max' => 'This form was submitted too quickly. Please try again.',
        ];
    }

    /**
     * Get the human-readable attribute names.
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'request_type' => 'request type',
            '_ts' => 'form timestamp',
        ];
    }
}
