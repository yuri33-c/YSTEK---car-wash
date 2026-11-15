<?php
namespace App\Service;

/**
 * Integração de WhatsApp.
 *
 * A função principal gera um link wa.me com a mensagem pronta, permitindo
 * operar imediatamente sem expor token de API no navegador. Quando uma API
 * oficial/provedor for configurada, este serviço pode ser estendido para
 * envio server-side sem alterar o fluxo da O.S.
 */
final class WhatsAppService
{
    /** Normaliza o telefone para o formato internacional, preservando o DDI 55. */
    public static function normalizePhone(?string $phone): string
    {
        $digits = preg_replace('/\D+/', '', (string)$phone);
        if ($digits === '') {
            return '';
        }
        return str_starts_with($digits, '55') ? $digits : '55' . $digits;
    }

    /** Monta o link do WhatsApp Web/App com mensagem URL-encoded. */
    public static function buildReadyUrl(?string $phone, string $message): string
    {
        $number = self::normalizePhone($phone);
        if ($number === '') {
            return '';
        }
        return 'https://wa.me/' . $number . '?text=' . rawurlencode($message);
    }

    /** Mensagem padrão de veículo liberado após conclusão da O.S. */
    public static function readyMessage(string $clientName, string $vehicle, string $tenantName, string $orderId): string
    {
        return "Olá, {$clientName}! Seu carro ({$vehicle}) está pronto para retirada no {$tenantName}. O.S. #{$orderId}. Obrigado pela preferência!";
    }
}
