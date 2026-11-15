<?php
namespace App\Model;

use Adianti\Database\TRecord;

/**
 * Modelo MVC responsável pela persistência de ServiceOrder.
 *
 * As regras de negócio ficam nos controllers/services; este modelo mapeia
 * apenas a entidade para a tabela PostgreSQL correspondente.
 */
class ServiceOrder extends TRecord
{
    protected static $tablename = 'service_orders';
    protected static $primarykey = 'id';
}
