<?php
namespace App\Model;

use Adianti\Database\TRecord;

/** Modelo MVC das avarias registradas durante a inspeção do veículo. */
class VehicleDamage extends TRecord
{
    protected static $tablename = 'vehicle_damages';
    protected static $primarykey = 'id';
}
