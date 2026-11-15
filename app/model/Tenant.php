<?php
namespace App\Model;

use Adianti\Database\TRecord;

/**
 * Modelo MVC responsável pela persistência de Tenant.
 *
 * As regras de negócio ficam nos controllers/services; este modelo mapeia
 * apenas a entidade para a tabela PostgreSQL correspondente.
 */
class Tenant extends TRecord
{
    protected static $tablename = 'tenants';
    protected static $primarykey = 'id';

    public static function getCustomerObjects(): array
    {
        // Identifica a conta interna pelo vínculo SUPER_ADMIN e pelo e-mail institucional,
        // inclusive quando esse e-mail está somente no usuário associado ao tenant.
        $criteria = "LOWER(COALESCE(tenants.email, '')) <> :tenant_email
            AND NOT EXISTS (
                SELECT 1 FROM users AS platform_user
                WHERE platform_user.tenant_id = tenants.id
                  AND (
                      platform_user.role = 'SUPER_ADMIN'
                      OR LOWER(platform_user.email) = :user_email
                  )
            )";

        return self::getObjects($criteria, [
            ':tenant_email' => 'plataforma@ystek.com.br',
            ':user_email' => 'plataforma@ystek.com.br',
        ]);
    }
}
