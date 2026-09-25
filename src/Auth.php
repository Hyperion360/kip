<?php // src/Auth.php
namespace Kip;

final class Auth
{
    private const MAX_ATTEMPTS = 5;
    private const WINDOW_MINUTES = 15;
    private const RESET_MAX_PER_ACCOUNT = 3; // stricter: a reset flood against one account
    private const RESET_MAX_PER_IP = 10;     // looser: shared NATs must not mass-lock victims
    public const EPOCH_LEN = 12;             // password-hash prefix a session must still match
    /** Raw reset token is 32 random bytes; its hex form (what travels in URLs) is twice that. */
    public const RESET_TOKEN_BYTES = 32;
    public const RESET_TOKEN_HEX = 64;

    /** @var callable */
    private $regenerator;
    private ?bool $kindSplit = null;

    public function __construct(
        private Database $db,
        private Session $session,
        ?callable $regenerator = null,
    ) {
        $this->regenerator = $regenerator ?? static function (): void {
            if (session_status() === PHP_SESSION_ACTIVE) session_regenerate_id(true);
        };
    }

    public function register(string $email, string $password): void
    {
        $this->db->query('INSERT INTO users (email, password_hash) VALUES (?, ?)',
            [$email, password_hash($password, PASSWORD_DEFAULT)]);
    }

    /**
     * Note: the IP received here is already proxy-resolved by
     * Request::fromGlobals(), set KIP_TRUSTED_PROXY=1 behind a reverse
     * proxy so the last X-Forwarded-For hop (the true client) is used;
     * without it, REMOTE_ADDR is the proxy and the IP clause degrades to a
     * site-wide counter (see README, Reverse proxy).
     */
    public function throttled(string $email, string $ip): bool
    {
        $since = date('c', time() - self::WINDOW_MINUTES * 60);
        // julianday() compares instants, not strings: date('c') carries the local offset,
        // and lexicographic compare skews across DST transitions.
        $where = $this->kindSplit()
            ? "kind = 'login' AND (email = ? OR ip = ?) AND julianday(attempted_at) > julianday(?)"
            : '(email = ? OR ip = ?) AND julianday(attempted_at) > julianday(?)';
        // Opportunistic prune: rows outside the window are dead weight forever otherwise.
        $this->db->query('DELETE FROM login_attempts WHERE julianday(attempted_at) <= julianday(?)', [$since]);
        $row = $this->db->one("SELECT COUNT(*) c FROM login_attempts WHERE {$where}", [$email, $ip, $since]);
        return (int) $row['c'] >= self::MAX_ATTEMPTS;
    }

    /**
     * Reset requests get their OWN bucket once the app has the login_attempts.kind
     * column: per-account stricter than login, per-IP looser, a login-failure flood
     * must never block the victim's way back in via "forgot password" (and reset spam
     * must never lock login). Without the column, resets share the login bucket
     * (the original, stricter behavior, e.g. the frozen tutorial blog).
     */
    public function resetThrottled(string $email, string $ip): bool
    {
        if (!$this->kindSplit()) return $this->throttled($email, $ip);
        $since = date('c', time() - self::WINDOW_MINUTES * 60);
        $this->db->query('DELETE FROM login_attempts WHERE julianday(attempted_at) <= julianday(?)', [$since]); // prune on this path too. Reset floods must not wait for a login to be cleaned
        $byAccount = (int) $this->db->one(
            "SELECT COUNT(*) c FROM login_attempts WHERE kind = 'reset' AND email = ? AND julianday(attempted_at) > julianday(?)",
            [$email, $since]
        )['c'];
        $byIp = (int) $this->db->one(
            "SELECT COUNT(*) c FROM login_attempts WHERE kind = 'reset' AND ip = ? AND julianday(attempted_at) > julianday(?)",
            [$ip, $since]
        )['c'];
        return $byAccount >= self::RESET_MAX_PER_ACCOUNT || $byIp >= self::RESET_MAX_PER_IP;
    }

    /**
     * Timing-equalizer: a real bcrypt hash of an unguessable value, verified on the
     * unknown-email path so response time does not reveal account existence.
     *
     * INVARIANT: its cost MUST equal what password_hash(..., PASSWORD_DEFAULT)
     * produces for real users (see register() above). If it is cheaper, the
     * unknown-email path finishes measurably sooner and the equalizer leaks the
     * very thing it exists to hide. This hash was cost 10 while PASSWORD_DEFAULT
     * had moved to 12, which made unknown-email logins ~4x faster than real ones
     * (53ms vs 210ms, measured). AuthTest asserts the parity, so PHP raising its
     * default again fails the suite instead of silently reopening the oracle.
     *
     * Plaintext is 32 random bytes, so no real password can match it.
     */
    private const DUMMY_HASH = '$2y$12$jgEVQOxK3JGQp1XzYOGoHu1UX2K16qRBjuTfmiR4r3eEXOuE3n0YS';

    public function attempt(string $email, string $password, string $ip = ''): bool
    {
        if ($this->throttled($email, $ip)) return false; // refuse before verifying. Correct password included
        $user = $this->db->one('SELECT * FROM users WHERE email = ?', [$email]);
        if ($user === null) {
            password_verify($password, self::DUMMY_HASH); // identical work either way (as createReset does)
            $this->recordAttempt($email, $ip, 'login');
            return false;
        }
        if (!password_verify($password, (string) $user['password_hash'])) {
            $this->recordAttempt($email, $ip, 'login');
            return false;
        }
        $this->db->query('DELETE FROM login_attempts WHERE email = ?', [$email]);
        ($this->regenerator)();
        $this->session->set('user_id', $user['id']);
        $this->session->set('pwd_epoch', substr((string) $user['password_hash'], 0, self::EPOCH_LEN));
        return true;
    }

    /**
     * A logged-in session stays valid only while the user's password is unchanged:
     * the session carries the first EPOCH_LEN chars of the hash (the "epoch"), and
* a password reset or admin password edit, which rewrites the hash, revokes
     * every session logged in before it. Fail-closed: no users table, no row, or a
     * legacy session without an epoch are all invalid.
     */
    public function sessionValid(): bool
    {
        $id = $this->session->get('user_id');
        $epoch = $this->session->get('pwd_epoch');
        if ($id === null || !is_string($epoch)) return false;
        try {
            $user = $this->db->one('SELECT password_hash FROM users WHERE id = ?', [$id]);
        } catch (\PDOException) {
            return false;
        }
        return $user !== null && hash_equals(substr((string) $user['password_hash'], 0, self::EPOCH_LEN), $epoch);
    }

    public function user(): ?array
    {
        $id = $this->session->get('user_id');
        return $id === null ? null : $this->db->one('SELECT id, email FROM users WHERE id = ?', [$id]);
    }

    public function logout(): void
    {
        $this->session->forget('user_id');
        $this->session->rotateCsrf();     // stale tokens die with the login (QA T4)
        ($this->regenerator)();           // same fixation defense as attempt()
    }

    /**
     * Start a password reset. Returns the RAW token to email (only its sha256
     * lands in the DB), or null when the email is unknown OR the email/IP pair
     * is throttled. The caller shows the same "check your email" page either
     * way (no account enumeration). Every request burns a login_attempts row,
     * so reset spam shares the 5-per-15-minutes login throttle (email-flood guard).
     * The token is generated (and hashed) on BOTH paths so response time does not
     * leak account existence; the DELETE+INSERT pair is transactional so a
     * double-submit cannot interleave into a PK violation.
     */
    public function createReset(string $email, string $ip = ''): ?string
    {
        if ($this->resetThrottled($email, $ip)) return null;
        $this->recordAttempt($email, $ip, 'reset');
        $token = bin2hex(random_bytes(self::RESET_TOKEN_BYTES));   // identical work either way
        $hash = hash('sha256', $token);
        $user = $this->db->one('SELECT id FROM users WHERE email = ?', [$email]);
        if ($user === null) return null;
        $this->db->begin();
        try {
            $this->db->query('DELETE FROM password_resets WHERE email = ?', [$email]);   // one live token per account
            $this->db->query('INSERT INTO password_resets (email, token_hash, expires_at) VALUES (?, ?, ?)',
                [$email, $hash, date('c', time() + 1800)]);                              // 30-minute window
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
        return $token;
    }

    /** Consume a reset token: single-use, expiry-checked (instant compare, DST-safe),
     *  transactional, clears the throttle on success. The hash rewrite also revokes
     *  every pre-reset session (the epoch check above). */
    public function resetPassword(string $token, string $password): bool
    {
        $row = $this->db->one('SELECT * FROM password_resets WHERE token_hash = ?', [hash('sha256', $token)]);
        if ($row === null || strtotime((string) $row['expires_at']) < time()) return false;
        $this->db->begin();
        try {
            $this->db->query('DELETE FROM password_resets WHERE email = ?', [$row['email']]);
            $this->db->query('UPDATE users SET password_hash = ? WHERE email = ?',
                [password_hash($password, PASSWORD_DEFAULT), $row['email']]);
            $this->db->query('DELETE FROM login_attempts WHERE email = ?', [$row['email']]);
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
        return true;
    }

    /** Memoized: does login_attempts carry the kind column (split buckets)? */
    private function kindSplit(): bool
    {
        if ($this->kindSplit === null) {
            try { $this->db->one('SELECT kind FROM login_attempts LIMIT 1'); $this->kindSplit = true; }
            catch (\PDOException) { $this->kindSplit = false; }
        }
        return $this->kindSplit;
    }

    private function recordAttempt(string $email, string $ip, string $kind): void
    {
        if ($this->kindSplit()) {
            $this->db->query('INSERT INTO login_attempts (email, ip, attempted_at, kind) VALUES (?, ?, ?, ?)',
                [$email, $ip, date('c'), $kind]);
        } else {
            $this->db->query('INSERT INTO login_attempts (email, ip, attempted_at) VALUES (?, ?, ?)',
                [$email, $ip, date('c')]);
        }
    }
}
