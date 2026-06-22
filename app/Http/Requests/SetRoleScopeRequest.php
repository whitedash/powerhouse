<?php

namespace App\Http\Requests;

use App\Enums\AccessScope;
use App\Enums\ScopeArea;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Set a role's tri-state scope for one scoped area. Area is constrained to
 * App\Enums\ScopeArea and scope to App\Enums\AccessScope — invalid values
 * are rejected. Authorised via the existing enum (isSuperAdmin).
 */
class SetRoleScopeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isSuperAdmin() ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'area' => ['required', Rule::in(ScopeArea::values())],
            'scope' => ['required', Rule::in(AccessScope::values())],
        ];
    }
}
