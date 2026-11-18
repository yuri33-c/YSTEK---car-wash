<?php
namespace App\Service;

/**
 * Integração de WhatsApp baseada em links oficiais wa.me.
 *
 * O sistema não dispara mensagens silenciosamente: prepara o texto com os
 * dados reais da ordem e abre o WhatsApp para o usuário revisar e enviar.
 */
final class WhatsAppService
{
    /** Normaliza telefone brasileiro para o formato internacional do wa.me. */
    public static function normalizePhone(?string $phone): string
    {
        $digits = preg_replace('/\D+/', '', (string)$phone);
        if ($digits === '') {
            return '';
        }

        // Alguns cadastros antigos armazenaram o zero de discagem antes do DDD.
        if (strlen($digits) >= 11 && strlen($digits) <= 13 && str_starts_with($digits, '0')) {
            $digits = substr($digits, 1);
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

    /**
     * Renderiza a mensagem configurada pela empresa.
     *
     * Placeholders aceitos: [NOME], [MARCA], [MODELO], [VEICULO], [PLACA],
     * [SERVICO], [VALOR], [DATA], [HORA], [EMPRESA], [OS],
     * [WHATSAPP_EMPRESA] e [ENDERECO].
     * Também aceita {{NOME}} como alternativa a [NOME].
     */
    public static function completionMessage(?string $template, array $values): string
    {
        $template = trim((string)$template);
        if ($template === '') {
            $template = 'Olá, [NOME]! O serviço [SERVICO] do seu veículo [VEICULO] (placa [PLACA]) foi concluído. '
                . 'Valor: [VALOR]. Seu veículo está pronto para retirada em [EMPRESA]. '
                . 'O.S. [OS]. Obrigado pela preferência!';
        }

        $keys = [
            'NOME', 'MARCA', 'MODELO', 'VEICULO', 'PLACA', 'SERVICO', 'VALOR',
            'DATA', 'HORA', 'EMPRESA', 'OS', 'WHATSAPP_EMPRESA', 'ENDERECO',
        ];
        $normalized = [];
        foreach ($keys as $key) {
            $normalized[$key] = trim((string)($values[$key] ?? ''));
        }

        $message = preg_replace_callback(
            '/\[(' . implode('|', $keys) . ')\]|\{\{(' . implode('|', $keys) . ')\}\}/iu',
            static function (array $match) use ($normalized): string {
                $key = strtoupper((string)($match[1] !== '' ? $match[1] : ($match[2] ?? '')));
                return $normalized[$key] ?? '';
            },
            $template
        );

        return trim((string)$message);
    }

    /** Compatibilidade com chamadas antigas: mantém a mensagem padrão legada. */
    public static function readyMessage(string $clientName, string $vehicle, string $tenantName, string $orderId): string
    {
        return self::completionMessage(
            'Olá, [NOME]! Seu carro ([VEICULO]) está pronto para retirada no [EMPRESA]. O.S. [OS]. Obrigado pela preferência!',
            [
                'NOME' => $clientName,
                'VEICULO' => $vehicle,
                'EMPRESA' => $tenantName,
                'OS' => $orderId,
            ]
        );
    }
}
