<?php
namespace App\Model;

use Adianti\Database\TRecord;

/**
 * Modelo MVC responsável pela persistência de FinancialRecord.
 *
 * As regras de negócio ficam nos controllers/services; este modelo mapeia
 * apenas a entidade para a tabela PostgreSQL correspondente.
 */
class FinancialRecord extends TRecord
{
    protected static $tablename = 'financial_records';
    protected static $primarykey = 'id';
}
