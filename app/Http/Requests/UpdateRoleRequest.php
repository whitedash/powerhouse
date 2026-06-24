<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Rename a custom role. Authorised via the existing enum (isSuperAdmin).
 */
class UpdateRoleRequest extends FormRequest
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
        $id = (int) $this->route('id');

        return [
            'name' => [
                'required', 'string', 'max:50', 'regex:/^[A-Za-z][A-Za-z0-9 _-]*$/',
                Rule::unique('roles', 'name')->where('guard_name', 'web')->ignore($id),
                Rule::notIn(['super_admin', 'staff']),
            ],
        ];
    }
}
