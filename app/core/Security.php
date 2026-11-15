<?php
namespace App\Core;

/**
 * Serviços de segurança transversais do aplicativo.
 *
 * Esta classe concentra configuração de sessão, cabeçalhos de segurança e
 * proteção CSRF. Ela não contém regras de negócio e pode ser reutilizada por
 * qualquer controller da aplicação.
 */
final class Security
{
    private function __construct() {}

    /** Inicia a sessão com parâmetros seguros e compatíveis com HTTP local. */
    public static function startSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['SERVER_PORT'] ?? null) == 443);

        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.use_trans_sid', '0');
        ini_set('session.cookie_httponly', '1');
        ini_set('session.sid_length', '48');

        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'secure' => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);

        session_start();
    }

    /** Impede que páginas autenticadas sejam reutilizadas pelo cache do browser. */
    public static function noCacheHeaders(): void
    {
        if (PHP_SAPI === 'cli') {
            return;
        }

        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Cache-Control: post-check=0, pre-check=0', false);
        header('Pragma: no-cache');
        header('Expires: 0');
        header('X-Frame-Options: SAMEORIGIN');
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
        if ($secure = ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['SERVER_PORT'] ?? null) == 443))) {
            header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
        }
    }

    /** Garante um token CSRF estável durante a sessão atual. */
    public static function csrfToken(): string
    {
        self::startSession();
        if (empty($_SESSION['_csrf'])) {
            $_SESSION['_csrf'] = bin2hex(random_bytes(32));
        }
        return (string)$_SESSION['_csrf'];
    }

    /** Retorna o campo hidden pronto para ser incluído em formulários POST autenticados. */
    public static function csrfInput(): string
    {
        $token = htmlspecialchars(self::csrfToken(), ENT_QUOTES, 'UTF-8');
        return '<input type="hidden" name="_csrf" value="' . $token . '">';
    }

    /** Valida o token CSRF da área autenticada. */
    public static function verifyCsrf(?string $token): bool
    {
        self::startSession();
        $expected = (string)($_SESSION['_csrf'] ?? '');
        return $expected !== '' && $token !== null && hash_equals($expected, (string)$token);
    }

    /**
     * Gera um token específico para a tela de login.
     *
     * Ele fica em cookie separado da sessão para que uma página de login aberta
     * antes do logout não fique inutilizada quando a sessão autenticada é destruída.
     * O cookie é HttpOnly e SameSite=Lax; o valor só precisa ser enviado de volta
     * no formulário para ser validado pelo servidor.
     */
    public static function loginCsrfToken(): string
    {
        $name = 'ystek_login_csrf';
        $token = (string)($_COOKIE[$name] ?? '');
        if ($token === '' || !preg_match('/^[a-f0-9]{64}$/', $token)) {
            $token = bin2hex(random_bytes(32));
            $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
                || (($_SERVER['SERVER_PORT'] ?? null) == 443);
            if (PHP_SAPI !== 'cli') {
                setcookie($name, $token, [
                    'expires' => time() + 86400,
                    'path' => '/',
                    'secure' => $secure,
                    'httponly' => true,
                    'samesite' => 'Lax',
                ]);
            }
            $_COOKIE[$name] = $token;
        }
        return $token;
    }

    /** Retorna o campo hidden de CSRF utilizado exclusivamente no login. */
    public static function loginCsrfInput(): string
    {
        return '<input type="hidden" name="_login_csrf" value="' . htmlspecialchars(self::loginCsrfToken(), ENT_QUOTES, 'UTF-8') . '">';
    }

    /** Valida o token do login contra o cookie correspondente. */
    public static function verifyLoginCsrf(?string $token): bool
    {
        $expected = (string)($_COOKIE['ystek_login_csrf'] ?? '');
        return $expected !== '' && $token !== null && hash_equals($expected, (string)$token);
    }


    /** Retorna o endereço remoto observado pelo servidor sem confiar cegamente em headers de proxy. */
    public static function clientIp(): string
    {
        $ip = trim((string)($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
        return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : 'unknown';
    }

    /** Remove o token atual para que ele seja substituído em uma nova sessão. */
    public static function rotateCsrf(): void
    {
        self::startSession();
        unset($_SESSION['_csrf']);
    }

    /** Limpa a sessão e invalida o cookie de sessão no navegador. */
    public static function destroySession(): void
    {
        self::startSession();
        $_SESSION = [];

        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires' => time() - 42000,
                'path' => $params['path'] ?? '/',
                'domain' => $params['domain'] ?? '',
                'secure' => (bool)($params['secure'] ?? false),
                'httponly' => (bool)($params['httponly'] ?? true),
                'samesite' => $params['samesite'] ?? 'Lax',
            ]);
        }

        session_destroy();
    }
}
