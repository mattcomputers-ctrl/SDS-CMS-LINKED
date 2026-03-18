<?php

declare(strict_types=1);

namespace SDS\Models;

use SDS\Core\Database;

class LabelTemplate
{
    public static function all(): array
    {
        $db = Database::getInstance();
        return $db->fetchAll('SELECT * FROM label_templates ORDER BY is_default DESC, name ASC');
    }

    public static function findById(int $id): ?array
    {
        $db = Database::getInstance();
        return $db->fetch('SELECT * FROM label_templates WHERE id = ?', [$id]);
    }

    public static function create(array $data): int
    {
        $db = Database::getInstance();
        return (int) $db->insert('label_templates', $data);
    }

    public static function update(int $id, array $data): void
    {
        $db = Database::getInstance();
        $db->update('label_templates', $data, 'id = ?', [$id]);
    }

    public static function delete(int $id): void
    {
        $db = Database::getInstance();
        $db->delete('label_templates', 'id = ?', [$id]);
    }

    public static function getDefault(): ?array
    {
        $db = Database::getInstance();
        return $db->fetch('SELECT * FROM label_templates WHERE is_default = 1 LIMIT 1');
    }

    /**
     * Return the field layout as an associative array.
     */
    public static function decodeLayout(array $template): array
    {
        $layout = $template['field_layout'] ?? '{}';
        if (is_string($layout)) {
            return json_decode($layout, true) ?: [];
        }
        return is_array($layout) ? $layout : [];
    }

    /**
     * Valid field types that can be placed on a label.
     */
    public static function fieldTypes(): array
    {
        return [
            'lot_item_code'            => 'Lot # / Item Code',
            'pictograms'               => 'Pictograms',
            'signal_word'              => 'Signal Word',
            'hazard_statements'        => 'Hazard Statements',
            'precautionary_statements' => 'Precautionary Statements',
            'net_weight'               => 'Net Weight',
            'prop65_warning'           => 'Prop 65 Warning',
            'supplier_info'            => 'Supplier Info',
        ];
    }
}
