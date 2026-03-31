<?php include dirname(__DIR__) . '/layouts/main.php'; ?>

<?php
$isEdit = $mode === 'edit';
$action = $isEdit ? '/manufacturers/' . (int) $item['id'] : '/manufacturers';
?>

<div class="card">
    <form method="POST" action="<?= $action ?>" enctype="multipart/form-data">
        <?= csrf_field() ?>

        <h3>Manufacturer Information</h3>
        <div class="form-grid-2col">
            <div class="form-group full-width">
                <label for="name">Company Name <span class="text-danger">*</span></label>
                <input type="text" id="name" name="name" required
                       value="<?= e(old('name', $item['name'] ?? '')) ?>"
                       placeholder="e.g. Acme Ink Corporation">
            </div>

            <div class="form-group full-width">
                <label for="address">Street Address</label>
                <input type="text" id="address" name="address"
                       value="<?= e(old('address', $item['address'] ?? '')) ?>"
                       placeholder="e.g. 123 Industrial Blvd, Suite 100">
            </div>

            <div class="form-group">
                <label for="city">City</label>
                <input type="text" id="city" name="city"
                       value="<?= e(old('city', $item['city'] ?? '')) ?>">
            </div>

            <div class="form-group">
                <label for="state">State / Province</label>
                <input type="text" id="state" name="state"
                       value="<?= e(old('state', $item['state'] ?? '')) ?>">
            </div>

            <div class="form-group">
                <label for="zip">ZIP / Postal Code</label>
                <input type="text" id="zip" name="zip"
                       value="<?= e(old('zip', $item['zip'] ?? '')) ?>">
            </div>

            <div class="form-group">
                <label for="country">Country</label>
                <input type="text" id="country" name="country"
                       value="<?= e(old('country', $item['country'] ?? '')) ?>"
                       placeholder="e.g. United States">
            </div>
        </div>

        <h3>Contact Information</h3>
        <div class="form-grid-2col">
            <div class="form-group">
                <label for="phone">Phone Number</label>
                <input type="text" id="phone" name="phone"
                       value="<?= e(old('phone', $item['phone'] ?? '')) ?>"
                       placeholder="e.g. (555) 123-4567">
            </div>

            <div class="form-group">
                <label for="emergency_phone">Emergency Phone</label>
                <input type="text" id="emergency_phone" name="emergency_phone"
                       value="<?= e(old('emergency_phone', $item['emergency_phone'] ?? '')) ?>"
                       placeholder="e.g. (800) 424-9300">
                <small class="text-muted">24-hour emergency number for SDS Section 1</small>
            </div>

            <div class="form-group">
                <label for="email">Email</label>
                <input type="email" id="email" name="email"
                       value="<?= e(old('email', $item['email'] ?? '')) ?>">
            </div>

            <div class="form-group">
                <label for="website">Website</label>
                <input type="text" id="website" name="website"
                       value="<?= e(old('website', $item['website'] ?? '')) ?>"
                       placeholder="e.g. https://www.example.com">
            </div>
        </div>

        <h3>Logo &amp; Settings</h3>
        <div class="form-grid-2col">
            <div class="form-group">
                <label for="logo">Company Logo</label>
                <?php if ($isEdit && !empty($item['logo_path'])): ?>
                    <div style="margin-bottom: 0.5rem;">
                        <img src="<?= e($item['logo_path']) ?>" alt="Current Logo" style="max-height: 60px; max-width: 200px; border: 1px solid #e0e0e0; padding: 4px; border-radius: 4px; background: #fff;">
                        <label style="display: inline-flex; align-items: center; gap: 0.3rem; margin-left: 0.5rem; cursor: pointer;">
                            <input type="checkbox" name="remove_logo" value="1">
                            <span class="text-muted">Remove logo</span>
                        </label>
                    </div>
                <?php endif; ?>
                <input type="file" name="logo" id="logo" accept="image/png,image/jpeg,image/gif">
                <small class="text-muted">PNG, JPG, or GIF. Max 2 MB.</small>
            </div>

            <div class="form-group">
                <label style="display: inline-flex; align-items: center; gap: 0.5rem; cursor: pointer; margin-top: 1.5rem;">
                    <input type="checkbox" name="is_default" value="1"
                           <?= !empty($item['is_default']) ? 'checked' : '' ?>>
                    <span>Set as Default Manufacturer</span>
                </label>
                <small class="text-muted" style="display: block;">The default manufacturer is automatically selected for SDS creation.</small>
            </div>
        </div>

        <div class="form-actions">
            <button type="submit" class="btn btn-primary"><?= $isEdit ? 'Update' : 'Create' ?> Manufacturer</button>
            <a href="/manufacturers" class="btn btn-outline">Cancel</a>
        </div>
    </form>
</div>

<?php include dirname(__DIR__) . '/layouts/footer.php'; ?>
