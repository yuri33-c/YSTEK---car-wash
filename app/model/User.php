<?php
namespace App\Model;

use Adianti\Database\TRecord;

/**
 * Modelo MVC responsável pela persistência de User.
 *
 * As regras de negócio ficam nos controllers/services; este modelo mapeia
 * apenas a entidade para a tabela PostgreSQL correspondente.
 */
class User extends TRecord
{
    protected static $tablename = 'users';
    protected static $primarykey = 'id';
}
