<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreApplicationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('email'))) {
            $this->merge(['email' => mb_strtolower(trim($this->input('email')))]);
        }
        foreach (['givenNames', 'familyNames', 'region', 'commune'] as $field) {
            if (is_string($this->input($field))) {
                $this->merge([$field => trim($this->input($field))]);
            }
        }
        if ($this->input('legal') === 'natural' && is_string($this->input('givenNames')) && is_string($this->input('familyNames')) && $this->filled(['givenNames', 'familyNames'])) {
            $this->merge(['name' => $this->input('givenNames').' '.$this->input('familyNames')]);
        }
        if (is_string($this->input('region')) && is_string($this->input('commune')) && $this->filled(['region', 'commune'])) {
            $this->merge(['address' => $this->input('region').', '.$this->input('commune')]);
        }
    }

    public function rules(): array
    {
        $natural = $this->input('legal') === 'natural';
        $juridica = $this->input('legal') === 'juridica';
        $memberNatural = $natural && $this->input('membership') === 'member';
        $sponsorsAllowed = $juridica || $memberNatural;
        $sponsorsRequired = $juridica || ($memberNatural && config('admissions.require_member_sponsors'));
        $file = ['file', 'mimetypes:application/pdf,image/jpeg,image/png', 'extensions:pdf,jpg,jpeg,png', 'max:'.config('admissions.max_file_kb')];

        return [
            'membership' => ['required', Rule::in(['member', 'collaborator'])],
            'legal' => ['required', Rule::in(['natural', 'juridica'])],
            'name' => [Rule::requiredIf($natural), Rule::prohibitedIf(!$natural), 'nullable', 'string', 'max:255'],
            'givenNames' => [Rule::requiredIf($memberNatural), Rule::prohibitedIf(!$natural), 'nullable', 'string', 'max:120'],
            'familyNames' => [Rule::requiredIf($memberNatural), Rule::prohibitedIf(!$natural), 'nullable', 'string', 'max:120'],
            'age' => [Rule::requiredIf($memberNatural), Rule::prohibitedIf(!$natural), 'nullable', 'integer', 'min:0', 'max:32767'],
            'region' => [Rule::requiredIf($memberNatural), 'nullable', 'string', 'max:120'],
            'commune' => [Rule::requiredIf($memberNatural), 'nullable', 'string', 'max:120'],
            'identifier' => [Rule::requiredIf($natural), Rule::prohibitedIf(!$natural), 'nullable', 'string', 'max:80'],
            'organizationName' => [Rule::requiredIf($juridica), Rule::prohibitedIf(!$juridica), 'nullable', 'string', 'max:255'],
            'organizationId' => [Rule::requiredIf($juridica), Rule::prohibitedIf(!$juridica), 'nullable', 'string', 'max:80'],
            'representativeName' => [Rule::requiredIf($juridica), Rule::prohibitedIf(!$juridica), 'nullable', 'string', 'max:255'],
            'representativeId' => [Rule::requiredIf($juridica), Rule::prohibitedIf(!$juridica), 'nullable', 'string', 'max:80'],
            'email' => ['required', 'string', 'email:rfc', 'max:255'],
            'address' => ['required', 'string', 'max:500'],
            'aiActivity' => ['required', 'string', 'max:10000'],
            'declaration_accepted' => ['required', 'accepted'],
            'sponsors' => [Rule::requiredIf($sponsorsRequired), Rule::prohibitedIf(!$sponsorsAllowed), 'array', 'list', 'size:2'],
            'sponsors.*' => ['required', 'uuid', 'distinct:ignore_case'],
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
            foreach (['name', 'givenNames', 'familyNames', 'identifier', 'organizationName', 'organizationId',
                'representativeName', 'representativeId', 'email', 'address', 'region', 'commune', 'aiActivity'] as $field) {
                if (is_string($this->input($field)) && str_contains($this->input($field), "\0")) {
                    $validator->errors()->add($field, 'Este dato contiene un carácter no admitido.');
                }
            }
            $key = $this->header('Idempotency-Key');
            if ($key !== null && !preg_match('/^[A-Za-z0-9_-]{32,128}$/D', $key)) {
                $validator->errors()->add('submission', 'El identificador de envío no es válido.');
            }
            if (!config('admissions.require_member_sponsors') && !app()->environment(['local', 'testing'])) {
                $validator->errors()->add('sponsors', 'El envío sin patrocinios solo está disponible en pruebas locales.');
            }
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
