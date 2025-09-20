<?php

pest()->extend(Tests\TestCase::class)->in('Feature');

function createTempFile(string $content = 'test content'): string
{
    $tempFile = tempnam(sys_get_temp_dir(), 'pathlib_test_');
    file_put_contents($tempFile, $content);
    return $tempFile;
}

function createTempDir(): string
{
    $tempDir = sys_get_temp_dir() . '/pathlib_test_' . uniqid();
    mkdir($tempDir, 0755, true);
    return $tempDir;
}

function cleanupTemp(string $path): void
{
    if (is_file($path)) {
        unlink($path);
    } elseif (is_dir($path)) {
        array_map('unlink', glob("$path/*"));
        rmdir($path);
    }
}
