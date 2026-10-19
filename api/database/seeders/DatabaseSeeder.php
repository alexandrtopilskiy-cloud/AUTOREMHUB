<?php
namespace Database\Seeders;
use Illuminate\Database\Seeder;
final class DatabaseSeeder extends Seeder { public function run():void{$this->command?->info('Заявки и демонстрационные данные не создаются. Используйте sto:tenant-create и sto:organization-create.');} }
