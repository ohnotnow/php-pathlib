# PHP PathLib

*A tiny, Python `pathlib`‑flavoured `Path` for PHP that makes path handling and small file ops pleasant and testable.*

## Installation

```bash
composer require ohffs/php-pathlib
```

## TL;DR — why this over raw PHP?

**Before** (string wrangling + globals):

```php
$base = rtrim(getenv('HOME'), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'myapp';
$cfg  = $base . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'settings.json';
@mkdir(dirname($cfg), 0777, true);
file_put_contents($cfg, json_encode(['debug' => true]));
```

**After** (fluent, intention‑revealing):

```php
use Ohffs\PhpPathlib\Path;

$cfg = Path::of('~/myapp')
    ->joinpath('config', 'settings.json');

$cfg->writeText(json_encode(['debug' => true]));
```

You get consistent separators, `~/` expansion, easy joins, and readable code you can test safely.

---

## Quick start

```php
use Ohffs\PhpPathlib\Path;

// Construct & chain
$project  = Path::of('~/projects/example');
$config   = $project->joinpath('config', 'app.json');
$readme   = $project->joinpath('README.md');

// Queries
$config->exists();  // bool
$config->isFile();  // bool
$project->isDir();  // bool

// Name parts
$readme->name();    // "README.md"
$readme->stem();    // "README"
$readme->suffix();  // "md" (no leading dot)
$project->parent(); // Path("~/projects")
$project->parts();  // ["~", "projects", "example"] after expanduser() you get absolute parts

// IO
$config->writeText('{"debug":true}');
$text = $config->readText();

// Transformations
$logDir  = Path::of('/var/log')->resolve();
$logFile = $logDir->joinpath('app.log').""; // castable to string
$tmpMd   = Path::of('report.txt')->withSuffix('md'); // report.md

// Globbing (non‑recursive)
foreach (Path::of('src')->glob('*.php') as $php) {
    echo $php->name(), "\n";
}
```

---

## Fake filesystem for tests

Sandbox all file ops under a disposable temp directory. Absolute logical paths (like `/etc/hosts`) are **mapped under the fake base**, never touching your real FS.

**Pest example**

```php
use Ohffs\PhpPathlib\Path;

beforeEach(function () {
    $this->fs = Path::fake();       // returns a guard; keep the ref alive
});

afterEach(function () {
    unset($this->fs);               // or Path::unfake()
});

it('writes without touching the real FS', function () {
    $p = Path::of('/app/data.txt');
    $p->writeText('ok');

    expect($p->exists())->toBeTrue();            // logical
    expect(is_file($this->fs->base().'/app/data.txt'))->toBeTrue(); // actual temp
});
```

**Notes**

* `Path::fake()` returns a `ScopedFake` with `base(): string` and cleans up on `__destruct()`.
* `__toString()` always shows the *logical* path; the temp base is applied only when touching the real filesystem.

---

## Common recipes

**Ensure a directory exists and write a file**

```php
$dir = Path::of('~/cache/images');
$dir->mkdir(true);                  // parents=true
$dir->joinpath('index.json')->writeText('[]');
```

**List items in a folder**

```php
$names = array_map(fn($p) => $p->name(), Path::of('logs')->iterdir());
```

**Change extension safely (dotfiles supported)**

```php
Path::of('/a/archive.tar.gz')->withSuffix('zip'); // /a/archive.tar.zip
Path::of('/a/.env')->withSuffix('bak');           // /a/.env.bak
```

**Normalize a messy path without requiring it to exist**

```php
Path::of('/x/y/../z/./a')->resolve();  // /x/z/a
```

---

## Behaviour details

* **Separators**: accepts `/` and `\\` in inputs; outputs use your OS separator for real FS calls. Logical string helpers normalise sensibly.
* **Dotfiles**: `.env`/`.gitignore` are treated as *no extension*; `suffix()` returns `''`, `stem()` returns the whole name.
* **`glob()`**: non‑recursive; uses PHP’s `glob()` under the hood; returns `Path[]` with *logical* paths (no temp prefixes).
* **`resolve()`**: if the actual path exists, uses `realpath()`; otherwise performs a pure string normalisation (`.`/`..`).
* **Safety**: in fake mode, even logical absolutes are sandboxed under the temp base.

---

## API reference (essentials)

### Construction

* `Path::of(string $path): Path`

### Query

* `exists(): bool`
* `isFile(): bool`
* `isDir(): bool`
* `isAbsolute(): bool`
* `name(): string`
* `stem(): string`
* `suffix(): string` *(no leading dot)*
* `parent(): Path`
* `parts(): string[]`

### Transform

* `joinpath(string ...$parts): Path`
* `withSuffix(string $suffix): Path`
* `withName(string $name): Path`
* `expanduser(): Path`
* `resolve(): Path`

### Filesystem ops

* `readText(): string`
* `writeText(string $content): void`
* `mkdir(bool $parents = false, int $mode = 0777): void`
* `iterdir(): Path[]`
* `glob(?string $pattern = null): Path[]`

### Testing helpers

* `Path::fake(): ScopedFake` *(returns guard with `base()`; auto‑cleanup on destruct)*
* `Path::unfake(): void`

---

## Requirements

* PHP **7.4+** (typed properties & arrow functions)
* No runtime dependencies

## License

MIT — see `LICENSE`.

