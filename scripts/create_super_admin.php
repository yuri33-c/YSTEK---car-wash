<?php
/**
 * Cria o primeiro SUPER_ADMIN de forma interativa.
 *
 * Este script deve ser executado apenas via CLI e fora do fluxo HTTP.
 * Nenhum e-mail, nome de conta ou senha fica salvo no código-fonte.
 */
require_once __DIR__ . '/../lib/adianti/database/TTransaction.php';
use Adianti\Database\TTransaction;

function prompt(string $label): string
{
    fwrite(STDOUT, $label);
    $value = fgets(STDIN);
    return trim((string)$value);
}

function promptSecret(string $label): string
{
    fwrite(STDOUT, $label);
    if (stripos(PHP_OS_FAMILY, 'Windows') === false) {
        $value = trim((string)shell_exec('stty -echo; read -r value; stty echo; printf "%s" "$value"'));
        fwrite(STDOUT, PHP_EOL);
        return $value;
    }
    $value = fgets(STDIN);
    fwrite(STDOUT, PHP_EOL);
    return trim((string)$value);
}

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Este arquivo só pode ser executado pela CLI.\n");
    exit(1);
}

$email = strtolower(prompt('E-mail do SUPER_ADMIN: '));
if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "E-mail inválido.\n");
    exit(1);
}

$name = prompt('Nome do SUPER_ADMIN: ');
if ($name === '') {
    fwrite(STDERR, "Nome obrigatório.\n");
    exit(1);
}

$password = promptSecret('Senha do SUPER_ADMIN (mínimo 12 caracteres): ');
$confirmation = promptSecret('Confirme a senha: ');

if (strlen($password) < 12) {
    fwrite(STDERR, "A senha deve ter pelo menos 12 caracteres.\n");
    exit(1);
}
if (!hash_equals($password, $confirmation)) {
    fwrite(STDERR, "As senhas não conferem.\n");
    exit(1);
}

try {
    TTransaction::open();
    $pdo = TTransaction::get();

    $stmt = $pdo->prepare('SELECT id FROM users WHERE LOWER(email) = LOWER(:email) LIMIT 1');
    $stmt->execute([':email' => $email]);
    if ($stmt->fetchColumn()) {
        throw new RuntimeException('Já existe um usuário com esse e-mail.');
    }

    $hash = password_hash($password, PASSWORD_DEFAULT);
    $insert = $pdo->prepare("INSERT INTO users (tenant_id, name, email, password, role, active) VALUES (NULL, :name, :email, :password, 'SUPER_ADMIN', TRUE)");
    $insert->execute([
        ':name' => $name,
        ':email' => $email,
        ':password' => $hash,
    ]);

    TTransaction::close();
    fwrite(STDOUT, "SUPER_ADMIN criado com sucesso.\n");
    exit(0);
} catch (Throwable $e) {
    try { TTransaction::close(); } catch (Throwable $ignore) {}
    fwrite(STDERR, "Falha ao criar SUPER_ADMIN: {$e->getMessage()}\n");
    exit(1);
}
