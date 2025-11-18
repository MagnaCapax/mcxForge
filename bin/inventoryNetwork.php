#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * inventoryNetwork.php
 *
 * Collect network interface inventory for the current host. Reports:
 *  - Interface name, MAC, state, MTU, link type.
 *  - IP addresses per interface.
 *  - Speed, duplex, driver, firmware version, and bus info when ethtool is available.
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

function inventoryNetworkMain(array $argv): int
{
    [$format, $colorEnabled] = inventoryNetworkParseArguments($argv);

    $inventory = inventoryNetworkCollect();
    if ($inventory === null) {
        \mcxForge\Logger::logStderr("Error: failed to collect network inventory (ip command not available or output parse failed).\n");
        return EXIT_ERROR;
    }

    switch ($format) {
        case 'json':
            inventoryNetworkRenderJson($inventory);
            break;
        case 'php':
            inventoryNetworkRenderPhp($inventory);
            break;
        case 'human':
        default:
            inventoryNetworkRenderHuman($inventory, $colorEnabled);
            break;
    }

    return EXIT_OK;
}

/**
 * @return array{0:string,1:bool}
 */
function inventoryNetworkParseArguments(array $argv): array
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
            inventoryNetworkPrintHelp();
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
 * @return array<string,mixed>|null
 */
function inventoryNetworkCollect(): ?array
{
    $ipLink = @shell_exec('ip -o link show 2>/dev/null');
    if (!is_string($ipLink) || trim($ipLink) === '') {
        return null;
    }

    $lines = preg_split('/\R/', trim($ipLink));
    if (!is_array($lines)) {
        return null;
    }

    $interfaces = [];

    foreach ($lines as $line) {
        $trimmed = trim((string) $line);
        if ($trimmed === '') {
            continue;
        }

        if (!preg_match('/^\d+:\s+([^:]+):\s+<([^>]*)>.*mtu\s+(\d+).*state\s+(\S+)/', $trimmed, $m)) {
            continue;
        }

        $name = $m[1];
        $flagsRaw = $m[2];
        $mtu = (int) $m[3];
        $state = strtoupper($m[4]);

        $flags = $flagsRaw !== '' ? explode(',', $flagsRaw) : [];

        $linkType = null;
        $mac = null;

        if (preg_match('/link\/(\S+)\s+([0-9a-fA-F:]{17})/', $trimmed, $lm) === 1) {
            $linkType = $lm[1];
            $mac = strtolower($lm[2]);
        }

        if ($linkType === null && $name === 'lo') {
            $linkType = 'loopback';
        }

        $interfaces[$name] = [
            'name' => $name,
            'mac' => $mac,
            'state' => $state,
            'mtu' => $mtu,
            'linkType' => $linkType,
            'flags' => $flags,
            'addresses' => [],
            'speed' => null,
            'duplex' => null,
            'driver' => null,
            'firmwareVersion' => null,
            'busInfo' => null,
        ];
    }

    if (count($interfaces) === 0) {
        return [
            'summary' => [
                'totalInterfaces' => 0,
                'upInterfaces' => 0,
                'downInterfaces' => 0,
            ],
            'interfaces' => [],
            'ethtoolUsed' => false,
        ];
    }

    inventoryNetworkPopulateAddresses($interfaces);
    $ethtoolUsed = inventoryNetworkPopulateEthtool($interfaces);

    $total = count($interfaces);
    $up = 0;
    $down = 0;

    foreach ($interfaces as $iface) {
        $state = strtoupper((string) ($iface['state'] ?? 'UNKNOWN'));
        if ($state === 'UP') {
            $up++;
        } elseif ($state === 'DOWN') {
            $down++;
        }
    }

    return [
        'summary' => [
            'totalInterfaces' => $total,
            'upInterfaces' => $up,
            'downInterfaces' => $down,
        ],
        'interfaces' => array_values($interfaces),
        'ethtoolUsed' => $ethtoolUsed,
    ];
}

/**
 * @param array<string,array<string,mixed>> $interfaces
 */
function inventoryNetworkPopulateAddresses(array &$interfaces): void
{
    foreach ($interfaces as $name => &$iface) {
        $cmd = sprintf('ip -o addr show dev %s 2>/dev/null', escapeshellarg($name));
        $output = @shell_exec($cmd);
        if (!is_string($output) || trim($output) === '') {
            continue;
        }

        $lines = preg_split('/\R/', trim($output));
        if (!is_array($lines)) {
            continue;
        }

        $addresses = [];

        foreach ($lines as $line) {
            $trimmed = trim((string) $line);
            if ($trimmed === '') {
                continue;
            }

            if (!preg_match('/^\d+:\s+(\S+)\s+(\S+)\s+([0-9a-fA-F:.]+)\/(\d+)/', $trimmed, $m)) {
                continue;
            }

            $family = strtolower($m[2]);
            $addr = $m[3];
            $prefix = (int) $m[4];

            $addresses[] = [
                'family' => $family,
                'address' => $addr,
                'prefixLength' => $prefix,
            ];
        }

        $iface['addresses'] = $addresses;
    }
    unset($iface);
}

/**
 * @param array<string,array<string,mixed>> $interfaces
 */
function inventoryNetworkPopulateEthtool(array &$interfaces): bool
{
    $hasEthtool = inventoryNetworkHasEthtool();
    if (!$hasEthtool) {
        return false;
    }

    $used = false;

    foreach ($interfaces as $name => &$iface) {
        $info = inventoryNetworkReadEthtool($name);
        if ($info === null) {
            continue;
        }
        $used = true;

        $iface['speed'] = $info['speed'];
        $iface['duplex'] = $info['duplex'];
        $iface['driver'] = $info['driver'];
        $iface['firmwareVersion'] = $info['firmwareVersion'];
        $iface['busInfo'] = $info['busInfo'];
    }
    unset($iface);

    return $used;
}

function inventoryNetworkHasEthtool(): bool
{
    $result = @shell_exec('command -v ethtool 2>/dev/null');
    return is_string($result) && trim($result) !== '';
}

/**
 * @return array{speed:?string,duplex:?string,driver:?string,firmwareVersion:?string,busInfo:?string}|null
 */
function inventoryNetworkReadEthtool(string $ifaceName): ?array
{
    $iface = escapeshellarg($ifaceName);

    $baseOutput = @shell_exec('ethtool ' . $iface . ' 2>/dev/null');
    $infoOutput = @shell_exec('ethtool -i ' . $iface . ' 2>/dev/null');

    if ((!is_string($baseOutput) || trim($baseOutput) === '') && (!is_string($infoOutput) || trim($infoOutput) === '')) {
        return null;
    }

    $speed = null;
    $duplex = null;

    if (is_string($baseOutput) && trim($baseOutput) !== '') {
        $lines = preg_split('/\R/', $baseOutput);
        if (is_array($lines)) {
            foreach ($lines as $line) {
                $trimmed = trim((string) $line);
                if (stripos($trimmed, 'Speed:') === 0) {
                    $value = trim(substr($trimmed, strlen('Speed:')));
                    if ($value !== 'Unknown!') {
                        $speed = $value;
                    }
                } elseif (stripos($trimmed, 'Duplex:') === 0) {
                    $value = trim(substr($trimmed, strlen('Duplex:')));
                    $duplex = $value;
                }
            }
        }
    }

    $driver = null;
    $firmware = null;
    $busInfo = null;

    if (is_string($infoOutput) && trim($infoOutput) !== '') {
        $lines = preg_split('/\R/', $infoOutput);
        if (is_array($lines)) {
            foreach ($lines as $line) {
                $trimmed = trim((string) $line);
                if (stripos($trimmed, 'driver:') === 0) {
                    $driver = trim(substr($trimmed, strlen('driver:')));
                } elseif (stripos($trimmed, 'firmware-version:') === 0) {
                    $firmware = trim(substr($trimmed, strlen('firmware-version:')));
                } elseif (stripos($trimmed, 'bus-info:') === 0) {
                    $busInfo = trim(substr($trimmed, strlen('bus-info:')));
                }
            }
        }
    }

    return [
        'speed' => $speed,
        'duplex' => $duplex,
        'driver' => $driver,
        'firmwareVersion' => $firmware,
        'busInfo' => $busInfo,
    ];
}

/**
 * @param array<string,mixed> $inventory
 */
function inventoryNetworkRenderHuman(array $inventory, bool $colorEnabled): void
{
    /** @var array<string,int> $summary */
    $summary = $inventory['summary'] ?? [];
    /** @var array<int,array<string,mixed>> $interfaces */
    $interfaces = $inventory['interfaces'] ?? [];

    $sectionColor = $colorEnabled ? "\033[1;34m" : '';
    $ifaceColor = $colorEnabled ? "\033[0;36m" : '';
    $stateUpColor = $colorEnabled ? "\033[0;32m" : '';
    $stateDownColor = $colorEnabled ? "\033[0;31m" : '';
    $resetColor = $colorEnabled ? "\033[0m" : '';

    echo $sectionColor . "Network interfaces" . $resetColor . PHP_EOL;

    if (count($interfaces) === 0) {
        echo "  (no interfaces detected)\n";
    }

    foreach ($interfaces as $iface) {
        $name = (string) ($iface['name'] ?? '');
        $mac = $iface['mac'] ?? null;
        $state = strtoupper((string) ($iface['state'] ?? 'UNKNOWN'));
        $mtu = (int) ($iface['mtu'] ?? 0);
        $linkType = $iface['linkType'] ?? null;
        $speed = $iface['speed'] ?? null;
        $driver = $iface['driver'] ?? null;
        $firmware = $iface['firmwareVersion'] ?? null;
        $busInfo = $iface['busInfo'] ?? null;

        $addresses = [];
        if (isset($iface['addresses']) && is_array($iface['addresses'])) {
            /** @var array<int,array<string,mixed>> $addrList */
            $addrList = $iface['addresses'];
            foreach ($addrList as $addr) {
                $family = $addr['family'] ?? '';
                $address = $addr['address'] ?? '';
                $prefix = isset($addr['prefixLength']) ? (int) $addr['prefixLength'] : null;
                if ($address !== '' && $prefix !== null) {
                    $addresses[] = sprintf('%s %s/%d', $family, $address, $prefix);
                }
            }
        }

        $stateColor = $state === 'UP' ? $stateUpColor : $stateDownColor;

        echo sprintf(
            "  * %s%s%s; state=%s%s%s; mtu=%d\n",
            $ifaceColor,
            $name,
            $resetColor,
            $stateColor,
            $state,
            $resetColor,
            $mtu
        );

        if ($mac !== null) {
            echo sprintf("      MAC: %s\n", $mac);
        }
        if ($linkType !== null) {
            echo sprintf("      Link: %s\n", $linkType);
        }
        if ($speed !== null || $iface['duplex'] !== null) {
            echo sprintf(
                "      Speed: %s, duplex: %s\n",
                $speed !== null ? $speed : 'unknown',
                $iface['duplex'] !== null ? $iface['duplex'] : 'unknown'
            );
        }
        if ($driver !== null || $firmware !== null || $busInfo !== null) {
            echo "      Driver:";
            echo sprintf(" %s", $driver !== null ? $driver : 'unknown');
            if ($firmware !== null && $firmware !== '') {
                echo sprintf("; firmware=%s", $firmware);
            }
            if ($busInfo !== null && $busInfo !== '') {
                echo sprintf("; bus=%s", $busInfo);
            }
            echo PHP_EOL;
        }

        if (count($addresses) > 0) {
            echo sprintf("      Addresses: %s\n", implode(', ', $addresses));
        }
    }

    echo PHP_EOL;

    $total = (int) ($summary['totalInterfaces'] ?? 0);
    $up = (int) ($summary['upInterfaces'] ?? 0);
    $down = (int) ($summary['downInterfaces'] ?? 0);

    echo sprintf(
        "Summary: total=%d; up=%d; down=%d\n",
        $total,
        $up,
        $down
    );
}

/**
 * @param array<string,mixed> $inventory
 */
function inventoryNetworkRenderJson(array $inventory): void
{
    $encoded = json_encode($inventory, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($encoded === false) {
        \mcxForge\Logger::logStderr("Error: failed to encode JSON output.\n");
        exit(EXIT_ERROR);
    }

    echo $encoded . PHP_EOL;
}

/**
 * @param array<string,mixed> $inventory
 */
function inventoryNetworkRenderPhp(array $inventory): void
{
    echo serialize($inventory) . PHP_EOL;
}

function inventoryNetworkPrintHelp(): void
{
    $help = <<<TEXT
Usage: inventoryNetwork.php [--format=human|json|php] [--no-color]

Collect network interface inventory for the current host.

Options:
  --format=human    Human-readable output (default).
  --format=json     JSON output with per-interface details and a summary.
  --format=php      PHP serialize() output of the same structure.
  --no-color        Disable ANSI colors in human output.
  -h, --help        Show this help message.

Notes:
  - Interfaces are discovered via 'ip -o link show' and 'ip -o addr show'.
  - Speed, duplex, and firmware details are collected via ethtool when available.

TEXT;

    echo $help;
}

if (PHP_SAPI === 'cli' && isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    exit(inventoryNetworkMain($argv));
}

