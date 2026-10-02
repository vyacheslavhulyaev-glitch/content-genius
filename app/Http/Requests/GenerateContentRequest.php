<?php

namespace App\Http\Requests;

use App\Services\GenerationInputPolicy;
use Illuminate\Foundation\Http\FormRequest;

class GenerateContentRequest extends FormRequest
{
    public function authorize(): bool
    {
        $this->user()->contents()->findOrFail($this->route('content'));

        return true;
    }

    public function rules(): array
    {
        return GenerationInputPolicy::unsupportedRules();
    }
}
