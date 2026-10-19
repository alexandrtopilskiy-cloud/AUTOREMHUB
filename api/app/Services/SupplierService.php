<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\CatalogKind;
use App\Enums\UserRole;
use App\Exceptions\ApiException;
use App\Models\Supplier;
use App\Models\User;
use App\Support\DomainInput as Input;
use App\Support\RussianPhone;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

final class SupplierService
{
    public const FIELDS = [
        'legalFormName', 'shortName', 'kpp', 'ogrn', 'registrationDate',
        'legalAddress', 'actualAddress', 'postalAddress', 'directorName',
        'lastName', 'firstName', 'middleName', 'okpo', 'oktmo', 'okved',
        'bankName', 'bik', 'settlementAccount', 'correspondentAccount',
        'treasuryAccount', 'taxRegime', 'vatRate',
    ];

    public function write(User $user, ?Supplier $supplier, array $values): Supplier
    {
        if (!in_array($user->role, [UserRole::Manager, UserRole::Station], true)) {
            throw new ApiException(403, 'FORBIDDEN', 'Поставщиков создают УК и СТО');
        }
        return DB::transaction(function () use ($user, $supplier, $values): Supplier {
            if ($supplier !== null) {
                $supplier = Supplier::query()->where('tenant_id', $user->tenantId())->whereKey($supplier->id)->lockForUpdate()->firstOrFail();
                if ($user->role === UserRole::Station && $supplier->created_by_organization_id !== $user->organization_id) {
                    throw new ApiException(403, 'FORBIDDEN', 'СТО может изменять только созданных своей организацией поставщиков');
                }
            }
            $data = array_replace($supplier?->requisites ?? [], $supplier === null ? [] : [
                'name' => $supplier->name, 'taxId' => $supplier->tax_id,
                'legalForm' => $supplier->legal_form, 'contacts' => $supplier->contacts ?? [], 'active' => $supplier->active,
            ], $values);
            $individual = (bool) config('supplier_legal_forms.' . ($data['legalForm'] ?? '') . '.individual', false);
            $rules = [
                'legalForm' => ['required', Rule::in(array_keys(config('supplier_legal_forms')))],
                'name' => ['required', 'string', 'max:255'],
                'taxId' => ['required', 'string', 'regex:/^\d{' . ($individual ? '12' : '10') . '}$/'],
                'active' => ['sometimes', 'boolean'], 'contact' => ['sometimes', 'string', 'max:255'],
                'contacts' => ['required', 'array', 'list', 'min:1', 'max:30'],
                'contacts.*' => ['array:lastName,firstName,middleName,position,phone,email'],
                'contacts.*.lastName' => ['required', 'string', 'max:100'],
                'contacts.*.firstName' => ['required', 'string', 'max:100'],
                'contacts.*.middleName' => ['nullable', 'string', 'max:100'],
                'contacts.*.position' => ['nullable', 'string', 'max:150'],
                'contacts.*.phone' => ['required', 'string', 'max:50'],
                'contacts.*.email' => ['nullable', 'email', 'max:255'],
            ];
            foreach (self::FIELDS as $field) {
                $rules[$field] = ['nullable', 'string', 'max:1000'];
            }
            foreach (['legalAddress', 'actualAddress', 'postalAddress', 'bankName', 'taxRegime'] as $field) {
                $rules[$field] = ['required', 'string', 'max:1000'];
            }
            $rules['ogrn'] = ['required', 'string', 'regex:/^\d{' . ($individual ? '15' : '13') . '}$/'];
            $rules['kpp'] = $individual ? ['nullable', 'in:'] : ['required', 'string', 'regex:/^\d{9}$/'];
            foreach (['lastName', 'firstName'] as $field) {
                $rules[$field] = [$individual ? 'required' : 'nullable', 'string', 'max:100'];
            }
            $rules['directorName'] = [$individual ? 'nullable' : 'required', 'string', 'max:255'];
            $rules['legalFormName'] = [($data['legalForm'] ?? '') === 'other' ? 'required' : 'nullable', 'string', 'max:255'];
            $rules['registrationDate'] = ['required', 'date_format:Y-m-d'];
            $rules['bik'] = ['required', 'string', 'regex:/^\d{9}$/'];
            foreach (['settlementAccount', 'correspondentAccount'] as $field) {
                $rules[$field] = ['required', 'string', 'regex:/^\d{20}$/'];
            }
            $rules['treasuryAccount'] = ['nullable', 'regex:/^\d{20}$/'];
            $rules['okpo'] = ['nullable', 'regex:/^(\d{8}|\d{10})$/'];
            $rules['oktmo'] = ['nullable', 'regex:/^(\d{8}|\d{11})$/'];
            $rules['vatRate'] = ['required', 'string', 'max:50'];
            Input::validate($data, $rules, [
                'regex' => 'Некорректный формат :attribute',
            ], [
                'taxId' => $individual ? 'ИНН ИП (12 цифр)' : 'ИНН организации (10 цифр)',
                'ogrn' => $individual ? 'ОГРНИП (15 цифр)' : 'ОГРН (13 цифр)',
                'kpp' => 'КПП (9 цифр)', 'bik' => 'БИК (9 цифр)',
                'settlementAccount' => 'Расчётный счёт (20 цифр)',
                'correspondentAccount' => 'Корреспондентский счёт (20 цифр)',
            ]);
            foreach ($data['contacts'] as $index => &$contact) {
                $contact['phone'] = RussianPhone::normalize($contact['phone']);
                if ($contact['phone'] === null) {
                    Input::fail('Введите телефон в формате +7 (999) 123 45 67', 'contacts.' . $index . '.phone');
                }
            }
            unset($contact);
            $model = $supplier ?? new Supplier(['tenant_id' => $user->tenantId(), 'created_by_organization_id' => $user->organization_id]);
            $before = $model->exists ? $model->attributesToArray() : null;
            $model->fill([
                'name' => trim($data['name']), 'tax_id' => $data['taxId'],
                'legal_form' => $data['legalForm'], 'contacts' => $data['contacts'],
                'requisites' => array_intersect_key($data, array_flip(self::FIELDS)),
                'contact' => trim($data['contacts'][0]['lastName'] . ' ' . $data['contacts'][0]['firstName']),
                'active' => $data['active'] ?? true,
            ])->save();
            app(CatalogAuditService::class)->record($user->tenantId(), $user, CatalogKind::Suppliers, $model, $before === null ? 'create' : 'update', $before, $model->attributesToArray());
            return $model;
        });
    }
}
