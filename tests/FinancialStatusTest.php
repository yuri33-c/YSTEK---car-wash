<?php

namespace Adianti\Control {
    if (!class_exists(TPage::class)) {
        class TPage {}
    }
}

namespace {
    require_once __DIR__ . '/../app/control/FinancialController.php';

    $reflection = new ReflectionClass(\App\Control\FinancialController::class);
    $controller = $reflection->newInstanceWithoutConstructor();
    $normalize = $reflection->getMethod('normalizeStatus');
    $normalize->setAccessible(true);

    $cases = [
        ['Receita', 'A receber', 'A receber'],
        ['Receita', 'Recebido', 'Recebido'],
        ['Receita', 'Pendente', 'A receber'],
        ['Receita', 'Pago', 'Recebido'],
        ['Despesa', 'A pagar', 'A pagar'],
        ['Despesa', 'Pago', 'Pago'],
        ['Despesa', 'Pendente', 'A pagar'],
        ['Despesa', 'Recebido', 'Pago'],
        ['Despesa', 'A receber', null],
        ['Receita', 'A pagar', null],
    ];

    foreach ($cases as [$type, $status, $expected]) {
        $actual = $normalize->invoke($controller, $type, $status);
        if ($actual !== $expected) {
            fwrite(STDERR, "FALHOU: {$type}/{$status}\n");
            exit(1);
        }
    }

    echo "OK: 10 cenários de status financeiro passaram." . PHP_EOL;
}
