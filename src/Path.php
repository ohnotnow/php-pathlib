<?php

namespace Ohffs\PhpPathlib;

class Path
{
    private string $path;
    private static ?string $fakeRoot = null;

    public function __construct(string $path = '')
    {
        $this->path = $this->normalize($path ?: getcwd());
    }

    public function __toString(): string
    {
        return $this->path;
    }

    // Static constructor for fluent API
    public static function of(string $path): self
    {
        return new self($path);
    }

    // Testing helpers
    public static function fake(): FakePath
    {
        return new FakePath();
    }

    public static function stopFaking(): void
    {
        self::$fakeRoot = null;
    }

    public static function setFakeRoot(string $root): void
    {
        self::$fakeRoot = $root;
    }

    protected function getActualPath(): string
    {
        if (self::$fakeRoot && !str_starts_with($this->path, '/tmp/')) {
            // Prefix non-temp paths with fake root
            return self::$fakeRoot . '/' . ltrim($this->path, '/');
        }
        return $this->path;
    }

    // Join path components
    public function joinpath(...$parts): self
    {
        $allParts = array_merge([$this->path], $parts);
        $joined = implode(DIRECTORY_SEPARATOR, $allParts);
        return new self($joined);
    }

    // Path properties
    public function name(): string
    {
        return basename($this->path);
    }

    public function stem(): string
    {
        return pathinfo($this->path, PATHINFO_FILENAME);
    }

    public function suffix(): string
    {
        $ext = pathinfo($this->path, PATHINFO_EXTENSION);
        return $ext ? '.' . $ext : '';
    }

    public function parent(): self
    {
        return new self(dirname($this->path));
    }

    public function parts(): array
    {
        return array_filter(explode(DIRECTORY_SEPARATOR, $this->path));
    }

    // File operations
    public function exists(): bool
    {
        return file_exists($this->getActualPath());
    }

    public function isFile(): bool
    {
        return is_file($this->getActualPath());
    }

    public function isDir(): bool
    {
        return is_dir($this->getActualPath());
    }

    public function readText(): string
    {
        $actualPath = $this->getActualPath();
        if (!is_file($actualPath)) {
            throw new InvalidArgumentException("Path is not a file: {$this->path}");
        }
        return file_get_contents($actualPath);
    }

    public function writeText(string $content): void
    {
        file_put_contents($this->getActualPath(), $content);
    }

    public function mkdir(int $mode = 0755, bool $parents = true): void
    {
        $actualPath = $this->getActualPath();
        if (!file_exists($actualPath)) {
            mkdir($actualPath, $mode, $parents);
        }
    }

    // Iterator for directories
    public function iterdir(): \Generator
    {
        $actualPath = $this->getActualPath();
        if (!is_dir($actualPath)) {
            throw new InvalidArgumentException("Path is not a directory: {$this->path}");
        }

        foreach (scandir($actualPath) as $item) {
            if ($item !== '.' && $item !== '..') {
                yield new self($this->path . DIRECTORY_SEPARATOR . $item);
            }
        }
    }

    // Glob pattern matching
    public function glob(string $pattern): array
    {
        $fullPattern = $this->joinpath($pattern);
        $actualPattern = str_replace($this->path, $this->getActualPath(), (string)$fullPattern);
        $results = glob($actualPattern);

        return array_map(function($actualPath) {
            $virtualPath = str_replace($this->getActualPath(), $this->path, $actualPath);
            return new self($virtualPath);
        }, $results ?: []);
    }

    // Path resolution
    public function resolve(): self
    {
        return new self(realpath($this->getActualPath()) ?: $this->path);
    }

    public function relative(self $other): self
    {
        $from = $this->resolve()->parts();
        $to = $other->resolve()->parts();

        // Find common prefix
        $common = 0;
        while ($common < min(count($from), count($to)) && $from[$common] === $to[$common]) {
            $common++;
        }

        // Build relative path
        $ups = str_repeat('..' . DIRECTORY_SEPARATOR, count($from) - $common);
        $downs = implode(DIRECTORY_SEPARATOR, array_slice($to, $common));

        return new self(rtrim($ups . $downs, DIRECTORY_SEPARATOR) ?: '.');
    }

    // Utility methods
    public function withSuffix(string $suffix): self
    {
        $stem = $this->stem();
        $parent = $this->parent();
        return $parent->joinpath($stem . $suffix);
    }

    public function withName(string $name): self
    {
        return $this->parent()->joinpath($name);
    }

    private function normalize(string $path): string
    {
        // Handle empty path
        if (empty($path)) {
            return getcwd();
        }

        // Expand ~ to home directory
        if ($path[0] === '~') {
            $home = $_SERVER['HOME'] ?? $_SERVER['USERPROFILE'] ?? posix_getpwuid(posix_getuid())['dir'] ?? '/';
            $path = $home . substr($path, 1);
        }

        // Convert all slashes to system separator
        $path = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);

        // Split path into parts for processing
        $isAbsolute = $path[0] === DIRECTORY_SEPARATOR;
        $parts = array_filter(explode(DIRECTORY_SEPARATOR, $path), fn($part) => $part !== '');

        // Resolve . and .. components
        $resolved = [];
        foreach ($parts as $part) {
            if ($part === '.') {
                continue; // Skip current directory references
            } elseif ($part === '..') {
                if (!empty($resolved) && end($resolved) !== '..') {
                    array_pop($resolved); // Go up one level
                } elseif (!$isAbsolute) {
                    $resolved[] = '..'; // Keep .. for relative paths
                }
                // For absolute paths, ignore .. at root
            } else {
                $resolved[] = $part;
            }
        }

        // Rebuild path
        $normalizedPath = implode(DIRECTORY_SEPARATOR, $resolved);

        if ($isAbsolute) {
            $normalizedPath = DIRECTORY_SEPARATOR . $normalizedPath;
        } elseif (empty($normalizedPath)) {
            $normalizedPath = '.';
        }

        return $normalizedPath;
    }
}

class FakePath
{
    private string $tempDir;

    public function __construct()
    {
        $this->tempDir = sys_get_temp_dir() . '/pathlib_fake_' . uniqid();
        mkdir($this->tempDir, 0755, true);
        Path::setFakeRoot($this->tempDir);
    }

    public function of(string $path): Path
    {
        return Path::of($path);
    }

    public function cleanup(): void
    {
        $this->removeDirectory($this->tempDir);
        Path::stopFaking();
    }

    public function getTempDir(): string
    {
        return $this->tempDir;
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) return;

        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . DIRECTORY_SEPARATOR . $file;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }
        rmdir($dir);
    }
}
