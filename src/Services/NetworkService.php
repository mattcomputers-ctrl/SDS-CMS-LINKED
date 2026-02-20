<?php

declare(strict_types=1);

namespace SDS\Services;

/**
 * NetworkService — read and apply OS-level network configuration.
 *
 * Supports Ubuntu/Debian systems using Netplan (18.04+) or legacy
 * /etc/network/interfaces.  Changes are written to configuration
 * files and applied; the calling code should warn the user that
 * changing the IP may cause an immediate disconnection.
 */
class NetworkService
{
    /**
     * Get the current network configuration for the primary interface.
     *
     * @return array{
     *   interface: string,
     *   ip_address: string,
     *   subnet_mask: string,
     *   cidr: int,
     *   gateway: string,
     *   dns_servers: string[],
     *   method: string,
     *   hostname: string
     * }
     */
    public static function getCurrentConfig(): array
    {
        $iface   = self::getPrimaryInterface();
        $details = self::getInterfaceDetails($iface);
        $gateway = self::getDefaultGateway();
        $dns     = self::getDnsServers();

        return [
            'interface'   => $iface,
            'ip_address'  => $details['ip_address'],
            'subnet_mask' => $details['subnet_mask'],
            'cidr'        => $details['cidr'],
            'gateway'     => $gateway,
            'dns_servers' => $dns,
            'method'      => $details['method'],
            'hostname'    => gethostname() ?: '',
        ];
    }

    /**
     * Apply new network settings.
     *
     * @param  array $config  Keys: ip_address, cidr (or subnet_mask), gateway, dns_servers (comma-separated)
     * @return array{success: bool, message: string, method: string}
     */
    public static function applyConfig(array $config): array
    {
        $ip      = trim($config['ip_address'] ?? '');
        $cidr    = (int) ($config['cidr'] ?? 0);
        $subnet  = trim($config['subnet_mask'] ?? '');
        $gateway = trim($config['gateway'] ?? '');
        $dns     = trim($config['dns_servers'] ?? '');

        // Validate IP
        if ($ip !== '' && !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return ['success' => false, 'message' => 'Invalid IPv4 address.', 'method' => ''];
        }

        // Convert subnet mask to CIDR if needed
        if ($cidr === 0 && $subnet !== '') {
            $cidr = self::subnetToCidr($subnet);
        }
        if ($cidr < 1 || $cidr > 32) {
            return ['success' => false, 'message' => 'Invalid subnet / CIDR prefix (must be 1-32).', 'method' => ''];
        }

        // Validate gateway
        if ($gateway !== '' && !filter_var($gateway, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return ['success' => false, 'message' => 'Invalid gateway address.', 'method' => ''];
        }

        // Determine the config method available
        if (self::hasNetplan()) {
            return self::applyViaNetplan($ip, $cidr, $gateway, $dns);
        }

        return self::applyViaInterfaces($ip, $cidr, $gateway, $dns);
    }

    // ── Interface detection ──────────────────────────────────────

    private static function getPrimaryInterface(): string
    {
        // Use ip route to find the default interface
        $out = shell_exec('ip route show default 2>/dev/null') ?? '';
        if (preg_match('/dev\s+(\S+)/', $out, $m)) {
            return $m[1];
        }

        // Fallback: first non-lo interface
        $out = shell_exec('ls /sys/class/net/ 2>/dev/null') ?? '';
        $ifaces = array_filter(explode("\n", trim($out)), fn($i) => $i !== '' && $i !== 'lo');
        return reset($ifaces) ?: 'eth0';
    }

    private static function getInterfaceDetails(string $iface): array
    {
        $result = [
            'ip_address'  => '',
            'subnet_mask' => '',
            'cidr'        => 0,
            'method'      => 'unknown',
        ];

        $out = shell_exec("ip -4 addr show {$iface} 2>/dev/null") ?? '';

        // Parse: inet 192.168.1.100/24 brd 192.168.1.255 scope global ...
        if (preg_match('/inet\s+(\d+\.\d+\.\d+\.\d+)\/(\d+)/', $out, $m)) {
            $result['ip_address'] = $m[1];
            $result['cidr']       = (int) $m[2];
            $result['subnet_mask'] = self::cidrToSubnet((int) $m[2]);
        }

        // Check if DHCP or static
        if (preg_match('/dynamic/', $out)) {
            $result['method'] = 'dhcp';
        } else {
            $result['method'] = 'static';
        }

        return $result;
    }

    private static function getDefaultGateway(): string
    {
        $out = shell_exec('ip route show default 2>/dev/null') ?? '';
        if (preg_match('/default via (\d+\.\d+\.\d+\.\d+)/', $out, $m)) {
            return $m[1];
        }
        return '';
    }

    private static function getDnsServers(): array
    {
        $servers = [];
        $out = shell_exec('cat /etc/resolv.conf 2>/dev/null') ?? '';
        if (preg_match_all('/^nameserver\s+(\S+)/m', $out, $matches)) {
            $servers = $matches[1];
        }
        return $servers;
    }

    // ── Netplan-based configuration (Ubuntu 18.04+) ──────────────

    private static function hasNetplan(): bool
    {
        return is_dir('/etc/netplan') && !empty(glob('/etc/netplan/*.yaml'));
    }

    private static function applyViaNetplan(string $ip, int $cidr, string $gateway, string $dns): array
    {
        $iface = self::getPrimaryInterface();
        $files = glob('/etc/netplan/*.yaml');
        if (empty($files)) {
            return ['success' => false, 'message' => 'No Netplan config files found.', 'method' => 'netplan'];
        }

        $netplanFile = $files[0];

        // Back up the existing config
        $backupFile = $netplanFile . '.bak.' . date('YmdHis');
        copy($netplanFile, $backupFile);

        // Build DNS list
        $dnsAddresses = array_filter(array_map('trim', explode(',', $dns)));
        if (empty($dnsAddresses)) {
            $dnsAddresses = ['8.8.8.8', '8.8.4.4'];
        }

        $dnsYaml = implode(', ', $dnsAddresses);

        $yaml = <<<YAML
# SDS System — Network Configuration
# Generated on {date}
# Previous config backed up to: {$backupFile}
network:
  version: 2
  renderer: networkd
  ethernets:
    {$iface}:
      addresses:
        - {$ip}/{$cidr}
      routes:
        - to: default
          via: {$gateway}
      nameservers:
        addresses: [{$dnsYaml}]
YAML;

        $yaml = str_replace('{date}', date('Y-m-d H:i:s'), $yaml);

        file_put_contents($netplanFile, $yaml);

        // Apply (netplan apply is non-interactive)
        exec('netplan apply 2>&1', $output, $rc);

        if ($rc !== 0) {
            // Rollback
            copy($backupFile, $netplanFile);
            exec('netplan apply 2>&1');
            return [
                'success' => false,
                'message' => 'Netplan apply failed: ' . implode("\n", $output) . '. Configuration was rolled back.',
                'method'  => 'netplan',
            ];
        }

        return [
            'success' => true,
            'message' => "Network settings applied via Netplan. Previous config backed up to {$backupFile}.",
            'method'  => 'netplan',
        ];
    }

    // ── Legacy /etc/network/interfaces (Debian / older Ubuntu) ───

    private static function applyViaInterfaces(string $ip, int $cidr, string $gateway, string $dns): array
    {
        $iface = self::getPrimaryInterface();
        $subnet = self::cidrToSubnet($cidr);
        $ifacesFile = '/etc/network/interfaces';

        if (!file_exists($ifacesFile)) {
            return [
                'success' => false,
                'message' => 'Neither Netplan nor /etc/network/interfaces found. Manual configuration required.',
                'method'  => 'none',
            ];
        }

        // Back up
        $backupFile = $ifacesFile . '.bak.' . date('YmdHis');
        copy($ifacesFile, $backupFile);

        $dnsAddresses = array_filter(array_map('trim', explode(',', $dns)));
        $dnsLine = !empty($dnsAddresses) ? implode(' ', $dnsAddresses) : '8.8.8.8 8.8.4.4';

        $content = <<<IFACES
# SDS System — Network Configuration
# Generated on {date}
# Previous config backed up to: {$backupFile}

auto lo
iface lo inet loopback

auto {$iface}
iface {$iface} inet static
    address {$ip}
    netmask {$subnet}
    gateway {$gateway}
    dns-nameservers {$dnsLine}
IFACES;

        $content = str_replace('{date}', date('Y-m-d H:i:s'), $content);
        file_put_contents($ifacesFile, $content);

        // Restart networking
        exec('systemctl restart networking 2>&1 || ifdown ' . escapeshellarg($iface) . ' && ifup ' . escapeshellarg($iface) . ' 2>&1', $output, $rc);

        return [
            'success' => true,
            'message' => "Network settings applied via /etc/network/interfaces. Previous config backed up to {$backupFile}.",
            'method'  => 'interfaces',
        ];
    }

    // ── CIDR / Subnet utilities ──────────────────────────────────

    public static function cidrToSubnet(int $cidr): string
    {
        $mask = $cidr > 0 ? (~0 << (32 - $cidr)) & 0xFFFFFFFF : 0;
        return long2ip($mask);
    }

    public static function subnetToCidr(string $subnet): int
    {
        $long = ip2long($subnet);
        if ($long === false) {
            return 0;
        }
        $bin = decbin($long);
        return substr_count($bin, '1');
    }
}
