<?php
namespace App\Model;

use Adianti\Database\TRecord;

/**
 * Modelo MVC responsável pela persistência de Vehicle.
 *
 * As regras de negócio ficam nos controllers/services; este modelo mapeia
 * apenas a entidade para a tabela PostgreSQL correspondente.
 */
class Vehicle extends TRecord
{
    protected static $tablename = 'vehicles';
    protected static $primarykey = 'id';
}
