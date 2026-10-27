    <div class="box">
        <strong>Автомобиль:</strong> {{ $vehicle->make ?? '' }} {{ $vehicle->model ?? '' }}<br>
        <strong>VIN:</strong> {{ $vehicle->vin ?? '' }}<br>
        <strong>Гос. номер:</strong> {{ $vehicle->plate ?? '' }}<br>
        <strong>Пробег:</strong> {{ $act->mileage ?? '0' }} км<br>
        <strong>Уровень топлива:</strong> {{ $act->fuel_level ?? '' }}
    </div>
