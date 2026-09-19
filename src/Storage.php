<?php // src/Storage.php
namespace Kip;

/**
 * Validated file uploads. Threat model: attacker-controlled filename and
 * content. Defenses: extension whitelist (no .php, no .svg by default),
 * finfo MIME must match the claimed extension, server-generated random
 * filename (no traversal, no overwrite, no attacker-chosen path), size cap.
 */
final class Storage
{
    public const DEFAULT_MAX_BYTES = 5_242_880;
    /** Never storable, regardless of the configured extension list, execution and stored-XSS vectors. */
    private const DENY_EXT = ['php', 'phtml', 'phar', 'inc', 'cgi', 'htaccess', 'html', 'htm', 'shtml', 'svg', 'xml', 'xhtml', 'xht', 'js', 'mjs', 'swf'];
    private const DEFAULT_EXT = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf', 'txt', 'csv'];
    private const MIME = [
        'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif',
        'webp' => 'image/webp', 'pdf' => 'application/pdf', 'txt' => 'text/plain', 'csv' => 'text/csv',
    ];

    /** @var callable(string,string):bool */
    private $mover;

    /** @param ?callable(string,string):bool $mover test seam, move_uploaded_file() rejects non-SAPI files */
    public function __construct(
        private string $dir,
        private int $maxBytes = self::DEFAULT_MAX_BYTES,
        private ?array $allowedExt = null,
        ?callable $mover = null,
    ) {
        $this->mover = $mover ?? static fn(string $tmp, string $dest): bool => move_uploaded_file($tmp, $dest);
    }

    /** @param array $file one $_FILES entry. @return string public path, e.g. /uploads/9f2ab3….png */
    public function put(array $file): string
    {
        if (($file['error'] ?? -1) !== UPLOAD_ERR_OK) {
            throw new UploadException('Upload failed (PHP error code ' . ($file['error'] ?? 'unknown') . ')');
        }
        if (($file['size'] ?? 0) > $this->maxBytes) {
            throw new UploadException("File exceeds the {$this->maxBytes}-byte limit");
        }
        // The reported size is client-influenced metadata; the file on disk is authoritative.
        $actual = filesize((string) ($file['tmp_name'] ?? ''));
        if ($actual === false || $actual > $this->maxBytes) {
            throw new UploadException("File exceeds the {$this->maxBytes}-byte limit");
        }
        $ext = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
        if (in_array($ext, self::DENY_EXT, true)) {
            throw new UploadException("File type .{$ext} is never allowed");
        }
        if (!in_array($ext, $this->allowedExt ?? self::DEFAULT_EXT, true)) {
            throw new UploadException("File type .{$ext} is not allowed");
        }
        if (!isset(self::MIME[$ext])) {
            // Every stored extension gets a content check, no silently-unverified types (defense review).
            throw new UploadException("No content check is defined for .{$ext}, only " . implode(', ', array_keys(self::MIME)) . ' can be stored');
        }
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file((string) $file['tmp_name']);
        if ($mime !== self::MIME[$ext]) {
            throw new UploadException("File content does not match .{$ext}");
        }
        if (!is_dir($this->dir) && !mkdir($this->dir, 0755, true) && !is_dir($this->dir)) {
            throw new UploadException("Cannot create uploads directory {$this->dir}");
        }
        $name = bin2hex(random_bytes(8)) . '.' . $ext;
        if (!($this->mover)((string) $file['tmp_name'], $this->dir . '/' . $name)) {
            throw new UploadException('Could not move the uploaded file');
        }
        return '/uploads/' . $name;
    }
}
