<?php

declare(strict_types=1);

namespace App\Http\Requests\Receipt;

use App\Rules\ValidReceiptFile;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * docs/02 FR-004: upload JPG/JPEG/PNG/WEBP/PDF, validate MIME and size.
 */
class StoreReceiptRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'file' => [
                'required',
                'file',
                'max:'.(int) config('receipts.max_size_kb'),
                // Extension and declared MIME are attacker-controlled, so the
                // real check is on the file's magic bytes.
                new ValidReceiptFile,
            ],
            // Optional: a receipt may be attached to an existing session, or
            // stand alone until review creates one.
            'charging_session_id' => [
                'nullable',
                'integer',
                Rule::exists('charging_sessions', 'id')->whereNull('deleted_at'),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'file.max' => 'The receipt may not be larger than '
                .round(((int) config('receipts.max_size_kb')) / 1024, 1).' MB.',
        ];
    }
}
