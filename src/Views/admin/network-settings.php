<?php include dirname(__DIR__) . '/layouts/main.php'; ?>

<div class="card">
    <h2>Current Network Configuration</h2>
    <p class="text-muted mb-1">Detected settings for interface <strong><?= e($network['interface']) ?></strong>. Method: <strong><?= e($network['method']) ?></strong>.</p>

    <table class="table" style="max-width: 500px; margin-bottom: 2rem;">
        <tbody>
            <tr><td style="width: 150px;"><strong>Interface</strong></td><td><?= e($network['interface']) ?></td></tr>
            <tr><td><strong>IP Address</strong></td><td><?= e($network['ip_address'] ?: '(not detected)') ?></td></tr>
            <tr><td><strong>Subnet Mask</strong></td><td><?= e($network['subnet_mask']) ?> (<?= '/'. $network['cidr'] ?>)</td></tr>
            <tr><td><strong>Gateway</strong></td><td><?= e($network['gateway'] ?: '(not detected)') ?></td></tr>
            <tr><td><strong>DNS Servers</strong></td><td><?= e(implode(', ', $network['dns_servers'] ?: ['(none)'])) ?></td></tr>
            <tr><td><strong>Hostname</strong></td><td><?= e($network['hostname']) ?></td></tr>
        </tbody>
    </table>

    <h2>Change Network Settings</h2>

    <div style="margin-bottom: 1.5rem; padding: 1rem; background: #fff3cd; border: 1px solid #ffc107; border-radius: 4px; color: #856404;">
        <strong>Warning:</strong> Changing the IP address, subnet, or gateway will immediately apply new network settings to this server.
        <strong>You may lose your connection</strong> and need to reconnect using the new IP address.
        Make sure you have console or physical access to this server before making changes.
    </div>

    <form method="POST" action="/admin/network-settings">
        <?= csrf_field() ?>

        <div class="form-grid-2col">
            <div class="form-group">
                <label>IP Address</label>
                <input type="text" name="ip_address"
                       value="<?= e($network['ip_address']) ?>"
                       pattern="\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}"
                       placeholder="192.168.1.100">
                <small class="text-muted">IPv4 address (e.g. 192.168.1.100)</small>
            </div>

            <div class="form-group">
                <label>Subnet Mask</label>
                <select name="subnet_mask" id="subnet_mask">
                    <?php
                    $subnets = [
                        '255.255.255.0'   => '255.255.255.0 (/24) — Most common',
                        '255.255.255.128' => '255.255.255.128 (/25)',
                        '255.255.254.0'   => '255.255.254.0 (/23)',
                        '255.255.252.0'   => '255.255.252.0 (/22)',
                        '255.255.248.0'   => '255.255.248.0 (/21)',
                        '255.255.240.0'   => '255.255.240.0 (/20)',
                        '255.255.0.0'     => '255.255.0.0 (/16)',
                        '255.0.0.0'       => '255.0.0.0 (/8)',
                    ];
                    foreach ($subnets as $mask => $label):
                    ?>
                        <option value="<?= $mask ?>" <?= $network['subnet_mask'] === $mask ? 'selected' : '' ?>><?= $label ?></option>
                    <?php endforeach; ?>
                </select>
                <small class="text-muted">Select the subnet mask for your network</small>
            </div>

            <div class="form-group">
                <label>Gateway</label>
                <input type="text" name="gateway"
                       value="<?= e($network['gateway']) ?>"
                       pattern="\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}"
                       placeholder="192.168.1.1">
                <small class="text-muted">Default gateway / router address</small>
            </div>

            <div class="form-group">
                <label>DNS Servers</label>
                <input type="text" name="dns_servers"
                       value="<?= e(implode(', ', $network['dns_servers'])) ?>"
                       placeholder="8.8.8.8, 8.8.4.4">
                <small class="text-muted">Comma-separated DNS server addresses</small>
            </div>
        </div>

        <div style="margin: 1.5rem 0; padding: 0.75rem 1rem; background: #f8d7da; border: 1px solid #f5c6cb; border-radius: 4px; color: #721c24;">
            <label style="font-weight: normal; cursor: pointer;">
                <input type="checkbox" name="confirm_network" value="1" id="confirm_check" style="margin-right: 0.5rem;">
                <strong>I understand that applying these changes may disconnect my current session</strong> and I have alternative access (console, physical, or IPMI) to this server if needed.
            </label>
        </div>

        <div class="form-actions">
            <button type="submit" class="btn btn-primary" id="apply_btn" disabled>Apply Network Settings</button>
        </div>
    </form>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var check = document.getElementById('confirm_check');
    var btn = document.getElementById('apply_btn');

    if (check && btn) {
        check.addEventListener('change', function () {
            btn.disabled = !this.checked;
        });
    }

    // Double-confirm on submit
    var form = btn ? btn.closest('form') : null;
    if (form) {
        form.addEventListener('submit', function (e) {
            var ip = form.querySelector('[name="ip_address"]').value;
            var gw = form.querySelector('[name="gateway"]').value;
            var mask = form.querySelector('[name="subnet_mask"]').value;

            var msg = 'You are about to change the network settings:\n\n'
                + '  IP Address:   ' + ip + '\n'
                + '  Subnet Mask:  ' + mask + '\n'
                + '  Gateway:      ' + gw + '\n\n'
                + 'The server may become unreachable at the current address.\n'
                + 'You will need to reconnect at: http://' + ip + '\n\n'
                + 'Are you sure you want to proceed?';

            if (!confirm(msg)) {
                e.preventDefault();
            }
        });
    }
});
</script>

<?php include dirname(__DIR__) . '/layouts/footer.php'; ?>
