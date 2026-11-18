<?php
require_once __DIR__ . '/../app/service/WhatsAppService.php';

use App\Service\WhatsAppService;

function expectSame($expected, $actual, string $label): void
{
    if ($expected !== $actual) {
        fwrite(STDERR, "FALHOU: {$label}\nEsperado: " . var_export($expected, true) . "\nObtido: " . var_export($actual, true) . "\n");
        exit(1);
    }
    echo "OK: {$label}\n";
}

$template = "Olá [NOME]!\nO serviço [SERVICO] do seu [MODELO], placa [PLACA], foi concluído em [DATA] às [HORA].\nValor: [VALOR]. O.S. [OS] — [EMPRESA].";
$expected = "Olá Mariana Souza!\nO serviço Lavagem completa do seu Honda Civic, placa ABC1D23, foi concluído em 18/11/2026 às 17:42.\nValor: R$ 89,90. O.S. #0018 — YSTEK Lava Jato.";

$message = WhatsAppService::completionMessage($template, [
    'NOME' => 'Mariana Souza',
    'SERVICO' => 'Lavagem completa',
    'MODELO' => 'Honda Civic',
    'PLACA' => 'ABC1D23',
    'DATA' => '18/11/2026',
    'HORA' => '17:42',
    'VALOR' => 'R$ 89,90',
    'OS' => '#0018',
    'EMPRESA' => 'YSTEK Lava Jato',
]);
expectSame($expected, $message, 'substituição de dados reais no modelo da mensagem');

expectSame('5594991234567', WhatsAppService::normalizePhone('(94) 99123-4567'), 'normalização de telefone brasileiro');
expectSame('5594991234567', WhatsAppService::normalizePhone('+55 94 99123-4567'), 'preservação do DDI 55 sem duplicação');
expectSame('', WhatsAppService::buildReadyUrl('', 'Mensagem'), 'não criar link sem telefone do cliente');

$url = WhatsAppService::buildReadyUrl('(94) 99123-4567', $message);
expectSame(true, str_starts_with($url, 'https://wa.me/5594991234567?text='), 'geração do link para o WhatsApp do cliente');
parse_str((string)parse_url($url, PHP_URL_QUERY), $query);
expectSame($expected, $query['text'] ?? null, 'preservação de acentos e quebras de linha no link');

$curly = WhatsAppService::completionMessage('Olá {{nome}} — [placa]', ['NOME' => 'Ana', 'PLACA' => 'ABC123']);
expectSame('Olá Ana — ABC123', $curly, 'placeholders minúsculos e formato {{CHAVE}}');

$default = WhatsAppService::completionMessage('', ['NOME' => 'Paulo', 'SERVICO' => 'Polimento', 'VEICULO' => 'Fiat Argo', 'PLACA' => 'QWE1A23', 'VALOR' => 'R$ 120,00', 'EMPRESA' => 'YSTEK', 'OS' => '#0021']);
expectSame(true, str_contains($default, 'Olá, Paulo!') && str_contains($default, 'R$ 120,00'), 'mensagem padrão quando não existe modelo configurado');

echo "\nTODOS OS TESTES DE WHATSAPP PASSARAM.\n";
