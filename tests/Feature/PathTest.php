<?php


use Ohffs\PhpPathlib\Path;

beforeEach(function () {
    $this->fake = Path::fake();
});

afterEach(function () {
    $this->fake->cleanup();
});

// Test path creation and basic properties
test('creates path objects correctly', function () {
    $path = Path::of('/home/user/document.txt');

    expect((string) $path)->toBe('/home/user/document.txt');
    expect($path->name())->toBe('document.txt');
    expect($path->stem())->toBe('document');
    expect($path->suffix())->toBe('.txt');
});

test('handles home directory expansion', function () {
    $home = $_SERVER['HOME'] ?? $_SERVER['USERPROFILE'] ?? '/home/user';
    $path = Path::of('~/documents/file.txt');

    expect((string) $path)->toStartWith($home);
    expect($path->name())->toBe('file.txt');
});

test('resolves relative paths', function () {
    $path = Path::of('./test/../config/app.json');

    expect($path->name())->toBe('app.json');
    expect((string) $path)->not()->toContain('..');
});

test('joins path components', function () {
    $base = Path::of('/var/www');
    $full = $base->joinpath('html', 'assets', 'style.css');

    expect($full->name())->toBe('style.css');
    expect((string) $full)->toBe('/var/www/html/assets/style.css');
});

test('gets parent directory', function () {
    $path = Path::of('/home/user/documents/file.txt');
    $parent = $path->parent();

    expect((string) $parent)->toBe('/home/user/documents');
});

test('changes file suffix', function () {
    $original = Path::of('/path/to/file.txt');
    $changed = $original->withSuffix('.md');

    expect($changed->name())->toBe('file.md');
    expect($changed->suffix())->toBe('.md');
});

test('changes filename', function () {
    $original = Path::of('/path/to/old_name.txt');
    $changed = $original->withName('new_name.txt');

    expect($changed->name())->toBe('new_name.txt');
    expect((string) $changed->parent())->toBe('/path/to');
});

// File system tests using fake filesystem
test('detects file existence', function () {
    $path = Path::of('/app/test.txt');

    // File doesn't exist initially
    expect($path->exists())->toBeFalse();

    // Create the file
    $path->writeText('test content');

    expect($path->exists())->toBeTrue();
    expect($path->isFile())->toBeTrue();
    expect($path->isDir())->toBeFalse();
});

test('detects directory existence', function () {
    $path = Path::of('/app/testdir');

    expect($path->exists())->toBeFalse();

    $path->mkdir();

    expect($path->exists())->toBeTrue();
    expect($path->isDir())->toBeTrue();
    expect($path->isFile())->toBeFalse();
});

test('reads and writes text files', function () {
    $path = Path::of('/app/config.txt');

    $path->writeText('original content');
    expect($path->readText())->toBe('original content');

    $path->writeText('new content');
    expect($path->readText())->toBe('new content');
});

test('creates directories with parents', function () {
    $path = Path::of('/app/deep/nested/directory');

    expect($path->exists())->toBeFalse();

    $path->mkdir();

    expect($path->exists())->toBeTrue();
    expect($path->isDir())->toBeTrue();

    // Parent directories should also exist
    expect($path->parent()->exists())->toBeTrue();
    expect(Path::of('/app/deep')->exists())->toBeTrue();
});

test('iterates directory contents', function () {
    $dir = Path::of('/app/testdir');
    $dir->mkdir();

    // Create some files
    Path::of('/app/testdir/file1.txt')->writeText('content1');
    Path::of('/app/testdir/file2.txt')->writeText('content2');
    Path::of('/app/testdir/subdir')->mkdir();

    $contents = iterator_to_array($dir->iterdir());

    expect($contents)->toHaveCount(3);

    $names = array_map(fn($p) => $p->name(), $contents);
    expect($names)->toContain('file1.txt');
    expect($names)->toContain('file2.txt');
    expect($names)->toContain('subdir');
});

test('finds files with glob patterns', function () {
    $dir = Path::of('/app/files');
    $dir->mkdir();

    // Create test files
    Path::of('/app/files/test1.txt')->writeText('content');
    Path::of('/app/files/test2.txt')->writeText('content');
    Path::of('/app/files/other.log')->writeText('content');
    Path::of('/app/files/readme.md')->writeText('content');

    $txtFiles = $dir->glob('*.txt');

    expect($txtFiles)->toHaveCount(2);

    $names = array_map(fn($p) => $p->name(), $txtFiles);
    expect($names)->toContain('test1.txt');
    expect($names)->toContain('test2.txt');
    expect($names)->not()->toContain('other.log');
    expect($names)->not()->toContain('readme.md');
});

test('handles mixed path separators', function () {
    $path = Path::of('app\\config/database\\settings.json');

    // Should normalize to system separator and work with fake filesystem
    expect($path->name())->toBe('settings.json');

    // Create the file to test it works
    $path->parent()->mkdir();
    $path->writeText('{"driver": "mysql"}');

    expect($path->exists())->toBeTrue();
    expect($path->readText())->toBe('{"driver": "mysql"}');
});

test('gets path parts', function () {
    $path = Path::of('/home/user/documents/file.txt');
    $parts = $path->parts();

    expect($parts)->toBe(['home', 'user', 'documents', 'file.txt']);
});

test('resolves paths correctly', function () {
    // Create a file to resolve to
    $file = Path::of('/app/config/settings.json');
    $file->parent()->mkdir();
    $file->writeText('{}');

    $relative = Path::of('/app/temp/../config/./settings.json');
    $resolved = $relative->resolve();

    expect($resolved->exists())->toBeTrue();
    expect($resolved->name())->toBe('settings.json');
});

test('fake filesystem is isolated between tests', function () {
    // This test should not see files from previous tests
    $file = Path::of('/app/isolated_test.txt');

    expect($file->exists())->toBeFalse();

    $file->writeText('isolated content');
    expect($file->exists())->toBeTrue();
});
