# Streamed ZIP Archive

A PHP Composer package to create a ZIP in memory, using an external binary.

It only requires [Symfony's Process Component](https://symfony.com/doc/current/components/process.html), and a few binary utilities
- `zip`: a version recent enough to support the `-FI` option
- `mkfifo`: should be always available with unix systems
- `tee`: should be always available with unix systems
- `realpath`: GNU's coreutils version (not by default with Alpine !)

You can use this lib if you have for example :
- a streamed input, or a raw string
- only need to create a temporary zip with will be downloaded on the fly
- high storage constraint (low space, or the need to with nothing on the hard drive)

## Installation

Install `zip` on your OS.

```shell script
composer require cleverage/streamed-zip-archive
```

For Alpine users, you might want to install `coreutils`.

## Usage

```php
// Open some files, or fetch some data from an URL, a database, Flysystem ...
$pdf = fopen('file.pdf', 'r');
$data = '...';

$zipArchive = new \CleverAge\StreamedZipArchive\StreamedZipArchive();

// Register all content
$zipArchive->addStream('file.pdf', $pdf);
$zipArchive->addStream('subfolder/data.txt', $data);

// Build the ZIP and handle it as you want (HTTP response, write in a file, etc...)
$zipContent = $zipArchive->buildArchive();
```

If the `zip` binary is not called `zip` on your system, you should be able to extend and replace the `ZIP_BINARY` constant.
