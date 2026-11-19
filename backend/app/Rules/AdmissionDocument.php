<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Symfony\Component\HttpFoundation\File\UploadedFile;

class AdmissionDocument implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (!$value instanceof UploadedFile || !$value->isValid()) return;
        $expected = ['pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png'];
        $extension = strtolower($value->getClientOriginalExtension());
        if ($value->getSize() <= 0 || !isset($expected[$extension]) || $value->getMimeType() !== $expected[$extension]) {
            $fail('El contenido debe corresponder a la extensión PDF, JPG o PNG del archivo.');
        }
    }
}
