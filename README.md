# PHP PathLib

A simple, Python pathlib-inspired Path class for PHP that makes working with file paths much more pleasant.

## Why?

Working with file paths in PHP can be frustrating - dealing with directory separators, expanding `~/` paths, resolving `../` components, and PHP's native `SplFileInfo` doesn't help much. This library brings the basic parts of Python's pathlib to PHP.

## Installation

```bash
composer require ohffs/php-pathlib
```

## Quick Start

```php
use Ohffs\PhpPathlib\Path;

// Create paths naturally
$config = Path::of('~/.config/myapp');
$logFile = Path::of('../logs/app.log');

// Chain operations fluently  
$settings = Path::of('~/projects')
    ->joinpath('myapp', 'config')
    ->joinpath('settings.json');

// File operations are simple
if ($settings->exists()) {
    $data = json_decode($settings->readText(), true);
}
```

## Features

### Path Creation & Manipulation

```php
// All of these work seamlessly
$home = Path::of('~/documents');
$relative = Path::of('../config/app.json');
$mixed = Path::of('~/projects\\myapp/src/../settings');

// Join path components
$path = Path::of('/var/www')->joinpath('html', 'assets', 'images');

// Get path properties
echo $path->name();     // "images"
echo $path->parent();   // "/var/www/html/assets"
echo $path->suffix();   // "" (no extension)

$file = Path::of('~/documents/report.pdf');
echo $file->name();     // "report.pdf"
echo $file->stem();     // "report"
echo $file->suffix();   // ".pdf"
```

### File Operations

```php
$file = Path::of('~/data/config.json');

// Check file status
if ($file->exists() && $file->isFile()) {
    $content = $file->readText();
}

// Write files
$file->writeText(json_encode(['key' => 'value']));

// Work with directories
$dir = Path::of('~/projects/new-app');
$dir->mkdir();  // Creates directory (with parents by default)

if ($dir->isDir()) {
    foreach ($dir->iterdir() as $item) {
        echo "Found: " . $item->name() . "\n";
    }
}
```

### Path Transformations

```php
$original = Path::of('~/documents/report.txt');

// Change file extension
$markdown = $original->withSuffix('.md');

// Change filename entirely
$backup = $original->withName('report_backup.txt');

// Get absolute path
$resolved = Path::of('../config')->resolve();

// Find relative path between two locations
$from = Path::of('~/projects/app1');
$to = Path::of('~/projects/app2/config');
$relative = $from->relative($to);  // "../app2/config"
```

### Pattern Matching

```php
$projectDir = Path::of('~/projects/myapp');

// Find all PHP files
$phpFiles = $projectDir->glob('*.php');

// Find files in subdirectories
$allConfigs = $projectDir->glob('**/config/*.json');

foreach ($phpFiles as $file) {
    echo "PHP file: " . $file . "\n";
}
```

## Real-World Examples

### Configuration Management

```php
use Ohffs\PhpPathlib\Path;

class Config
{
    private Path $configDir;
    
    public function __construct()
    {
        $this->configDir = Path::of('~/.config/myapp');
        
        if (!$this->configDir->exists()) {
            $this->configDir->mkdir();
        }
    }
    
    public function load(string $name): array
    {
        $configFile = $this->configDir->joinpath($name . '.json');
        
        if (!$configFile->exists()) {
            return [];
        }
        
        return json_decode($configFile->readText(), true);
    }
    
    public function save(string $name, array $data): void
    {
        $configFile = $this->configDir->joinpath($name . '.json');
        $configFile->writeText(json_encode($data, JSON_PRETTY_PRINT));
    }
}
```

### Log File Management

```php
use Ohffs\PhpPathlib\Path;

class Logger
{
    private Path $logDir;
    
    public function __construct()
    {
        $this->logDir = Path::of('../logs');
        $this->logDir->mkdir();
    }
    
    public function log(string $level, string $message): void
    {
        $logFile = $this->logDir->joinpath(date('Y-m-d') . '.log');
        $timestamp = date('Y-m-d H:i:s');
        $entry = "[{$timestamp}] {$level}: {$message}\n";
        
        file_put_contents((string)$logFile, $entry, FILE_APPEND);
    }
    
    public function getRecentLogs(int $days = 7): array
    {
        $logs = [];
        
        foreach ($this->logDir->glob('*.log') as $logFile) {
            $date = $logFile->stem();
            if (strtotime($date) >= strtotime("-{$days} days")) {
                $logs[] = $logFile;
            }
        }
        
        return $logs;
    }
}
```

### Asset Processing

```php
use Ohffs\PhpPathlib\Path;

class AssetProcessor
{
    public function processImages(string $inputDir, string $outputDir): void
    {
        $input = Path::of($inputDir);
        $output = Path::of($outputDir);
        
        $output->mkdir();
        
        foreach ($input->glob('*.{jpg,jpeg,png}') as $image) {
            $processed = $output->joinpath($image->stem() . '_processed' . $image->suffix());
            
            // Process image (pseudo-code)
            $this->resizeImage((string)$image, (string)$processed);
            
            echo "Processed: {$image->name()} -> {$processed->name()}\n";
        }
    }
}
```

## API Reference

### Path Creation
- `Path::of(string $path)` - Create a new Path instance
- `new Path(string $path)` - Alternative constructor

### Path Properties
- `name()` - Final component (filename)
- `stem()` - Filename without extension
- `suffix()` - File extension (with dot)
- `parent()` - Parent directory
- `parts()` - Array of path components

### Path Operations
- `joinpath(...$parts)` - Join path components
- `withSuffix(string $suffix)` - Change file extension
- `withName(string $name)` - Change filename
- `resolve()` - Get absolute path
- `relative(Path $other)` - Get relative path

### File System
- `exists()` - Check if path exists
- `isFile()` - Check if path is a file
- `isDir()` - Check if path is a directory
- `readText()` - Read file contents as string
- `writeText(string $content)` - Write string to file
- `mkdir(int $mode = 0755, bool $parents = true)` - Create directory

### Directory Operations
- `iterdir()` - Iterate over directory contents (Generator)
- `glob(string $pattern)` - Find paths matching pattern

## Requirements

- PHP 7.4+
- No additional dependencies

## License

MIT License - see LICENSE file for details.

