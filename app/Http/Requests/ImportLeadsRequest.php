<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Leads CSV import. Deliberately validates only file presence here —
 * mime/size enforcement lives in FileUploadService's 'import' context
 * (double MIME check + size cap), and duplicating those rules in a
 * second place would let the two drift. FileUploadService throws
 * ValidationException on a bad file, which surfaces exactly like a
 * FormRequest failure.
 */
class ImportLeadsRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Route middleware already enforces permission:leads.manage; this
        // is the belt to that brace (and covers any future route rewiring).
        return $this->user()?->can('leads.manage') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'file' => ['required', 'file'],
        ];
    }
}
