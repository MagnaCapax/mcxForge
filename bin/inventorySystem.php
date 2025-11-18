#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * inventorySystem.php
 *
 * Collect system-level inventory for the current host. Reports:
 *  - Hostname and basic OS identification.
 *  - System/chassis identifiers from DMI (vendor, product name, serial, asset tag).
 *  - Motherboard details.
 *  - BIOS vendor, version, and release date.
 *
 * Supports human‑readable output (default), JSON, and PHP serialize formats.
 * This script is read‑only and intended for use on live systems to feed
 * qualification and diagnostics flows.
 *
 * @author Aleksi Ursin
 */

require_once __DIR__ . '/../lib/php/Logger.php';

\mcxForge\Logger::initStreamLogging();

if (!defined('EXIT_OK')) {
    define('EXIT_OK', 0);
}
if (!defined('EXIT_ERROR')) {
    define('EXIT_ERROR', 1);
}

function inventorySystemMain(array $argv): int
{
    [$format, $colorEnabled] = inventorySystemParseArguments($argv);

    $info = inventorySystemCollect();

    switch ($format) {
        case 'json':
            inventorySystemRenderJson($info);
            break;
        case 'php':
            inventorySystemRenderPhp($info);
            break;
        case 'human':
        default:
            inventorySystemRenderHuman($info, $colorEnabled);
            break;
    }

    return EXIT_OK;
}

/**
 * @return array{0:string,1:bool}
 */
function inventorySystemParseArguments(array $argv): array
{
    $format = 'human';
    $colorEnabled = true;

    if (getenv('NO_COLOR') !== false) {
        $colorEnabled = false;
    }

    $args = $argv;
    array_shift($args);

    foreach ($args as $arg) {
        if ($arg === '--help' || $arg === '-h') {
            inventorySystemPrintHelp();
            exit(EXIT_OK);
        }

        if (str_starts_with($arg, '--format=')) {
            $value = substr($arg, strlen('--format='));
            if (!in_array($value, ['human', 'json', 'php'], true)) {
                \mcxForge\Logger::logStderr("Error: unsupported format '$value'. Use human, json, or php.\n");
                exit(EXIT_ERROR);
            }
            $format = $value;
            continue;
        }

        if ($arg === '--no-color') {
            $colorEnabled = false;
            continue;
        }

        \mcxForge\Logger::logStderr("Error: unrecognized argument '$arg'. Use --help for usage.\n");
        exit(EXIT_ERROR);
    }

    return [$format, $colorEnabled];
}

/**
 * @return array<string,mixed>
 */
function inventorySystemCollect(): array
{
    $hostname = gethostname() ?: null;

    $os = inventorySystemReadOsRelease();
    $system = inventorySystemReadSystemDmi();
    $board = inventorySystemReadBoardDmi();
    $chassis = inventorySystemReadChassisDmi();
    $bios = inventorySystemReadBiosDmi();

    return [
        'hostname' => $hostname,
        'os' => $os,
        'system' => $system,
        'board' => $board,
        'chassis' => $chassis,
        'bios' => $bios,
    ];
}

/**
 * @return array<string,string|null>|null
 */
function inventorySystemReadOsRelease(): ?array
{
    $path = '/etc/os-release';
    if (!is_readable($path)) {
        return null;
    }

    $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!is_array($lines)) {
        return null;
    }

    $data = [];
    foreach ($lines as $line) {
        $trimmed = trim($line);
        if ($trimmed === '' || $trimmed[0] === '#') {
            continue;
        }
        if (strpos($trimmed, '=') === false) {
            continue;
        }
        [$key, $value] = explode('=', $trimmed, 2);
        $key = trim($key);
        $value = trim($value);
        $value = trim($value, "\"'");
        $data[$key] = $value;
    }

    return [
        'name' => $data['NAME'] ?? null,
        'id' => $data['ID'] ?? null,
        'versionId' => $data['VERSION_ID'] ?? null,
        'prettyName' => $data['PRETTY_NAME'] ?? null,
    ];
}

/**
 * @return array<string,string|null>
 */
function inventorySystemReadSystemDmi(): array
{
    return [
        'manufacturer' => inventorySystemReadDmiField('sys_vendor'),
        'productName' => inventorySystemReadDmiField('product_name'),
        'productVersion' => inventorySystemReadDmiField('product_version'),
        'productFamily' => inventorySystemReadDmiField('product_family'),
        'productSerial' => inventorySystemReadDmiField('product_serial'),
        'productUuid' => inventorySystemReadDmiField('product_uuid'),
    ];
}

/**
 * @return array<string,string|null>
 */
function inventorySystemReadBoardDmi(): array
{
    return [
        'manufacturer' => inventorySystemReadDmiField('board_vendor'),
        'productName' => inventorySystemReadDmiField('board_name'),
        'productVersion' => inventorySystemReadDmiField('board_version'),
        'productSerial' => inventorySystemReadDmiField('board_serial'),
        'assetTag' => inventorySystemReadDmiField('board_asset_tag'),
    ];
}

/**
 * @return array<string,string|null>
 */
function inventorySystemReadChassisDmi(): array
{
    return [
        'manufacturer' => inventorySystemReadDmiField('chassis_vendor'),
        'type' => inventorySystemReadDmiField('chassis_type'),
        'serial' => inventorySystemReadDmiField('chassis_serial'),
        'assetTag' => inventorySystemReadDmiField('chassis_asset_tag'),
    ];
}

/**
 * @return array<string,string|null>
 */
function inventorySystemReadBiosDmi(): array
{
    return [
        'vendor' => inventorySystemReadDmiField('bios_vendor'),
        'version' => inventorySystemReadDmiField('bios_version'),
        'date' => inventorySystemReadDmiField('bios_date'),
    ];
}

/**
 * @return string|null
 */
function inventorySystemReadDmiField(string $field): ?string
{
    $path = '/sys/class/dmi/id/' . $field;
    if (!is_readable($path)) {
        return null;
    }

    $value = @file_get_contents($path);
    if ($value === false) {
        return null;
    }

    $trimmed = trim($value);
    if ($trimmed === '' || $trimmed === 'None' || $trimmed === 'To be filled by O.E.M.') {
        return null;
    }

    return $trimmed;
}

/**
 * @param array<string,mixed> $info
 */
function inventorySystemRenderHuman(array $info, bool $colorEnabled): void
{
    $sectionColor = $colorEnabled ? "\033[1;34m" : '';
    $labelColor = $colorEnabled ? "\033[0;36m" : '';
    $valueColor = $colorEnabled ? "\033[0;37m" : '';
    $resetColor = $colorEnabled ? "\033[0m" : '';

    echo $sectionColor . "System" . $resetColor . PHP_EOL;

    $hostname = $info['hostname'] ?? null;
    if ($hostname !== null) {
        echo sprintf(
            "  %sHostname:%s %s%s%s\n",
            $labelColor,
            $resetColor,
            $valueColor,
            $hostname,
            $resetColor
        );
    }

    /** @var array<string,string|null>|null $os */
    $os = $info['os'] ?? null;
    if ($os !== null) {
        echo sprintf(
            "  %sOS:%s %s%s%s\n",
            $labelColor,
            $resetColor,
            $valueColor,
            $os['prettyName'] ?? ($os['name'] ?? 'unknown'),
            $resetColor
        );
    }

    echo PHP_EOL;
    echo $sectionColor . "Chassis" . $resetColor . PHP_EOL;
    /** @var array<string,string|null> $chassis */
    $chassis = $info['chassis'] ?? [];
    echo sprintf(
        "  %sVendor:%s %s%s%s\n",
        $labelColor,
        $resetColor,
        $valueColor,
        $chassis['manufacturer'] ?? 'unknown',
        $resetColor
    );
    echo sprintf(
        "  %sType:%s %s%s%s\n",
        $labelColor,
        $resetColor,
        $valueColor,
        $chassis['type'] ?? 'unknown',
        $resetColor
    );
    echo sprintf(
        "  %sSerial:%s %s%s%s\n",
        $labelColor,
        $resetColor,
        $valueColor,
        $chassis['serial'] ?? 'unknown',
        $resetColor
    );
    echo sprintf(
        "  %sAsset tag:%s %s%s%s\n",
        $labelColor,
        $resetColor,
        $valueColor,
        $chassis['assetTag'] ?? 'unknown',
        $resetColor
    );

    echo PHP_EOL;
    echo $sectionColor . "System board" . $resetColor . PHP_EOL;
    /** @var array<string,string|null> $board */
    $board = $info['board'] ?? [];
    echo sprintf(
        "  %sVendor:%s %s%s%s\n",
        $labelColor,
        $resetColor,
        $valueColor,
        $board['manufacturer'] ?? 'unknown',
        $resetColor
    );
    echo sprintf(
        "  %sProduct:%s %s%s%s\n",
        $labelColor,
        $resetColor,
        $valueColor,
        $board['productName'] ?? 'unknown',
        $resetColor
    );
    echo sprintf(
        "  %sVersion:%s %s%s%s\n",
        $labelColor,
        $resetColor,
        $valueColor,
        $board['productVersion'] ?? 'unknown',
        $resetColor
    );
    echo sprintf(
        "  %sSerial:%s %s%s%s\n",
        $labelColor,
        $resetColor,
        $valueColor,
        $board['productSerial'] ?? 'unknown',
        $resetColor
    );

    echo PHP_EOL;
    echo $sectionColor . "BIOS" . $resetColor . PHP_EOL;
    /** @var array<string,string|null> $bios */
    $bios = $info['bios'] ?? [];
    echo sprintf(
        "  %sVendor:%s %s%s%s\n",
        $labelColor,
        $resetColor,
        $valueColor,
        $bios['vendor'] ?? 'unknown',
        $resetColor
    );
    echo sprintf(
        "  %sVersion:%s %s%s%s\n",
        $labelColor,
        $resetColor,
        $valueColor,
        $bios['version'] ?? 'unknown',
        $resetColor
    );
    echo sprintf(
        "  %sRelease date:%s %s%s%s\n",
        $labelColor,
        $resetColor,
        $valueColor,
        $bios['date'] ?? 'unknown',
        $resetColor
    );
}

/**
 * @param array<string,mixed> $info
 */
function inventorySystemRenderJson(array $info): void
{
    $encoded = json_encode($info, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($encoded === false) {
        \mcxForge\Logger::logStderr("Error: failed to encode JSON output.\n");
        exit(EXIT_ERROR);
    }

    echo $encoded . PHP_EOL;
}

/**
 * @param array<string,mixed> $info
 */
function inventorySystemRenderPhp(array $info): void
{
    echo serialize($info) . PHP_EOL;
}

function inventorySystemPrintHelp(): void
{
    $help = <<<TEXT
Usage: inventorySystem.php [--format=human|json|php] [--no-color]

Collect system-level inventory (hostname, OS, chassis, board, BIOS) for the current host.

Options:
  --format=human    Human-readable output (default).
  --format=json     JSON output with system, board, chassis, BIOS, and OS details.
  --format=php      PHP serialize() output of the same structure.
  --no-color        Disable ANSI colors in human output.
  -h, --help        Show this help message.

Notes:
  - Hardware identifiers are read from /sys/class/dmi/id where available.
  - OS identification is read from /etc/os-release when present.

TEXT;

    echo $help;
}

if (PHP_SAPI === 'cli' && isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    exit(inventorySystemMain($argv));
}

