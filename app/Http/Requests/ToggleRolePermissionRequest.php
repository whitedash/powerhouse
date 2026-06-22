<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Grant or revoke a single boolean permission on a role.
 *
 * The permission name is validated against the ACTUAL registered permissions
 * (web guard) — an arbitrary string can never be granted even if the UI
 * catalogue drifted. Authorised via the existing enum (isSuperAdmin).
 */
class ToggleRolePermissionRequest extends FormRequest
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
            'permission' => [
                'required', 'string',
                Rule::exists('permissions', 'name')->where('guard_name', 'web'),
            ],
            'granted' => ['required', 'boolean'],
        ];
    }
}
