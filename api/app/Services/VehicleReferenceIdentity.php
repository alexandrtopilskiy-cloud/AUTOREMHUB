<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\VehicleModel;
use App\Models\VehicleGeneration;
use App\Models\VehicleEngineVariant;
use Illuminate\Database\Eloquent\Builder;

/** Logical reference identities across technical variants of one model. */
final class VehicleReferenceIdentity
{
    public static function modelIds(VehicleModel $model): Builder
    {
        return VehicleModel::query()->where('vehicle_make_id', $model->vehicle_make_id)
            ->where('normalized_name', $model->normalized_name)->select('id');
    }

    public static function sameModel(?VehicleModel $left, ?VehicleModel $right): bool
    {
        return $left !== null && $right !== null
            && $left->vehicle_make_id === $right->vehicle_make_id
            && $left->normalized_name === $right->normalized_name;
    }

    public static function sameGeneration(?VehicleGeneration $left, ?VehicleGeneration $right): bool
    {
        return $left !== null && $right !== null
            && self::sameModel($left->model, $right->model)
            && $left->normalized_name === $right->normalized_name
            && $left->year_from === $right->year_from && $left->year_to === $right->year_to;
    }

    public static function sameEngine(?VehicleEngineVariant $left, ?VehicleEngineVariant $right): bool
    {
        return $left !== null && $right !== null
            && self::sameModel($left->model, $right->model)
            && mb_strtolower(trim($left->code)) === mb_strtolower(trim($right->code))
            && $left->engine_type === $right->engine_type
            && $left->power_hp === $right->power_hp;
    }

    public static function generationIds(VehicleGeneration $generation): Builder
    {
        return VehicleGeneration::query()
            ->whereIn('vehicle_model_id', self::modelIds($generation->model))
            ->where('normalized_name', $generation->normalized_name)
            ->where('year_from', $generation->year_from)->where('year_to', $generation->year_to)->select('id');
    }
}
