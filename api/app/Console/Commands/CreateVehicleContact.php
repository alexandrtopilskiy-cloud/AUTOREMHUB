<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Vehicle;
use App\Models\VehicleContact;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

final class CreateVehicleContact extends Command
{
    protected $signature = 'sto:vehicle-contact-create
        {vehicle : VIN или UUID автомобиля}
        {lastName : Фамилия}
        {firstName : Имя}
        {phone : Телефон РФ}
        {--middle-name= : Отчество}';

    protected $description = 'Добавить контактное лицо в справочник выбранного автомобиля';

    public function handle(): int
    {
        $vehicle = $this->findVehicle((string) $this->argument('vehicle'));

        if ($vehicle === null) {
            $this->error('Автомобиль не найден по VIN или UUID');

            return self::FAILURE;
        }

        $lastName = trim((string) $this->argument('lastName'));
        $firstName = trim((string) $this->argument('firstName'));
        $middleName = trim((string) $this->option('middle-name'));
        $phone = $this->normalizePhone((string) $this->argument('phone'));

        if ($lastName === '' || $firstName === '') {
            $this->error('Фамилия и имя обязательны');

            return self::FAILURE;
        }

        if ($phone === null) {
            $this->error('Телефон должен содержать 10 цифр либо начинаться с 7 или 8 и содержать 11 цифр');

            return self::FAILURE;
        }

        $contact = VehicleContact::query()->updateOrCreate(
            [
                'vehicle_id' => $vehicle->id,
                'phone'      => $phone,
            ],
            [
                'last_name'   => $lastName,
                'first_name'  => $firstName,
                'middle_name' => $middleName !== '' ? $middleName : null,
                'active'      => true,
            ],
        );

        $this->info(
            'Контакт сохранён. id=' . $contact->id
            . ' UUID=' . $contact->uuid
            . ' VIN=' . $vehicle->vin
        );

        return self::SUCCESS;
    }

    private function findVehicle(string $key): ?Vehicle
    {
        $normalized = strtoupper(trim($key));

        if (Str::isUuid($normalized)) {
            return Vehicle::query()->where('uuid', $normalized)->first();
        }

        return Vehicle::query()->where('vin', $normalized)->first();
    }

    private function normalizePhone(string $phone): ?string
    {
        $digits = preg_replace('/\D+/', '', $phone);

        if (strlen($digits) === 10) {
            return '+7' . $digits;
        }

        if (strlen($digits) === 11 && in_array($digits[0], ['7', '8'], true)) {
            return '+7' . substr($digits, 1);
        }

        return null;
    }
}
