<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Contracts\Validation\Validator;

/**
 * PBI-2: Validasi input pertanyaan pengguna.
 * Memastikan setiap pertanyaan yang dikirim ke backend sudah valid,
 * bersih, dan sesuai format sebelum diproses lebih lanjut.
 */
class SendMessageRequest extends FormRequest
{
    /**
     * Semua user (guest) diizinkan mengirim pesan.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Aturan validasi input pertanyaan pengguna (PBI-2).
     */
    public function rules(): array
    {
        return [
            'message'    => [
                'required',
                'string',
                'min:2',
                'max:2000',
            ],
            'session_id' => [
                'required',
                'string',
                'uuid',
            ],
        ];
    }

    /**
     * Pesan validasi dalam Bahasa Indonesia.
     */
    public function messages(): array
    {
        return [
            'message.required' => 'Pertanyaan tidak boleh kosong.',
            'message.string'   => 'Pertanyaan harus berupa teks.',
            'message.min'      => 'Pertanyaan minimal 2 karakter.',
            'message.max'      => 'Pertanyaan maksimal 2000 karakter.',
            'session_id.required' => 'Session ID diperlukan.',
            'session_id.uuid'     => 'Format Session ID tidak valid.',
        ];
    }

    /**
     * Preprocessing: bersihkan dan normalisasi input sebelum validasi.
     * PBI-3: sanitasi dan normalisasi teks pertanyaan pengguna melalui InputPreprocessor.
     */
    protected function prepareForValidation(): void
    {
        if ($this->has('message')) {
            $preprocessor = app(\App\Services\InputPreprocessor::class);
            $cleaned = $preprocessor->clean($this->input('message'));

            $this->merge(['message' => $cleaned]);
        }
    }

    /**
     * Kembalikan JSON error jika request dari AJAX / Fetch API.
     */
    protected function failedValidation(Validator $validator): never
    {
        throw new HttpResponseException(
            response()->json([
                'success' => false,
                'errors'  => $validator->errors(),
                'message' => $validator->errors()->first(),
            ], 422)
        );
    }
}
