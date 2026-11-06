<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreIntakeRequest extends StoreApplicationRequest
{
    protected function prepareForValidation(): void
    {
        parent::prepareForValidation();
        if (is_array($this->input('sponsorEmails'))) {
            $this->merge(['sponsorEmails' => array_map(fn ($email) => is_string($email) ? mb_strtolower(trim($email)) : $email, $this->input('sponsorEmails'))]);
        }
    }

    public function rules(): array
    {
        $rules = parent::rules();
        foreach (['sponsors', 'sponsors.*', 'documents', 'documents.vigencia', 'documents.personeria', 'documents.estatutos', 'documents.antecedentes'] as $key) {
            unset($rules[$key]);
        }
        return [
            ...$rules,
            'membership' => ['required', Rule::in(['member'])],
            'region' => ['required', 'string', 'max:120'],
            'commune' => ['required', 'string', 'max:120'],
            'sponsors' => ['prohibited'],
            'documents' => ['prohibited'],
            'sponsorEmails' => ['required', 'array', 'list', 'size:2'],
            'sponsorEmails.*' => ['bail', 'required', 'string', 'email:rfc', 'max:255'],
            'sharing_accepted' => ['required', 'accepted'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            foreach ($this->all() as $field => $value) {
                if (is_string($value) && str_contains($value, "\0")) {
                    $validator->errors()->add($field, 'Este dato contiene un carácter no admitido.');
                }
            }
            $key = $this->header('Idempotency-Key');
            if (!is_string($key) || !preg_match('/^[A-Za-z0-9_-]{32,128}$/D', $key)) {
                $validator->errors()->add('submission', 'Se requiere un identificador de envío válido.');
            }
            foreach ((array) $this->input('sponsorEmails', []) as $email) {
                if (is_string($email) && $email === $this->input('email')) {
                    $validator->errors()->add('sponsorEmails', 'Indica los correos de dos socios distintos de quien presenta la solicitud.');
                }
            }
            $emails = $this->input('sponsorEmails');
            if (is_array($emails) && count($emails) === 2 && is_string($emails[0] ?? null)
                && is_string($emails[1] ?? null) && $emails[0] === $emails[1]) {
                $validator->errors()->add('sponsorEmails', 'Los dos socios deben ser distintos.');
            }
        }];
    }
}
