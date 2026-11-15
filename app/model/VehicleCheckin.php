<?php
namespace App\Model;

use Adianti\Database\TRecord;

/**
 * Modelo MVC do check-in inicial do veículo.
 * Uma O.S. possui no máximo um check-in ativo.
 */
class VehicleCheckin extends TRecord
{
    protected static $tablename = 'vehicle_checkins';
    protected static $primarykey = 'id';
}
