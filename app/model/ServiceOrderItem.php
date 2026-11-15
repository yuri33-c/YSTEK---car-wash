<?php
namespace App\Model;

use Adianti\Database\TRecord;

/** Modelo da relação entre O.S. e serviços executados. */
class ServiceOrderItem extends TRecord
{
    protected static $tablename = 'service_order_items';
    protected static $primarykey = 'id';
}
