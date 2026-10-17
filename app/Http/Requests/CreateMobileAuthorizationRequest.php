<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Dto\MobileAuthorization;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The user approves the devnull app from the page the app opened, which sends back what the app asked for.
 */
final class CreateMobileAuthorizationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return ShowMobileAuthorizationRequest::parameterRules();
    }

    public function authorization(): MobileAuthorization
    {
        return new MobileAuthorization(
            $this->string('fingerprint')->value(),
            $this->string('device_name')->value(),
            $this->string('code_challenge')->value(),
            $this->string('state')->value(),
            $this->string('redirect_uri')->value(),
        );
    }

    public function failedValidation(Validator $validator): never
    {
        throw ShowMobileAuthorizationRequest::invalid($this, $validator);
    }
}
