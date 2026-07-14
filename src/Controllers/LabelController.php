<?php

declare(strict_types=1);

namespace SDS\Controllers;

use SDS\Core\App;
use SDS\Core\Database;
use SDS\Models\FinishedGood;
use SDS\Models\LabelTemplate;
use SDS\Models\Manufacturer;
use SDS\Services\AliasResolver;
use SDS\Services\SDSGenerator;
use SDS\Services\LabelPDFService;

class LabelController
{
    public function index(): void
    {
        if (!can_read('labels')) {
            $_SESSION['_flash']['error'] = 'You do not have permission to access GHS Labels.';
            redirect('/');
        }

        $db = Database::getInstance();

        // Unified product search: a single list combining every printable
        // target — finished goods, aliases (customer codes), and resale raw
        // materials. Each entry carries the fields the generate() endpoint
        // needs: an FG id (formula products) or a resale code (aliases / RMs).
        // A direct query is used instead of FinishedGood::all() so the list
        // isn't capped at the model's 100-row pagination limit.
        $finishedGoods = $db->fetchAll(
            "SELECT id, product_code, description
             FROM finished_goods
             WHERE is_active = 1
             ORDER BY product_code ASC"
        );

        $aliasRows = $db->fetchAll(
            "SELECT id, customer_code, description, internal_code_base
             FROM aliases
             ORDER BY customer_code ASC"
        );

        $rawMaterials = $db->fetchAll(
            "SELECT id, internal_code, supplier_product_name
             FROM raw_materials
             ORDER BY internal_code ASC"
        );

        $products = [];

        foreach ($finishedGoods as $fg) {
            $products[] = [
                'type'        => 'fg',
                'fg_id'       => (int) $fg['id'],
                'resale_code' => '',
                'code'        => $fg['product_code'],
                'description' => $fg['description'],
            ];
        }

        // Aliases: dedupe to one entry per base customer code (pack variants
        // print identical labels). The resale code is the base customer_code;
        // resolveLabelTarget() routes it to the right formula/resale source.
        $seenAlias = [];
        foreach ($aliasRows as $a) {
            $base = AliasResolver::stripPack((string) $a['customer_code']);
            if ($base === '' || isset($seenAlias[$base])) {
                continue;
            }
            $seenAlias[$base] = true;
            $products[] = [
                'type'        => 'alias',
                'fg_id'       => null,
                'resale_code' => $base,
                'code'        => $base,
                'description' => $a['description'],
            ];
        }

        // Resale raw materials: printable directly under their own code.
        // Dedupe by base code — pack variants (DSS0100, DSS0100-20) share
        // hazard data and print identical labels.
        $seenRm = [];
        foreach ($rawMaterials as $rm) {
            $code = AliasResolver::stripPack((string) $rm['internal_code']);
            if ($code === '' || isset($seenRm[$code])) {
                continue;
            }
            $seenRm[$code] = true;
            $products[] = [
                'type'        => 'rm',
                'fg_id'       => null,
                'resale_code' => $code,
                'code'        => $code,
                'description' => $rm['supplier_product_name'] ?? '',
            ];
        }

        $templates = LabelTemplate::all();

        // Load net weight unit options from admin settings
        $unitRow = $db->fetch("SELECT `value` FROM settings WHERE `key` = 'label.net_weight_units'");
        $netWeightUnits = [];
        if ($unitRow && trim($unitRow['value']) !== '') {
            $netWeightUnits = array_filter(array_map('trim', explode("\n", $unitRow['value'])), 'strlen');
        }

        $manufacturers = Manufacturer::all();

        // Company name (from admin settings, falling back to config) so the
        // manufacturer dropdown's default option reads as the real company
        // rather than a generic "Default (company settings)" label.
        $companyRow  = $db->fetch("SELECT `value` FROM settings WHERE `key` = 'company.name'");
        $companyName = ($companyRow['value'] ?? '') !== ''
            ? $companyRow['value']
            : App::config('company.name', 'Company default');

        view('labels/index', [
            'pageTitle'      => 'GHS Labels',
            'products'       => $products,
            'templates'      => $templates,
            'netWeightUnits' => $netWeightUnits,
            'manufacturers'  => $manufacturers,
            'colorOptions'   => LabelPDFService::colorOptions(),
            'companyName'    => $companyName,
        ]);
    }

    public function generate(): void
    {
        if (!can_read('labels')) {
            $_SESSION['_flash']['error'] = 'You do not have permission to generate labels.';
            redirect('/');
        }

        $finishedGoodId  = (int) ($_POST['finished_good_id'] ?? 0);
        $resaleCode      = trim($_POST['resale_code'] ?? '');
        $lotNumber       = trim($_POST['lot_number'] ?? '');
        $templateId      = (int) ($_POST['template_id'] ?? 0);
        $sheets          = max(1, (int) ($_POST['sheets'] ?? 1));
        $netWeightValue  = trim($_POST['net_weight_value'] ?? '');
        $netWeightUnit   = trim($_POST['net_weight_unit'] ?? '');
        $netWeight       = $netWeightValue !== '' ? $netWeightValue . ($netWeightUnit !== '' ? ' ' . $netWeightUnit : '') : '';
        $privateLabel    = !empty($_POST['private_label']);
        $manufacturerId  = (int) ($_POST['manufacturer_id'] ?? 0);

        // Product-identification color block. Validate against the known
        // options; anything unexpected falls back to 'none' (outline only).
        $colorCode = strtolower(trim($_POST['color_code'] ?? 'none'));
        if (!array_key_exists($colorCode, LabelPDFService::colorOptions())) {
            $colorCode = 'none';
        }

        // Resolve the label target. Three input modes, checked in order:
        //   1. resale_code — an alias customer_code, an RM base code, or
        //      an RM pack-variant code. AliasResolver routes it to the
        //      right composition source (formula vs 100 % RM resale).
        //   2. finished_good_id — the existing FG dropdown.
        // The resolved target is encoded as either ($fg, $sdsData,
        // $productCode) for FG labels, or ($rm, $sdsData, $productCode)
        // with a synthetic $fg dict for resale labels.
        $fg         = null;  // real FG row, OR synthetic row for resale
        $sdsData    = null;
        $productCode = null;

        if ($resaleCode !== '') {
            $resolved = self::resolveLabelTarget($resaleCode);
            if ($resolved === null) {
                $_SESSION['_flash']['error'] =
                    'Could not find a finished good, alias, or raw material matching "' . $resaleCode . '".';
                redirect('/labels');
            }
            $fg          = $resolved['fg'];
            $sdsData     = $resolved['sds_builder']();
            $productCode = $resolved['display_code'];
        } else {
            $fg = FinishedGood::findById($finishedGoodId);
            if ($fg === null) {
                $_SESSION['_flash']['error'] = 'Please select a valid product.';
                redirect('/labels');
            }
            $productCode = $fg['product_code'];
        }

        // Validate lot number: must be 1 to 12 digits
        if (!preg_match('/^\d{1,12}$/', $lotNumber)) {
            $_SESSION['_flash']['error'] = 'Lot number must be 1 to 12 digits.';
            redirect('/labels');
        }

        // Get template
        $template = null;
        if ($templateId > 0) {
            $template = LabelTemplate::findById($templateId);
        }
        if ($template === null) {
            $template = LabelTemplate::getDefault();
        }
        if ($template === null) {
            $_SESSION['_flash']['error'] = 'No label template found. Please create one first.';
            redirect('/labels');
        }

        // Convert sheets to total label count
        $labelsPerSheet = (int) $template['cols'] * (int) $template['rows'];
        $quantity = $sheets * $labelsPerSheet;

        try {
            // Generate SDS data only if not already built by the resale
            // resolver — formula-based FGs still use the regular path.
            if ($sdsData === null) {
                $generator = new SDSGenerator();
                $sdsData   = $generator->generate($finishedGoodId, 'en');
            }

            // Override manufacturer info if a specific manufacturer is selected
            if ($manufacturerId > 0) {
                $mfg = Manufacturer::findById($manufacturerId);
                if ($mfg) {
                    $mfgInfo = Manufacturer::toCompanyInfo($mfg);
                    $sdsData['sections'][1]['manufacturer_name']    = $mfgInfo['name'];
                    $sdsData['sections'][1]['manufacturer_address'] = trim($mfgInfo['address'] . ', ' . $mfgInfo['city'] . ', ' . $mfgInfo['state'] . ' ' . $mfgInfo['zip'], ', ');
                    $sdsData['sections'][1]['manufacturer_phone']   = $mfgInfo['phone'];
                }
            }

            // Generate PDF
            $pdfService = new LabelPDFService();
            $pdfContent = $pdfService->generateFromTemplate($sdsData, $fg, $lotNumber, $template, $quantity, $netWeight, $privateLabel, $colorCode);

            // Output PDF
            $filename = $productCode . '_label_' . $lotNumber . '.pdf';
            header('Content-Type: application/pdf');
            header('Content-Disposition: inline; filename="' . $filename . '"');
            header('Content-Length: ' . strlen($pdfContent));
            echo $pdfContent;
            exit;
        } catch (\Throwable $e) {
            $_SESSION['_flash']['error'] = 'Label generation failed: ' . $e->getMessage();
            redirect('/labels');
        }
    }

    /**
     * Resolve a user-typed code (alias, RM, or RM-with-pack) into a
     * label target. Returns null when the code doesn't match anything.
     *
     * The returned array has:
     *   fg            — either a real FG row (formula path) or a
     *                   synthetic row shaped like an FG (resale path)
     *   sds_builder   — closure returning the SDSData array. Deferred
     *                   so the caller doesn't pay the generation cost
     *                   on resolution failure.
     *   display_code  — pack-extension-stripped code used for the
     *                   label's product identifier and PDF filename.
     */
    private static function resolveLabelTarget(string $code): ?array
    {
        // Alias path first — aliases may resolve to either a formula FG
        // or a resale RM. AliasResolver encapsulates the rule.
        $alias = AliasResolver::resolveByCustomerCode($code);
        if ($alias !== null) {
            if ($alias['type'] === 'formula') {
                $fgId = (int) $alias['fg']['id'];
                $fg   = FinishedGood::findById($fgId);
                if ($fg === null) {
                    return null;
                }
                $displayCode = $alias['display_code'];
                $aliasDesc   = (string) $alias['alias']['description'];
                return [
                    'fg'           => $fg,
                    'display_code' => $displayCode,
                    'sds_builder'  => function () use ($fgId, $displayCode, $aliasDesc) {
                        $generator = new SDSGenerator();
                        $sds = $generator->generate($fgId, 'en');
                        return SDSGenerator::createAliasVariant($sds, $displayCode, $aliasDesc);
                    },
                ];
            }
            if ($alias['type'] === 'resale') {
                $rmId        = (int) $alias['rm']['id'];
                $displayCode = $alias['display_code'];
                $aliasDesc   = (string) $alias['alias']['description'];
                return [
                    'fg'           => self::buildResaleFg($alias['rm'], $displayCode, $aliasDesc),
                    'display_code' => $displayCode,
                    'sds_builder'  => function () use ($rmId, $displayCode, $aliasDesc) {
                        $generator = new SDSGenerator();
                        $sds = $generator->generateForResaleRawMaterial($rmId, 'en');
                        return SDSGenerator::createAliasVariant($sds, $displayCode, $aliasDesc);
                    },
                ];
            }
        }

        // Direct RM code (e.g. "DSS0100" or "DSS0100-20"). Strip the
        // pack extension; pack size doesn't change label content.
        $rmResolution = AliasResolver::resolveByRawMaterialCode($code);
        if ($rmResolution !== null) {
            $rm          = $rmResolution['rm'];
            $rmId        = (int) $rm['id'];
            $displayCode = $rmResolution['display_code'];
            $description = (string) ($rm['supplier_product_name'] ?? $displayCode);
            return [
                'fg'           => self::buildResaleFg($rm, $displayCode, $description),
                'display_code' => $displayCode,
                'sds_builder'  => function () use ($rmId) {
                    $generator = new SDSGenerator();
                    return $generator->generateForResaleRawMaterial($rmId, 'en');
                },
            ];
        }

        return null;
    }

    /**
     * Build an FG-shaped dict from a raw material for LabelPDFService.
     * The label service reads product_code, description, physical_state,
     * and color off this dict — all of which map cleanly from the RM.
     */
    private static function buildResaleFg(array $rm, string $displayCode, string $description): array
    {
        return [
            'id'                  => null,
            'product_code'        => $displayCode,
            'description'         => $description,
            'family'              => null,
            'is_active'           => 1,
            'physical_state'      => $rm['physical_state'] ?? null,
            'color'               => $rm['color'] ?? null,
            'recommended_use'     => null,
            'restrictions_on_use' => null,
        ];
    }
}
