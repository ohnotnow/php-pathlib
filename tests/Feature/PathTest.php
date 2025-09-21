<?php

use Ohffs\PhpPathlib\Path;

beforeEach(function () {
    $this->fs = \Ohffs\PhpPathlib\Path::fake();
});

afterEach(function () {
    unset($this->fs);
});

test('creates path objects correctly', function () {
    $path = Path::of('/home/user/documents');

    expect($path)->toBeInstanceOf(Path::class);
    expect((string)$path)->toBe('/home/user/documents');
});

test('handles home directory expansion', function () {
    $path = Path::of('~/documents');
    $expanded = $path->expanduser();

    expect((string)$expanded)->not->toStartWith('~');
    expect((string)$expanded)->toContain('documents');
});

test('expanduser preserves remainder and only expands when needed', function () {
    $p = Path::of('~/documents');
    $e = $p->expanduser();
    expect((string)$e)->toEndWith('/documents');

    // no-tilde input should be returned as-is (same instance allowed)
    $q = Path::of('/var/log')->expanduser();
    expect((string)$q)->toBe('/var/log');
});

test('resolves relative paths', function () {
    $path = Path::of('./config/../data/file.txt');
    $resolved = $path->resolve();

    expect((string)$resolved)->toContain('data/file.txt');
    expect((string)$resolved)->not->toContain('..');
});

test('joins path components', function () {
    $base = Path::of('/home/user');
    $joined = $base->joinpath('documents', 'file.txt');

    expect((string)$joined)->toMatch('#/home/user[/\\\\]documents[/\\\\]file\.txt#');
});

test('gets parent directory', function () {
    $path = Path::of('/home/user/documents/file.txt');
    $parent = $path->parent();

    expect((string)$parent)->toBe('/home/user/documents');
});

test('changes file suffix', function () {
    $path = Path::of('/home/user/document.txt');

    $newPath = $path->withSuffix('md');
    expect((string)$newPath)->toMatch('#document\.md$#');

    $noSuffix = $path->withSuffix('');
    expect((string)$noSuffix)->toMatch('#document$#');
});

test('changes filename', function () {
    $path = Path::of('/home/user/document.txt');
    $newPath = $path->withName('report.pdf');

    expect((string)$newPath)->toMatch('#report\.pdf$#');
});

test('detects file existence', function () {
    $path = Path::of('/test_file.txt');

    expect($path->exists())->toBeFalse();

    // Create the file
    $path->writeText('test content');

    expect($path->exists())->toBeTrue();
    expect($path->isFile())->toBeTrue();
    expect($path->isDir())->toBeFalse();
});

test('detects directory existence', function () {
    $path = Path::of('/')->joinpath('home');
    $path->mkdir(parents: true);

    expect($path->isDir())->toBeTrue();
    expect($path->isFile())->toBeFalse();
});

test('reads and writes text files', function () {
    $path = Path::of('/test_write.txt');

    $content = 'This is test content';
    $path->writeText($content);

    expect($path->exists())->toBeTrue();
    expect($path->readText())->toBe($content);
});

test('creates directories with parents', function () {
    $path = Path::of('/test_dir/sub_dir/deep_dir');

    expect($path->exists())->toBeFalse();

    $path->mkdir(parents: true);

    expect($path->exists())->toBeTrue();
    expect($path->isDir())->toBeTrue();
});

test('iterates directory contents', function () {
    $dir = Path::of('/test_iteration_dir');
    $dir->mkdir();

    // Create some test files
    $dir->joinpath('file1.txt')->writeText('content1');
    $dir->joinpath('file2.txt')->writeText('content2');
    $dir->joinpath('subdir')->mkdir();

    $contents = $dir->iterdir();
    $names = array_map(fn($p) => $p->name(), $contents);

    expect($names)->toContain('file1.txt');
    expect($names)->toContain('file2.txt');
    expect($names)->toContain('subdir');
});

test('finds files with glob patterns', function () {
    $dir = Path::of('/test_glob_dir');
    $dir->mkdir();

    $dir->joinpath('file1.txt')->writeText('content');
    $dir->joinpath('file2.txt')->writeText('content');
    $dir->joinpath('file.md')->writeText('content');

    $txtFiles = $dir->glob('*.txt');

    expect($txtFiles)->toHaveCount(2);
});

test('handles mixed path separators', function () {
    $path = Path::of('C:\\Users\\name\\documents/file.txt');
    $parent = $path->parent();

    expect($parent->name())->toBe('documents');
});

test('gets path parts', function () {
    $path = Path::of('/home/user/documents/file.txt');
    $parts = $path->parts();

    expect($parts)->toBe(['home', 'user', 'documents', 'file.txt']);
});

test('resolves paths correctly', function () {
    // Create a file to resolve to
    $file = Path::of('/app/config/settings.json');
    $file->writeText('test settings');

    $relative = Path::of('/app/temp/../config/./settings.json');
    $resolved = $relative->resolve();

    expect($resolved->exists())->toBeTrue();
    expect($resolved->name())->toBe('settings.json');
});

test('fake filesystem is isolated between tests', function () {
    // This should not exist from previous tests
    $file = Path::of('/isolated_test.txt');

    expect($file->exists())->toBeFalse();

    $file->writeText('isolated content');
    expect($file->exists())->toBeTrue();
});

test('fake maps absolute under base, not real FS', function () {
    $base = $this->fs->base();

    // Use something that definitely doesn't exist on the real machine
    $p = Path::of('/__pathlib_sentinel__/file.txt');
    $p->writeText('not real');

    // Assert it's inside the fake base
    expect(is_file($base . '/__pathlib_sentinel__/file.txt'))->toBeTrue();
    expect($p->exists())->toBeTrue();
});

test('readText throws on non-existent and on directory', function () {
    $file = Path::of('/nope.txt');
    expect(fn() => $file->readText())->toThrow(InvalidArgumentException::class);

    $dir = Path::of('/dir'); $dir->mkdir();
    expect(fn() => $dir->readText())->toThrow(InvalidArgumentException::class);
});

test('withSuffix handles dotfiles and multi-dot names', function () {
    expect((string)Path::of('/a/.env')->withSuffix('bak'))->toEndWith('/.env.bak');
    expect((string)Path::of('/a/archive.tar.gz')->withSuffix('zip'))->toEndWith('/archive.tar.zip');
    expect((string)Path::of('/a/noext')->withSuffix(''))->toEndWith('/noext');
});

test('suffix and stem behave consistently', function () {
    $p = Path::of('/x/archive.tar.gz');
    expect($p->suffix())->toBe('gz');
    expect($p->stem())->toBe('archive.tar');

    $q = Path::of('/x/.gitignore');
    expect($q->suffix())->toBe('');      // no extension for dotfile
    expect($q->stem())->toBe('.gitignore');
});

test('joinpath tolerates leading and trailing separators', function () {
    $base = Path::of('/home/user/');
    $j1 = $base->joinpath('/docs/', '/file.txt');
    expect((string)$j1)->toMatch('#/home/user[/\\\\]docs[/\\\\]file\.txt$#');
});

test('parts handles root and trailing slash', function () {
    expect(Path::of('/')->parts())->toBe([]);
    expect(Path::of('/a/b/')->parts())->toBe(['a','b']);
});

test('parts handles Windows-esque inputs', function () {
    expect(Path::of('C:\\Users\\me\\file.txt')->parts())->toBe(['C:', 'Users', 'me', 'file.txt']);
    expect(Path::of('\\\\server\\share\\dir')->parts())->toBe(['server','share','dir']);
});

test('resolve normalizes non-existent logical paths', function () {
    $r = Path::of('/a/b/../c/./d')->resolve();
    expect((string)$r)->toBe('/a/c/d');
});

test('iterdir returns Path objects', function () {
    $d = Path::of('/list'); $d->mkdir();
    $d->joinpath('f')->writeText('x');
    $items = $d->iterdir();

    foreach ($items as $p) {
        expect($p)->toBeInstanceOf(Path::class);
    }
});

test('glob returns logical paths', function () {
    $dir = Path::of('/g'); $dir->mkdir();
    $dir->joinpath('a.txt')->writeText('x');
    $matches = $dir->glob('*.txt');
    expect(array_map(fn($p) => (string)$p, $matches))
        ->each->toStartWith('/g');  // no temp dir prefixes
});

test('name returns last segment for directories', function () {
    $dir = Path::of('/a/b/'); // trailing slash
    expect($dir->name())->toBe('b');
});
