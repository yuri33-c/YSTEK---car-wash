<?php
namespace App\Model;

use Adianti\Database\TRecord;

/**
 * Modelo MVC responsável pela persistência de Client.
 *
 * As regras de negócio ficam nos controllers/services; este modelo mapeia
 * apenas a entidade para a tabela PostgreSQL correspondente.
 */
class Client extends TRecord
{
    protected static $tablename = 'clients';
    protected static $primarykey = 'id';
}
