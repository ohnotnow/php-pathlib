<?php

namespace Ohffs\PhpPathlib;

use RecursiveIteratorIterator;
use RecursiveDirectoryIterator;

final class Path
{
    private string $path;
    private static ?string $base = null;
    private static bool $autoExpandTilde = false;

    /**
     * Constructor
     */
    public function __construct(string $path)
    {
        $this->path = $path;
    }

    /**
     * Static factory method
     */
    public static function of(string $path): self
    {
        return new self($path);
    }

    public static function setAutoExpandTilde(bool $on = true): void
    {
        self::$autoExpandTilde = $on;
    }

    /**
     * Enable fake filesystem mode for testing.
     *
     * Returns a guard object that will automatically clean up the fake base
     * when destroyed (e.g. at end of a test). You can also call Path::unfake().
     */
    public static function fake(?string $prefix = 'fake-filesystem-'): ScopedFake
    {
        $rand = bin2hex(random_bytes(8));
        $dir  = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR)
              . DIRECTORY_SEPARATOR
              . $prefix . $rand;

        if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
            throw new \RuntimeException("Failed to create fake filesystem at: {$dir}");
        }

        self::$base = $dir;

        return new ScopedFake(
            $dir,
            function () {
                Path::unfake();
            }
        );
    }

    /**
     * Disable fake filesystem mode and remove the fake directory.
     */
    public static function unfake(): void
    {
        $base = self::$base;
        self::$base = null;

        if ($base && is_dir($base)) {
            self::rrmdir($base);
        }
    }

    /**
     * String representation: logical path (not the temp base).
     */
    public function __toString(): string
    {
        return $this->path;
    }

    /**
     * Check if path exists
     */
    public function exists(): bool
    {
        return file_exists($this->actual());
    }

    /**
     * Check if path is a file
     */
    public function isFile(): bool
    {
        return is_file($this->actual());
    }

    /**
     * Check if path is a directory
     */
    public function isDir(): bool
    {
        return is_dir($this->actual());
    }

    /**
     * Check if path is absolute
     */
    public function isAbsolute(): bool
    {
        // Unix absolute
        if (strpos($this->path, '/') === 0) {
            return true;
        }
        // Windows absolute (C:\ or \\server\share)
        if (preg_match('/^(?:[A-Z]:\\\\|\\\\\\\\)/i', $this->path)) {
            return true;
        }
        return false;
    }

    public function parent(): self
    {
        $norm = $this->normalizeSeparators($this->path);
        $parent = dirname($norm);
        return new self($parent);
    }

    public function name(): string
    {
        $norm = $this->normalizeSeparators($this->path);
        return basename($norm);
    }

    public function stem(): string
    {
        $name = $this->name();
        $pos = strrpos($name, '.');

        // If no dot or the only dot is leading (dotfile), stem is the whole name
        if ($pos === false || $pos === 0) {
            return $name;
        }
        return substr($name, 0, $pos);
    }

    public function suffix(): string
    {
        $name = $this->name();
        $pos = strrpos($name, '.');

        // No extension if no dot or the only dot is leading (dotfile)
        if ($pos === false || $pos === 0) {
            return '';
        }
        return substr($name, $pos + 1);
    }

    public function withSuffix(string $suffix): self
    {
        $stem = $this->stem();
        $parent = dirname($this->normalizeSeparators($this->path));
        $suffix = ltrim($suffix, '.');

        $newName = $suffix === '' ? $stem : "{$stem}.{$suffix}";

        return $parent === '.' ? new self($newName)
                               : new self($parent . DIRECTORY_SEPARATOR . $newName);
    }

    /**
     * Read text from file
     */
    public function readText(): string
    {
        $actual = $this->actual();
        if (!is_file($actual)) {
            throw new \InvalidArgumentException("Path is not a file: {$this->path}");
        }
        $data = file_get_contents($actual);
        if ($data === false) {
            throw new \RuntimeException("Failed to read: {$this->path}");
        }
        return $data;
    }

    /**
     * Write text to file
     */
    public function writeText(string $content): void
    {
        $actual = $this->actual();
        $dir = dirname($actual);
        if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
            throw new \RuntimeException("Failed to create directory: {$dir}");
        }
        if (file_put_contents($actual, $content) === false) {
            throw new \RuntimeException("Failed to write: {$this->path}");
        }
    }

    public function parts(): array
    {
        $p = self::normalizeSlashes($this->path); // use forward slashes

        // Handle Windows drive like "C:/..."
        $drive = null;
        if (preg_match('/^([A-Za-z]:)\//', $p, $m)) {
            $drive = $m[1];           // e.g. "C:"
            $p = substr($p, strlen($m[0])); // strip "C:/"
        }

        // Handle UNC like "//server/share/..."
        if (strpos($p, '//') === 0) {
            // strip leading '//' and treat first two segments as server/share
            $unc = substr($p, 2);
            $segments = array_values(array_filter(explode('/', $unc), fn($s) => $s !== ''));
            if (count($segments) >= 2) {
                $server = array_shift($segments);
                $share  = array_shift($segments);
                $parts = [$server, $share, ...$segments];
                return $drive ? array_merge([$drive], $parts) : $parts;
            }
            // fallback: no share given
            $p = $unc;
        }

        // For POSIX absolute paths, drop the leading slash
        if (isset($p[0]) && $p[0] === '/') {
            $p = substr($p, 1);
        }

        // Trim trailing slash and split
        $p = rtrim($p, '/');
        if ($p === '' && !$drive) {
            return [];
        }

        $parts = $p === '' ? [] : array_values(array_filter(explode('/', $p), fn($s) => $s !== ''));

        // Prepend drive if present
        if ($drive) {
            array_unshift($parts, $drive);
        }

        return $parts;
    }

    /**
     * Join path with another
     */
    public function joinpath(string ...$parts): self
    {
        $combined = $this->path;
        foreach ($parts as $part) {
            $combined = rtrim($combined, '/\\') . DIRECTORY_SEPARATOR . ltrim($part, '/\\');
        }
        return new self($combined);
    }

    /**
     * Create directory (including parents)
     */
    public function mkdir(bool $parents = false, int $mode = 0777): void
    {
        $actual = $this->actual();
        if (!is_dir($actual) && !mkdir($actual, $mode, $parents) && !is_dir($actual)) {
            throw new \RuntimeException("Failed to create directory: {$this->path}");
        }
    }

    /**
     * Iterate directory contents (returns Path[])
     */
    public function iterdir(): array
    {
        $actual = $this->actual();
        if (!is_dir($actual)) {
            throw new \InvalidArgumentException("Path is not a directory: {$this->path}");
        }

        $items = [];
        $entries = scandir($actual);
        if ($entries === false) {
            throw new \RuntimeException("Failed to read dir: {$this->path}");
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $items[] = new self($this->path . DIRECTORY_SEPARATOR . $entry);
        }
        return $items;
    }

    public function glob(?string $pattern = null): array
    {
        $isDir = $this->isDir();
        $dirActual = $isDir ? $this->actual() : dirname($this->actual());
        $dirLogical = $isDir ? $this->path : dirname($this->path);

        $pat = $pattern ?? ($isDir ? '*' : basename($this->path));
        $matches = glob(rtrim($dirActual, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $pat) ?: [];

        $out = [];
        foreach ($matches as $full) {
            $logical = rtrim($dirLogical, "/\\") . DIRECTORY_SEPARATOR . basename($full);
            $out[] = new self($logical);
        }
        return $out;
    }

    /**
     * Change filename
     */
    public function withName(string $name): self
    {
        $parent = dirname($this->path);
        return $parent === '.' ? new self($name)
                               : new self($parent . DIRECTORY_SEPARATOR . $name);
    }

    /**
     * Expand ~ to home directory
     */
    public function expanduser(): self
    {
        if (strpos($this->path, '~') === 0) {
            $home = getenv('HOME') ?: ($_SERVER['HOME'] ?? null);
            if (!$home && function_exists('posix_getpwuid')) {
                $home = posix_getpwuid(posix_getuid())['dir'] ?? null;
            }
            $expanded = ($home ?: '~') . substr($this->path, 1);
            return new self($expanded);
        }
        return $this;
    }

    private static function homeDir(): ?string
    {
        $home = getenv('HOME') ?: ($_SERVER['HOME'] ?? null)
            ?: getenv('USERPROFILE')
            ?: ((getenv('HOMEDRIVE') && getenv('HOMEPATH')) ? getenv('HOMEDRIVE') . getenv('HOMEPATH') : null);
        if (!$home && function_exists('posix_getpwuid')) {
            $home = posix_getpwuid(posix_getuid())['dir'] ?? null;
        }
        return $home ?: null;
    }

    /**
     * Resolve to a normalized logical path (doesn't escape the fake base).
     * If the actual path exists on a real FS, use realpath for normalization.
     */
    public function resolve(): self
    {
        $actual = $this->actual();

        if (file_exists($actual)) {
            $real = realpath($actual);
            if ($real === false) {
                // Fall through to manual normalization
                $real = $actual;
            }
            // Strip base when mapping back to logical path
            $logical = self::$base
                ? ltrim(substr($real, strlen(self::$base)), DIRECTORY_SEPARATOR)
                : $real;
            return new self(self::normalizeSlashes($logical));
        }

        // Manual normalization for non-existent paths
        $p = self::normalizeSlashes($this->path);
        $isAbs = strlen($p) > 0 && $p[0] === '/';

        $parts = array_values(array_filter(explode('/', $p), fn($x) => $x !== ''));
        $stack = [];
        foreach ($parts as $seg) {
            if ($seg === '.') {
                continue;
            }
            if ($seg === '..') {
                array_pop($stack);
            } else {
                $stack[] = $seg;
            }
        }
        $res = implode('/', $stack);
        if ($isAbs) {
            $res = '/' . $res;
        }
        return new self($res === '' ? ($isAbs ? '/' : '.') : $res);
    }

    /**
     * Return actual filesystem path (string), applying fake base if active.
     */
    private function actual(): string
    {
        $logical = $this->path;

        if (self::$autoExpandTilde && isset($logical[0]) && $logical[0] === '~') {
            $home = self::homeDir();
            if ($home) {
                $logical = $home . substr($logical, 1);
            }
        }

        if (self::$base === null) {
            return $this->normalizeSeparators($logical);
        }

        $relative = ltrim($this->normalizeSeparators($logical), '/\\');
        return rtrim(self::$base, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $relative;
    }

    /**
     * Normalize path separators to forward slashes for logical paths.
     */
    private static function normalizeSlashes(string $path): string
    {
        return str_replace('\\', '/', $path);
    }

    /**
     * Normalize separators to the current OS separator.
     */
    private function normalizeSeparators(string $path): string
    {
        return str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
    }

    /**
     * Recursively remove a directory.
     */
    private static function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $file) {
            /** @var \SplFileInfo $file */
            if ($file->isDir()) {
                rmdir($file->getPathname());
            } else {
                @chmod($file->getPathname(), 0666); // best effort
                unlink($file->getPathname());
            }
        }
        rmdir($dir);
    }
}

/**
 * Small guard object for scoped fake filesystem.
 * Keep it public so test code can type-hint if desired.
 */
final class ScopedFake
{
    private string $base;
    /** @var callable():void */
    private $onClose;

    /**
     * @param callable():void $onClose
     */
    public function __construct(string $base, callable $onClose)
    {
        $this->base = $base;
        $this->onClose = $onClose;
    }

    public function base(): string
    {
        return $this->base;
    }

    public function __destruct()
    {
        // Ensure cleanup even if the test forgets to call Path::unfake()
        ($this->onClose)();
    }
}
