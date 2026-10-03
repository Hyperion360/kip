<?php // src/Auth.php

declare(strict_types=1);
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
    private ?bool $oauthTable = null;

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
     * Whether password_verify() will do real work on $hash. Any bcrypt variant
     * ($2a$, $2b$, $2x$, $2y$) must have its full shape: crypt() rejects a bad salt
     * alphabet or an out-of-range cost in microseconds, and password_get_info() only
     * names $2y$ while password_verify() accepts all four. Anything else counts when
     * password_get_info() recognizes its algorithm.
     */
    private static function verifiable(string $hash): bool
    {
        if (str_starts_with($hash, '$2')) {
            return preg_match('~^\$2[abxy]\$(0[4-9]|[12][0-9]|3[01])\$[./A-Za-z0-9]{53}$~', $hash) === 1;
        }
        return password_get_info($hash)['algo'] !== null;
    }

    /**
     * Timing equalizer: every failed attempt does one full hash at PASSWORD_DEFAULT,
     * so response time does not reveal whether the account exists. An unknown email,
     * or a stored hash password_verify() cannot really check (empty, truncated, or a
     * bcrypt hash with a bad salt or cost, all rejected in microseconds; see
     * verifiable()), runs a discarded
     * password_hash($password, PASSWORD_DEFAULT): the same algorithm and cost
     * register() uses, on every PHP version, with nothing to keep in sync. Measured on
     * 8.4 at cost 12: 212ms for the discarded hash against 214ms verifying a real one.
     *
     * A valid hash stored at another cost or algorithm verifies at its own speed:
     * after moving from PHP 8.3 to 8.4 an existing account verifies at cost 10 (~52ms)
     * while an unknown email pays cost 12 (~210ms), and rows imported from another
     * system (argon2, a $2b$ prefix, or a low cost) differ the same way. So a
     * successful login rewrites any hash password_needs_rehash() flags at
     * PASSWORD_DEFAULT. LIMIT: an account stays distinguishable until its owner next
     * logs in, and for good when the rewrite is skipped (a NUL byte in the password, or
     * a non-bcrypt hash of a password over 72 bytes; see below). The rewrite changes the session epoch (see sessionValid()), so this
     * login gets the new epoch and that user's older sessions end, once.
     */
    public function attempt(string $email, string $password, string $ip = ''): bool
    {
        if ($this->throttled($email, $ip)) return false; // refuse before verifying. Correct password included
        $user = $this->db->one('SELECT * FROM users WHERE email = ?', [$email]);
        $hash = $user === null ? '' : (string) $user['password_hash'];
        if ($user === null || !self::verifiable($hash)) {
            // Discarded: the work a real verify costs. NULs are stripped because
            // password_hash() throws on them where password_verify() just returns false,
            // and a 500 on this path alone would reveal that the email is unknown.
            password_hash(str_replace("\0", '', $password), PASSWORD_DEFAULT);
            $this->recordAttempt($email, $ip, 'login');
            return false;
        }
        if (!password_verify($password, $hash)) {
            $this->recordAttempt($email, $ip, 'login');
            return false;
        }
        // Not rehashed: a NUL byte (argon2 verifies it, bcrypt's password_hash() throws), or a
        // non-bcrypt hash of a password over 72 bytes (bcrypt reads only the first 72, so it
        // would quietly get weaker; an old bcrypt hash already stopped at 72, so it loses
        // nothing). No transaction here: the compare-and-swap below is atomic on its own,
        // and a caller may already have one open.
        $weakens = strlen($password) > 72 && !str_starts_with($hash, '$2');
        $rehash = password_needs_rehash($hash, PASSWORD_DEFAULT) && !str_contains($password, "\0") && !$weakens
            ? password_hash($password, PASSWORD_DEFAULT) : null;
        // Compare-and-swap on the hash just verified: a reset or admin edit that landed
        // since then wins, rather than being overwritten with the old password.
        if ($rehash !== null) {
            if ($this->db->query('UPDATE users SET password_hash = ? WHERE id = ? AND password_hash = ?',
                    [$rehash, $user['id'], $hash])->rowCount() === 1) {
                $hash = $rehash;
            } else {
                // Lost the race. A concurrent login with this same password rehashed
                // it first: take that hash's epoch so both sessions stay valid. A reset
                // to another password does not verify, and this session stays on the
                // old epoch, which the reset has revoked.
                $current = (string) ($this->db->one('SELECT password_hash FROM users WHERE id = ?', [$user['id']])['password_hash'] ?? '');
                if (password_verify($password, $current)) $hash = $current;
            }
        }
        $this->db->query('DELETE FROM login_attempts WHERE email = ?', [$email]);
        ($this->regenerator)();
        $this->session->set('user_id', $user['id']);
        $this->session->set('pwd_epoch', substr($hash, 0, self::EPOCH_LEN));
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

    /** @return array<array-key, mixed>|null the id and email columns */
    public function user(): ?array
    {
        $id = $this->session->get('user_id');
        return $id === null ? null : $this->db->one('SELECT id, email FROM users WHERE id = ?', [$id]);
    }

    public function logout(): void
    {
        $this->session->forget('user_id');
        $this->session->forget(Auth\OAuthProvider::SESSION_KEY); // pending OAuth flows die with the login
        $this->session->rotateCsrf();     // stale tokens die with the login (QA T4)
        ($this->regenerator)();           // same fixation defense as attempt()
    }

    /**
     * Attach a provider identity to an account. Never transfers ownership:
     * an identity already linked to another user answers false, and the row
     * keeps its original owner. The caller must have authenticated the user
     * (the OAuth callback links the session's own user, never an email).
     */
    public function linkOAuthIdentity(int $userId, string $provider, string $providerUid): bool
    {
        $this->assertOAuthTable();
        $this->db->query(
            'INSERT INTO oauth_identities (provider, provider_uid, user_id) VALUES (?, ?, ?) '
            . 'ON CONFLICT(provider, provider_uid) DO NOTHING',
            [$provider, $providerUid, $userId]
        );
        $row = $this->db->one(
            'SELECT user_id FROM oauth_identities WHERE provider = ? AND provider_uid = ?',
            [$provider, $providerUid]
        );
        return $row !== null && (int) $row['user_id'] === $userId;
    }

    /**
     * Login-or-register for a completed OAuth flow. The identity row is the
     * only login key: a match logs that user in whatever the email says.
     * Without a match, a provider-VERIFIED email may seed a brand-new
     * account (random unusable password), and nothing else: an email that
     * already exists locally is refused, never linked, because a local
     * account has never proven its address (a verified provider email
     * merging into it would hand the pre-registrant shared control). An
     * unverified or absent email is refused the same way, which is why a
     * provider whose user-info carries no verification marker can attach to
     * an existing account only through linkOAuthIdentity() after a password
     * login. Returns ['user' => id, 'created' => bool], or null when the
     * flow must not log anyone in; the session is published only after the
     * transaction commits.
     *
     * @return array{user: int, created: bool}|null
     */
    public function loginOrRegisterOAuth(string $provider, string $providerUid, ?string $email, bool $emailVerified): ?array
    {
        $this->assertOAuthTable();
        $email = $email === null ? null : strtolower(trim($email));
        if ($email === '') $email = null;
        $this->db->begin();
        try {
            $identity = $this->db->one(
                'SELECT user_id FROM oauth_identities WHERE provider = ? AND provider_uid = ?',
                [$provider, $providerUid]
            );
            if ($identity !== null) {
                $user = $this->db->one('SELECT id, password_hash FROM users WHERE id = ?', [$identity['user_id']]);
                if ($user !== null) {
                    $this->db->commit();
                    $this->establishSession((int) $user['id'], (string) $user['password_hash']);
                    return ['user' => (int) $user['id'], 'created' => false];
                }
                // Stale row: the user was deleted and the app runs without
                // foreign keys. Drop it and fall through to the email policy.
                $this->db->query(
                    'DELETE FROM oauth_identities WHERE provider = ? AND provider_uid = ?',
                    [$provider, $providerUid]
                );
            }
            if ($email === null || !$emailVerified) {
                $this->db->commit();
                return null;
            }
            // COUNT, not one(): a schema without UNIQUE(email) could hold
            // several rows, and picking one would attach the identity to an
            // arbitrary account. Any occurrence refuses.
            $taken = (int) $this->db->one(
                'SELECT COUNT(*) c FROM users WHERE email = ? COLLATE NOCASE', [$email]
            )['c'];
            if ($taken > 0) {
                $this->db->commit();
                return null;
            }
            try {
                $this->db->query('INSERT INTO users (email, password_hash) VALUES (?, ?)',
                    [$email, password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT)]);
                $userId = (int) $this->db->lastInsertId();
                $this->db->query('INSERT INTO oauth_identities (provider, provider_uid, user_id) VALUES (?, ?, ?)',
                    [$provider, $providerUid, $userId]);
            } catch (\PDOException $e) {
                $constraint = (int) $e->getCode() === 19 || ($e->errorInfo[1] ?? null) === 19; // SQLITE_CONSTRAINT
                if (!$constraint) throw $e;
                // The COUNT above lost a race: a simultaneous first sign-in with the
                // same verified email won the UNIQUE(email) insert. The documented
                // outcome is the refusal, not a 500; the rollback discards our half.
                $this->db->rollBack();
                return null;
            }
            $hash = (string) $this->db->one('SELECT password_hash FROM users WHERE id = ?', [$userId])['password_hash'];
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
        $this->establishSession($userId, $hash);
        return ['user' => $userId, 'created' => true];
    }

    /** The fixation defense and epoch of attempt(), shared by the OAuth login path. */
    private function establishSession(int $userId, string $hash): void
    {
        ($this->regenerator)();
        $this->session->set('user_id', $userId);
        $this->session->set('pwd_epoch', substr($hash, 0, self::EPOCH_LEN));
    }

    /** Memoized: does oauth_identities exist (the app adopted OAuth)? */
    private function oauthIdentitiesPresent(): bool
    {
        if ($this->oauthTable === null) {
            try { $this->db->one('SELECT provider FROM oauth_identities LIMIT 1'); $this->oauthTable = true; }
            catch (\PDOException) { $this->oauthTable = false; }
        }
        return $this->oauthTable;
    }

    private function assertOAuthTable(): void
    {
        if (!$this->oauthIdentitiesPresent()) {
            throw new \RuntimeException(
                'the oauth_identities table is missing; apply the bundled app migration '
                . '009_create_oauth_identities before using OAuth sign-in'
            );
        }
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
            if ($this->oauthIdentitiesPresent()) {
                // A reset often signals compromise; a linked provider whose
                // account was hijacked must not survive it. Re-linking costs
                // the owner one click. Apps without the table reset as before.
                $this->db->query(
                    'DELETE FROM oauth_identities WHERE user_id IN (SELECT id FROM users WHERE email = ?)',
                    [$row['email']]
                );
            }
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
