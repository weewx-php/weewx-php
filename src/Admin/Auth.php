<?php

declare(strict_types=1);

namespace WeewxPhp\Admin;

use WeewxPhp\Config\Settings;
use WeewxPhp\Db\Sqlite;

/** Private persistent sessions; browser cookies carry only random bearer tokens. */
final class Auth
{
    private readonly Sqlite $db;
    private const TTL = 7200;

    public function __construct(Settings $settings)
    {
        $this->db = Changes::store($settings);
        $this->db->exec('CREATE TABLE IF NOT EXISTS admin_account(id INTEGER PRIMARY KEY CHECK(id = 1), password TEXT NOT NULL)');
        $this->db->exec('CREATE TABLE IF NOT EXISTS admin_session(token TEXT PRIMARY KEY, csrf TEXT NOT NULL, expires INTEGER NOT NULL, authenticated INTEGER NOT NULL DEFAULT 0)');
        $this->db->exec('CREATE TABLE IF NOT EXISTS admin_login(peer TEXT PRIMARY KEY, window INTEGER NOT NULL, failures INTEGER NOT NULL)');
    }

    public function close(): void
    {
        $this->db->close();
    }

    public function configured(): bool
    {
        return $this->db->scalar('SELECT COUNT(*) FROM admin_account') === 1;
    }

    public function setPassword(string $password, int $now): void
    {
        if (strlen($password) < 12 || strlen($password) > 72 || str_contains($password, "\0")) {
            throw new Problem('error.password_length', 'password');
        }
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $this->db->transaction(function () use ($hash, $now): void {
            $this->db->exec('INSERT INTO admin_account(id, password) VALUES(1, ?) ON CONFLICT(id) DO UPDATE SET password = excluded.password', [$hash]);
            $this->db->exec('DELETE FROM admin_session');
            $this->db->exec('DELETE FROM admin_login');
            $this->audit('password.reset', $now);
        });
    }

    /**
     * @return array{token: string, csrf: string, authenticated: bool} */
    public function session(?string $token, int $now): array
    {
        $this->db->exec('DELETE FROM admin_session WHERE expires <= ?', [$now]);
        if ($token !== null && preg_match('/^[a-f0-9]{64}$/D', $token) === 1) {
            $row = $this->db->one('SELECT csrf, authenticated FROM admin_session WHERE token = ? AND expires > ?', [hash('sha256', $token), $now]);
            if ($row !== null) {
                return ['token' => $token, 'csrf' => Sqlite::text($row['csrf']), 'authenticated' => $row['authenticated'] === 1];
            }
        }
        if ((int) Sqlite::text($this->db->scalar('SELECT COUNT(*) FROM admin_session')) >= 2000) {
            throw new Problem('error.busy', status: 429);
        }
        return $this->create($now, false);
    }

    /**
     * @return array{token: string, csrf: string, authenticated: bool} */
    private function create(int $now, bool $authenticated): array
    {
        $token = bin2hex(random_bytes(32));
        $csrf = bin2hex(random_bytes(32));
        $this->db->exec(
            'INSERT INTO admin_session(token, csrf, expires, authenticated) VALUES(?, ?, ?, ?)',
            [hash('sha256', $token), $csrf, $now + ($authenticated ? self::TTL : 900), $authenticated],
        );
        return ['token' => $token, 'csrf' => $csrf, 'authenticated' => $authenticated];
    }

    /**
     * @return array{token: string, csrf: string, authenticated: bool} */
    public function login(string $token, string $csrf, string $password, string $peer, int $now): array
    {
        $session = $this->session($token, $now);
        self::csrf($session['csrf'], $csrf);
        if (strlen($password) > 72 || str_contains($password, "\0")) {
            throw new Problem('error.login', 'password', 403);
        }
        $bucket = hash('sha256', $peer);
        $this->db->transaction(function () use ($bucket, $now): void {
            $this->db->exec('DELETE FROM admin_login WHERE window < ?', [$now - 900]);
            foreach ([$bucket, '*'] as $key) {
                $row = $this->db->one('SELECT failures FROM admin_login WHERE peer = ?', [$key]);
                if ($row !== null && (int) Sqlite::text($row['failures']) >= ($key === '*' ? 50 : 5)) {
                    throw new Problem('error.rate_limit', status: 429);
                }
                $this->db->exec('INSERT INTO admin_login(peer, window, failures) VALUES(?, ?, 1) ON CONFLICT(peer) DO UPDATE SET failures = failures + 1', [$key, $now]);
            }
        });
        $hash = $this->db->scalar('SELECT password FROM admin_account WHERE id = 1');
        if (!is_string($hash) || !password_verify($password, $hash)) {
            $this->audit('login.failed', $now);
            throw new Problem('error.login', 'password', 403);
        }
        return $this->db->transaction(function () use ($token, $bucket, $now): array {
            $this->db->exec('DELETE FROM admin_session WHERE token = ?', [hash('sha256', $token)]);
            $this->db->exec('DELETE FROM admin_login WHERE peer = ?', [$bucket]);
            $this->audit('login.success', $now);
            return $this->create($now, true);
        });
    }

    public static function csrf(string $expected, string $provided): void
    {
        if ($provided === '' || !hash_equals($expected, $provided)) {
            throw new Problem('error.csrf', status: 403);
        }
    }

    public function logout(string $token, int $now): void
    {
        $this->db->exec('DELETE FROM admin_session WHERE token = ?', [hash('sha256', $token)]);
        $this->audit('logout', $now);
    }

    public function audit(string $action, int $now, string $subject = 'admin'): void
    {
        $this->db->exec('INSERT INTO admin_audit(created, action, subject) VALUES (?, ?, ?)', [$now, $action, $subject]);
        $this->db->exec('DELETE FROM admin_audit WHERE id < (SELECT MAX(id) - 2000 FROM admin_audit)');
    }
}
