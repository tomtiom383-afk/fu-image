<?php

declare(strict_types=1);

namespace ImageHosting;

class Auth
{
    private Config $config;

    private const REMEMBER_COOKIE = 'image_remember';

    public function __construct(Config $config)
    {
        $this->config = $config;
    }

    public function start(): void
    {
        $name = $this->config->get('security.cookie_name', 'image_session');
        if (session_name() !== $name) {
            session_name($name);
        }

        $lifetime = (int) $this->config->get('security.session_lifetime', 7776000);
        ini_set('session.gc_maxlifetime', (string) $lifetime);

        $options = [
            'lifetime' => $lifetime,
            'path' => '/',
            'secure' => Security::isHttpsRequest(),
            'httponly' => true,
            'samesite' => 'Lax',
        ];
        session_set_cookie_params($options);
        session_start();

        if (!isset($_SESSION['_initiated'])) {
            session_regenerate_id(true);
            $_SESSION['_initiated'] = true;
        }

        $this->restorePersistentLogin();
    }

    public function isLoggedIn(): bool
    {
        return !empty($_SESSION['admin']) && $_SESSION['admin'] === true;
    }

    public function currentUser(): string
    {
        return $_SESSION['user'] ?? $this->config->get('auth.admin_user', 'admin');
    }

    public function isAuthorized(): bool
    {
        if ($this->isLoggedIn()) {
            return true;
        }
        return $this->verifyApiKey($this->getApiKey());
    }

    public function requireAuth(): void
    {
        if (!$this->isAuthorized()) {
            Response::error('未授权，请先登录或使用有效 API Token', 401);
        }
    }

    public function login(string $user, string $password, bool $remember = false): bool
    {
        $expectedUser = $this->config->get('auth.admin_user', 'admin');
        if ($user !== $expectedUser) {
            return false;
        }

        if (!$this->verifyPassword($password)) {
            return false;
        }

        session_regenerate_id(true);
        $_SESSION['admin'] = true;
        $_SESSION['user'] = $user;

        if ($remember) {
            $this->issuePersistentLogin($user);
        } else {
            $this->clearPersistentLogin();
        }

        return true;
    }

    public function logout(): void
    {
        $_SESSION = [];
        $this->clearPersistentLogin();
        $name = $this->config->get('security.cookie_name', 'image_session');
        if (isset($_COOKIE[$name])) {
            setcookie($name, '', $this->cookieOptions(1, true));
        }
        session_destroy();
    }

    private function verifyPassword(string $password): bool
    {
        $legacyHash = (string) $this->config->get('auth.password_legacy_hash', '');
        $modernHash = (string) $this->config->get('auth.password_hash', '');

        if ($legacyHash !== '') {
            if (hash('sha256', $password) === $legacyHash) {
                $this->migratePassword($password);
                return true;
            }
        }

        if ($modernHash !== '') {
            return password_verify($password, $modernHash);
        }

        return false;
    }

    private function migratePassword(string $password): void
    {
        $configPath = $this->config->path();
        $content = file_get_contents($configPath);
        if ($content === false) {
            return;
        }
        $data = json_decode($content, true);
        if (!is_array($data)) {
            return;
        }
        $data['auth']['password_hash'] = password_hash($password, PASSWORD_DEFAULT);
        unset($data['auth']['password_legacy_hash']);
        file_put_contents($configPath, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
        Config::reset();
    }

    public function getApiKey(): string
    {
        return $_POST['token'] ?? $_GET['token'] ?? $_SERVER['HTTP_X_API_KEY'] ?? '';
    }

    public function verifyApiKey(string $token): bool
    {
        if ($token === '') {
            return false;
        }
        $keys = $this->config->get('auth.api_keys', []);
        if (!is_array($keys) || !isset($keys[$token])) {
            return false;
        }
        $expires = (int) ($keys[$token]['expires'] ?? 0);
        return $expires === 0 || $expires > time();
    }

    public function getValidApiKey(): string
    {
        $keys = $this->config->get('auth.api_keys', []);
        if (!is_array($keys)) {
            return '';
        }
        foreach ($keys as $token => $info) {
            $expires = (int) ($info['expires'] ?? 0);
            if ($expires === 0 || $expires > time()) {
                return $token;
            }
        }
        return '';
    }

    private function issuePersistentLogin(string $user): void
    {
        $lifetime = (int) $this->config->get('security.remember_lifetime', 7776000);
        $expires = time() + $lifetime;
        $payload = json_encode([
            'user' => $user,
            'expires' => $expires,
            'sig' => $this->persistentSignature($user, $expires),
        ], JSON_UNESCAPED_SLASHES);
        setcookie(self::REMEMBER_COOKIE, $this->base64UrlEncode($payload), $this->cookieOptions($lifetime, false));
    }

    private function clearPersistentLogin(): void
    {
        setcookie(self::REMEMBER_COOKIE, '', $this->cookieOptions(1, true));
    }

    private function restorePersistentLogin(): void
    {
        if ($this->isLoggedIn()) {
            return;
        }
        $cookie = $_COOKIE[self::REMEMBER_COOKIE] ?? '';
        if ($cookie === '') {
            return;
        }
        $payload = json_decode($this->base64UrlDecode($cookie), true);
        if (!is_array($payload)) {
            $this->clearPersistentLogin();
            return;
        }
        $user = (string) ($payload['user'] ?? '');
        $expires = (int) ($payload['expires'] ?? 0);
        $sig = (string) ($payload['sig'] ?? '');
        $expectedUser = $this->config->get('auth.admin_user', 'admin');

        if ($user !== $expectedUser || $expires <= time() || !hash_equals($this->persistentSignature($user, $expires), $sig)) {
            $this->clearPersistentLogin();
            return;
        }

        $_SESSION['admin'] = true;
        $_SESSION['user'] = $user;
        $this->refreshSessionCookie();
    }

    private function persistentSignature(string $user, int $expires): string
    {
        $secret = $this->persistentSecret();
        return hash_hmac('sha256', $user . '|' . $expires, $secret);
    }

    private function persistentSecret(): string
    {
        $hash = (string) $this->config->get('auth.password_legacy_hash', '');
        if ($hash === '') {
            $hash = (string) $this->config->get('auth.password_hash', '');
        }
        return hash('sha256', 'image-remember-v2|' . $hash);
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function base64UrlDecode(string $value): string
    {
        $padding = strlen($value) % 4;
        if ($padding) {
            $value .= str_repeat('=', 4 - $padding);
        }
        $decoded = base64_decode(strtr($value, '-_', '+/'), true);
        return $decoded === false ? '' : $decoded;
    }

    private function refreshSessionCookie(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return;
        }
        $lifetime = (int) $this->config->get('security.session_lifetime', 7776000);
        setcookie(session_name(), session_id(), $this->cookieOptions($lifetime, false));
    }

    private function cookieOptions(int $lifetime, bool $past): array
    {
        return [
            'expires' => $past ? time() - 3600 : time() + $lifetime,
            'path' => '/',
            'secure' => Security::isHttpsRequest(),
            'httponly' => true,
            'samesite' => 'Lax',
        ];
    }
}
