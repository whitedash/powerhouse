<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Create a role (optionally copy-from). Authorised via the EXISTING enum
 * (isSuperAdmin) — the same mechanism the route group uses — not the new
 * permission system; phase 2 introduces no new enforcement.
 */
class StoreRoleRequest extends FormRequest
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
            'name' => [
                'required', 'string', 'max:50', 'regex:/^[A-Za-z][A-Za-z0-9 _-]*$/',
                // Unique among web-guard roles; reserved system names blocked.
                Rule::unique('roles', 'name')->where('guard_name', 'web'),
                Rule::notIn(['super_admin', 'staff']),
            ],
            'mode' => ['required', Rule::in(['blank', 'copy'])],
            'copy_from_id' => [
                'nullable',
                'required_if:mode,copy',
                'integer',
                Rule::exists('roles', 'id')->where('guard_name', 'web')->whereNot('name', 'super_admin'),
            ],
        ];
    }
}
