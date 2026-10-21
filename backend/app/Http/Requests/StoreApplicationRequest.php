<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreApplicationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Postulación pública; nunca asigna roles ni membresía.
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('email'))) {
            $this->merge(['email' => mb_strtolower(trim($this->input('email')))]);
        }
    }

    public function rules(): array
    {
        $natural = $this->input('legal') === 'natural';
        $juridica = $this->input('legal') === 'juridica';
        $file = ['file', 'mimetypes:application/pdf,image/jpeg,image/png', 'extensions:pdf,jpg,jpeg,png', 'max:'.config('admissions.max_file_kb')];

        return [
            'membership' => ['required', Rule::in(['member', 'collaborator'])],
            'legal' => ['required', Rule::in(['natural', 'juridica'])],
            'name' => [Rule::requiredIf($natural), Rule::prohibitedIf(!$natural), 'nullable', 'string', 'max:255'],
            'identifier' => [Rule::requiredIf($natural), Rule::prohibitedIf(!$natural), 'nullable', 'string', 'max:80'],
            'organizationName' => [Rule::requiredIf($juridica), Rule::prohibitedIf(!$juridica), 'nullable', 'string', 'max:255'],
            'organizationId' => [Rule::requiredIf($juridica), Rule::prohibitedIf(!$juridica), 'nullable', 'string', 'max:80'],
            'representativeName' => [Rule::requiredIf($juridica), Rule::prohibitedIf(!$juridica), 'nullable', 'string', 'max:255'],
            'representativeId' => [Rule::requiredIf($juridica), Rule::prohibitedIf(!$juridica), 'nullable', 'string', 'max:80'],
            'email' => ['required', 'string', 'email:rfc', 'max:255'],
            'address' => ['required', 'string', 'max:500'],
            'aiActivity' => ['required', 'string', 'max:10000'],
            'declaration_accepted' => ['required', 'accepted'],
            'sponsors' => [Rule::requiredIf($juridica), Rule::prohibitedIf(!$juridica), 'array', 'size:2'],
            'sponsors.*' => ['required', 'string', 'max:36', 'distinct:strict'],
            'documents' => ['sometimes', 'array:vigencia,personeria,estatutos,antecedentes', 'max:4'],
            'documents.vigencia' => [Rule::requiredIf($juridica), Rule::prohibitedIf(!$juridica), ...$file],
            'documents.personeria' => [Rule::requiredIf($juridica), Rule::prohibitedIf(!$juridica), ...$file],
            'documents.estatutos' => [Rule::requiredIf($juridica), Rule::prohibitedIf(!$juridica), ...$file],
            'documents.antecedentes' => ['sometimes', ...$file],
            'status' => ['prohibited'], 'roles' => ['prohibited'], 'role' => ['prohibited'],
            'decision_reason' => ['prohibited'], 'decision_by_user_id' => ['prohibited'],
            'id' => ['prohibited'], 'tracking_token_hash' => ['prohibited'], 'reference' => ['prohibited'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($this->input('membership') === 'collaborator' && $this->input('legal') === 'juridica'
                && !config('admissions.collaborator_legal_enabled')) {
                $validator->errors()->add('legal', 'Los requisitos del colaborador jurídico requieren definición institucional antes de habilitar esta ruta.');
            }
        }];
    }

    public function messages(): array
    {
        return [
            'required' => 'Este dato es obligatorio.', 'prohibited' => 'Este dato no corresponde a la modalidad o no puede enviarse desde el formulario.',
            'accepted' => 'Confirma que revisaste los datos de la postulación.',
            'sponsors.size' => 'Se requieren dos socios patrocinadores distintos.',
            'sponsors.*.distinct' => 'Los patrocinadores deben ser dos socios distintos.',
            'mimetypes' => 'El contenido debe ser PDF, JPG o PNG.',
            'extensions' => 'La extensión debe ser PDF, JPG, JPEG o PNG.',
            'max.file' => 'El archivo supera el tamaño permitido.',
        ];
    }
}
