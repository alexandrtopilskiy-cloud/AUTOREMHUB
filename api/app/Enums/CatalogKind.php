<?php

declare(strict_types=1);

namespace App\Enums;

enum CatalogKind: string
{
    case Vehicles = 'vehicles';
    case VehicleModels = 'vehicleModels';
    case Drivers = 'drivers';
    case ContactPersons = 'contactPersons';
    case WorkTypes = 'workTypes';
    case TimeNorms = 'timeNorms';
    case NormApplicabilities = 'normApplicabilities';
    case Parts = 'parts';
    case PartManufacturers = 'partManufacturers';
    case Reasons = 'reasons';
    case Regulations = 'regulations';
    case Suppliers = 'suppliers';
    case Stations = 'stations';
    case Customers = 'customers';
    case Managers = 'managers';
    case Users = 'users';

    public function isParticipant(): bool
    {
        return in_array($this, [self::Customers, self::Stations, self::Managers], true);
    }

    public function isManagerOnly(): bool
    {
        return !in_array($this, [
            self::Vehicles,
            self::VehicleModels,
            self::Drivers,
            self::ContactPersons,
        ], true);
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
