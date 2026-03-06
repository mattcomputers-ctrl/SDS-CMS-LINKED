<?php

declare(strict_types=1);

namespace SDS\Services;

use SDS\Core\Database;

/**
 * TrainingDataService — generates realistic sample data for system training.
 *
 * Creates ~100 raw materials with constituents and ~500 finished goods
 * with formulas. All generated data lives in tables that the Purge Data
 * feature can clear. Also produces downloadable CSV files compatible
 * with the HAP/VOC report upload workflow.
 */
class TrainingDataService
{
    /* ------------------------------------------------------------------
     *  Ink product families and naming patterns
     * ----------------------------------------------------------------*/

    private const FAMILIES = [
        'UV Offset',
        'UV Flexo',
        'Solvent',
        'Aqueous',
        'EB Cure',
    ];

    private const COLORS = [
        'Process Yellow', 'Process Magenta', 'Process Cyan', 'Process Black',
        'Warm Red', 'Reflex Blue', 'Rhodamine Red', 'Purple',
        'Green', 'Orange', 'Violet', 'Rubine Red',
        'White', 'Opaque White', 'Mixing Clear', 'Overprint Varnish',
        'Metallic Silver', 'Metallic Gold', 'Pantone 185', 'Pantone 286',
        'Pantone 348', 'Pantone 021', 'Pantone 165', 'Pantone 485',
        'Pantone 375', 'Pantone 541', 'Pantone 032', 'Pantone 877',
    ];

    private const FAMILY_PREFIXES = [
        'UV Offset'  => ['UVO', 'UVP'],
        'UV Flexo'   => ['UVF', 'FXO'],
        'Solvent'    => ['SOL', 'SFX'],
        'Aqueous'    => ['AQC', 'WBF'],
        'EB Cure'    => ['EBC', 'EBF'],
    ];

    /* ------------------------------------------------------------------
     *  Chemical data pools for realistic raw materials
     * ----------------------------------------------------------------*/

    /** Pigments (CAS, name) */
    private const PIGMENTS = [
        ['5102-83-0',  'Pigment Yellow 13'],
        ['6358-85-6',  'Pigment Yellow 12'],
        ['5567-15-7',  'Pigment Yellow 83'],
        ['980-26-7',   'Pigment Yellow 174'],
        ['2786-76-7',  'Pigment Red 57:1'],
        ['1328-53-6',  'Pigment Green 7'],
        ['147-14-8',   'Pigment Blue 15:3'],
        ['1333-86-4',  'Carbon Black'],
        ['13463-67-7', 'Titanium Dioxide'],
        ['1309-37-1',  'Iron Oxide Red'],
        ['12225-21-7', 'Pigment Orange 5'],
        ['6535-46-2',  'Pigment Red 53:1'],
        ['5281-04-9',  'Pigment Red 48:2'],
        ['6358-87-8',  'Pigment Yellow 14'],
        ['1047-16-1',  'Pigment Red 112'],
        ['12236-62-3', 'Pigment Violet 23'],
        ['68186-91-4', 'Pigment Violet 19'],
        ['85-86-9',    'Pigment Red 2'],
        ['3520-72-7',  'Pigment Orange 13'],
        ['8005-40-1',  'Pigment Blue 61'],
    ];

    /** UV monomers & oligomers (CAS, name, VOC%) */
    private const UV_MONOMERS = [
        ['57472-68-1', 'Dipropylene Glycol Diacrylate',            0.5],
        ['15625-89-5', 'Trimethylolpropane Triacrylate',           0.3],
        ['42978-66-5', 'Dipentaerythritol Hexaacrylate',           0.1],
        ['4986-89-4',  'Pentaerythritol Tetraacrylate',            0.2],
        ['28961-43-5', 'Trimethylolpropane Ethoxylate Triacrylate', 0.4],
        ['13048-33-4', 'Hexanediol Diacrylate',                    0.8],
        ['1680-21-3',  'Neopentyl Glycol Diacrylate',              0.6],
    ];

    /** UV oligomers / resins */
    private const UV_RESINS = [
        ['39394-59-9', 'Epoxy Acrylate Resin',        0.2],
        ['52404-36-3', 'Polyester Acrylate Resin',     0.1],
        ['58504-66-2', 'Urethane Acrylate Oligomer',   0.3],
        ['68551-17-7', 'Polyether Acrylate Resin',     0.2],
    ];

    /** Photoinitiators (CAS, name) */
    private const PHOTOINITIATORS = [
        ['75980-60-8', 'Diphenyl(2,4,6-trimethylbenzoyl)phosphine Oxide (TPO)'],
        ['947-19-3',   'Ethyl (2,4,6-trimethylbenzoyl) Phenylphosphinate (TPO-L)'],
        ['7473-98-5',  '2-Hydroxy-2-methylpropiophenone (Darocur 1173)'],
        ['10287-53-3', '1-Hydroxycyclohexyl Phenyl Ketone (Irgacure 184)'],
        ['71868-10-5', '2-Methyl-4\'-(methylthio)-2-morpholinopropiophenone (Irgacure 907)'],
        ['106797-53-9','2,4,6-Trimethylbenzoyldiphenylphosphine Oxide'],
    ];

    /** Solvents (CAS, name, VOC%) */
    private const SOLVENTS = [
        ['67-63-0',   'Isopropyl Alcohol',              100.0],
        ['141-78-6',  'Ethyl Acetate',                  100.0],
        ['108-88-3',  'Toluene',                        100.0],
        ['64-17-5',   'Ethanol',                        100.0],
        ['71-36-3',   'n-Butanol',                      100.0],
        ['108-65-6',  'Propylene Glycol Methyl Ether Acetate', 100.0],
        ['34590-94-8','Dipropylene Glycol Methyl Ether', 100.0],
        ['111-76-2',  '2-Butoxyethanol',                100.0],
        ['67-56-1',   'Methanol',                       100.0],
        ['110-80-5',  '2-Ethoxyethanol',                100.0],
    ];

    /** Aqueous components (CAS, name) */
    private const AQUEOUS = [
        ['7732-18-5', 'Water'],
        ['57-55-6',   'Propylene Glycol'],
        ['9002-93-1', 'Polyoxyethylene Octyl Phenyl Ether'],
    ];

    /** Additives (CAS, name) */
    private const ADDITIVES = [
        ['112-80-1',  'Oleic Acid'],
        ['63148-62-9','Polydimethylsiloxane'],
        ['8042-47-5', 'Mineral Oil'],
        ['9003-11-6', 'Poloxamer'],
        ['9004-99-3', 'Polyethylene Glycol Monostearate'],
        ['13586-84-0','Polytetrafluoroethylene (micronized)'],
        ['9004-34-6', 'Microcrystalline Cellulose'],
        ['112945-52-5','Fumed Silica'],
        ['115-10-6',  'Dimethyl Ether'],
        ['110-44-1',  'Sorbic Acid'],
    ];

    /** Solvent-ink resins */
    private const SOLVENT_RESINS = [
        ['63428-84-2', 'Polyamide Resin'],
        ['9003-39-8',  'Polyvinylpyrrolidone'],
        ['9011-14-7',  'Poly(methyl methacrylate)'],
        ['8050-09-7',  'Rosin (Colophony)'],
        ['68648-89-5', 'Modified Rosin Ester'],
        ['65997-04-8', 'Nitrocellulose'],
    ];

    /** Aqueous resins */
    private const AQUEOUS_RESINS = [
        ['9003-01-4', 'Polyacrylic Acid'],
        ['25036-16-2','Styrene-Acrylic Copolymer'],
        ['9005-25-8', 'Starch'],
        ['9004-32-4', 'Carboxymethyl Cellulose'],
    ];

    /** Suppliers */
    private const SUPPLIERS = [
        'BASF', 'Sun Chemical', 'Allnex', 'IGM Resins', 'Dow Chemical',
        'Evonik', 'Clariant', 'DIC Corporation', 'Siegwerk', 'Toyo Ink',
        'Altana', 'Flint Group', 'Zeller+Gmelin', 'Huber Engineered Materials',
        'Cabot Corporation', 'Kronos Worldwide', 'Lanxess', 'Arkema',
    ];

    /** Customer names for shipping data */
    private const CUSTOMERS = [
        'ABC Packaging Corp',
        'National Label Co',
        'Premier Print Solutions',
        'Pacific Coast Containers',
        'Midwest Folding Cartons',
        'Atlantic Flexible Packaging',
        'Delta Graphics Inc',
        'Sterling Label & Packaging',
        'Heartland Printing Co',
        'Summit Packaging Group',
        'Cascade Corrugated',
        'Liberty Press Inc',
        'Pinnacle Packaging',
        'Horizon Label Corp',
        'Keystone Flexo',
    ];

    /** Ship-to codes matching customer names */
    private const CUSTOMER_CODES = [
        'ABCPKG', 'NATLBL', 'PREMPR', 'PACCST', 'MWFOLD',
        'ATLFLX', 'DELGFX', 'STRLBL', 'HTLPRT', 'SUMPKG',
        'CASCRG', 'LIBPRS', 'PINPKG', 'HRZLBL', 'KEYFLX',
    ];

    /** Pack extensions for item names */
    private const PACK_EXTENSIONS = [
        '5', '10', '25', '50', '55', '275', '1G', '5G', '55G', '1D', '5D',
    ];

    /* ------------------------------------------------------------------
     *  Public API
     * ----------------------------------------------------------------*/

    /**
     * Generate all training data. Returns summary counts.
     *
     * @param int $userId  The admin user ID for created_by fields.
     * @return array{raw_materials: int, finished_goods: int, formulas: int}
     */
    public static function generate(int $userId): array
    {
        $db = Database::getInstance();
        $service = new self();

        // Step 1: Create ~100 raw materials with constituents
        $rmIds = $service->createRawMaterials($db, $userId);

        // Step 2: Create ~500 finished goods with formulas
        $fgCount = $service->createFinishedGoods($db, $userId, $rmIds);

        return [
            'raw_materials'  => count($rmIds),
            'finished_goods' => $fgCount['goods'],
            'formulas'       => $fgCount['formulas'],
        ];
    }

    /**
     * Generate alias CSV content (Item Name → Description mapping).
     * Uses the current finished goods in the database.
     *
     * @return string CSV content
     */
    public static function generateAliasCsv(): string
    {
        $db = Database::getInstance();
        $goods = $db->fetchAll("SELECT product_code, description FROM finished_goods ORDER BY product_code");

        $output = fopen('php://temp', 'r+');
        fputcsv($output, ['Item Name', 'Description']);

        foreach ($goods as $fg) {
            // Each product gets 2-3 pack sizes as separate item names
            $packSizes = self::pickRandom(self::PACK_EXTENSIONS, mt_rand(2, 3));
            foreach ($packSizes as $pack) {
                fputcsv($output, [
                    $fg['product_code'] . '-' . $pack,
                    $fg['description'],
                ]);
            }
        }

        rewind($output);
        $csv = stream_get_contents($output);
        fclose($output);
        return $csv;
    }

    /**
     * Generate shipping detail CSV content.
     * Uses the current finished goods in the database.
     *
     * @return string CSV content
     */
    public static function generateShippingCsv(): string
    {
        $db = Database::getInstance();
        $goods = $db->fetchAll("SELECT product_code FROM finished_goods ORDER BY product_code");

        if (empty($goods)) {
            $output = fopen('php://temp', 'r+');
            fputcsv($output, ['Bill To', 'Ship To', 'Ship To Name', 'Date Shipped', 'Item Name', 'Qty Shipped']);
            rewind($output);
            $csv = stream_get_contents($output);
            fclose($output);
            return $csv;
        }

        $output = fopen('php://temp', 'r+');
        fputcsv($output, ['Bill To', 'Ship To', 'Ship To Name', 'Date Shipped', 'Item Name', 'Qty Shipped']);

        // Generate ~1500 shipping records across the last 12 months
        $now = time();
        $yearAgo = strtotime('-12 months');

        for ($i = 0; $i < 1500; $i++) {
            $custIdx = mt_rand(0, count(self::CUSTOMERS) - 1);
            $fg = $goods[mt_rand(0, count($goods) - 1)];
            $pack = self::PACK_EXTENSIONS[mt_rand(0, count(self::PACK_EXTENSIONS) - 1)];
            $shipDate = date('m/d/Y', mt_rand($yearAgo, $now));
            $qty = self::randomQty();

            fputcsv($output, [
                self::CUSTOMER_CODES[$custIdx],
                self::CUSTOMER_CODES[$custIdx],
                self::CUSTOMERS[$custIdx],
                $shipDate,
                $fg['product_code'] . '-' . $pack,
                $qty,
            ]);
        }

        rewind($output);
        $csv = stream_get_contents($output);
        fclose($output);
        return $csv;
    }

    /* ------------------------------------------------------------------
     *  Private: raw material generation
     * ----------------------------------------------------------------*/

    /**
     * @return int[]  Array of raw material IDs created.
     */
    private function createRawMaterials(Database $db, int $userId): array
    {
        $ids = [];
        $counter = 1;

        // Pigment raw materials (~20)
        foreach (self::PIGMENTS as $pig) {
            $code = sprintf('RM-%03d', $counter++);
            $supplier = self::SUPPLIERS[mt_rand(0, count(self::SUPPLIERS) - 1)];
            $flushPct = mt_rand(40, 65);

            $id = (int) $db->insert('raw_materials', [
                'internal_code'         => $code,
                'supplier'              => $supplier,
                'supplier_product_name' => $pig[1] . " Flush {$flushPct}%",
                'voc_wt'                => 0.0,
                'specific_gravity'      => round(mt_rand(110, 160) / 100, 3),
                'flash_point_c'         => null,
                'physical_state'        => mt_rand(0, 1) ? 'Paste' : 'Powder',
                'appearance'            => self::randomAppearance($pig[1]),
                'odor'                  => 'Slight',
                'created_by'            => $userId,
            ]);

            // Constituents: pigment + carrier
            $carrierPct = 100.0 - $flushPct;
            $db->insert('raw_material_constituents', [
                'raw_material_id' => $id,
                'cas_number'      => $pig[0],
                'chemical_name'   => $pig[1],
                'pct_min'         => (float) $flushPct - 5,
                'pct_max'         => (float) $flushPct + 5,
                'sort_order'      => 1,
            ]);
            $db->insert('raw_material_constituents', [
                'raw_material_id' => $id,
                'cas_number'      => '64742-48-9',
                'chemical_name'   => 'Petroleum Distillates',
                'pct_min'         => $carrierPct - 5,
                'pct_max'         => $carrierPct + 5,
                'is_trade_secret' => 1,
                'sort_order'      => 2,
            ]);

            $ids[] = $id;
        }

        // UV monomer raw materials (~7)
        foreach (self::UV_MONOMERS as $mon) {
            $code = sprintf('RM-%03d', $counter++);
            $supplier = self::SUPPLIERS[mt_rand(0, count(self::SUPPLIERS) - 1)];

            $id = (int) $db->insert('raw_materials', [
                'internal_code'         => $code,
                'supplier'              => $supplier,
                'supplier_product_name' => $mon[1],
                'voc_wt'                => $mon[2],
                'specific_gravity'      => round(mt_rand(100, 120) / 100, 3),
                'flash_point_c'         => round(mt_rand(80, 140) + mt_rand(0, 9) / 10, 1),
                'physical_state'        => 'Liquid',
                'appearance'            => 'Clear, colorless to light yellow',
                'odor'                  => 'Mild acrylic',
                'created_by'            => $userId,
            ]);

            $db->insert('raw_material_constituents', [
                'raw_material_id' => $id,
                'cas_number'      => $mon[0],
                'chemical_name'   => $mon[1],
                'pct_min'         => 95.0,
                'pct_max'         => 100.0,
                'sort_order'      => 1,
            ]);

            $ids[] = $id;
        }

        // UV resin raw materials (~4)
        foreach (self::UV_RESINS as $res) {
            $code = sprintf('RM-%03d', $counter++);
            $supplier = self::SUPPLIERS[mt_rand(0, count(self::SUPPLIERS) - 1)];

            $id = (int) $db->insert('raw_materials', [
                'internal_code'         => $code,
                'supplier'              => $supplier,
                'supplier_product_name' => $res[1],
                'voc_wt'                => $res[2],
                'specific_gravity'      => round(mt_rand(110, 130) / 100, 3),
                'flash_point_c'         => round(mt_rand(100, 180) + mt_rand(0, 9) / 10, 1),
                'physical_state'        => 'Liquid',
                'appearance'            => 'Viscous, amber to light yellow',
                'odor'                  => 'Mild',
                'created_by'            => $userId,
            ]);

            $db->insert('raw_material_constituents', [
                'raw_material_id' => $id,
                'cas_number'      => $res[0],
                'chemical_name'   => $res[1],
                'pct_min'         => 90.0,
                'pct_max'         => 100.0,
                'sort_order'      => 1,
            ]);

            $ids[] = $id;
        }

        // Photoinitiator raw materials (~6)
        foreach (self::PHOTOINITIATORS as $pi) {
            $code = sprintf('RM-%03d', $counter++);
            $supplier = self::SUPPLIERS[mt_rand(0, count(self::SUPPLIERS) - 1)];

            $id = (int) $db->insert('raw_materials', [
                'internal_code'         => $code,
                'supplier'              => $supplier,
                'supplier_product_name' => $pi[1],
                'voc_wt'                => 0.0,
                'specific_gravity'      => round(mt_rand(110, 140) / 100, 3),
                'physical_state'        => mt_rand(0, 1) ? 'Solid' : 'Liquid',
                'appearance'            => 'White to yellow',
                'odor'                  => 'Odorless',
                'created_by'            => $userId,
            ]);

            $db->insert('raw_material_constituents', [
                'raw_material_id' => $id,
                'cas_number'      => $pi[0],
                'chemical_name'   => $pi[1],
                'pct_min'         => 97.0,
                'pct_max'         => 100.0,
                'sort_order'      => 1,
            ]);

            $ids[] = $id;
        }

        // Solvent raw materials (~10)
        foreach (self::SOLVENTS as $sol) {
            $code = sprintf('RM-%03d', $counter++);
            $supplier = self::SUPPLIERS[mt_rand(0, count(self::SUPPLIERS) - 1)];

            $id = (int) $db->insert('raw_materials', [
                'internal_code'         => $code,
                'supplier'              => $supplier,
                'supplier_product_name' => $sol[1] . ' 99%',
                'voc_wt'                => $sol[2],
                'specific_gravity'      => round(mt_rand(700, 1050) / 1000, 3),
                'flash_point_c'         => round(mt_rand(-10, 60) + mt_rand(0, 9) / 10, 1),
                'physical_state'        => 'Liquid',
                'appearance'            => 'Clear, colorless',
                'odor'                  => 'Strong, characteristic',
                'created_by'            => $userId,
            ]);

            $db->insert('raw_material_constituents', [
                'raw_material_id' => $id,
                'cas_number'      => $sol[0],
                'chemical_name'   => $sol[1],
                'pct_min'         => 99.0,
                'pct_max'         => 100.0,
                'sort_order'      => 1,
            ]);
            $db->insert('raw_material_constituents', [
                'raw_material_id' => $id,
                'cas_number'      => '7732-18-5',
                'chemical_name'   => 'Water',
                'pct_exact'       => 0.5,
                'sort_order'      => 2,
            ]);

            $ids[] = $id;
        }

        // Additive raw materials (~10)
        foreach (self::ADDITIVES as $add) {
            $code = sprintf('RM-%03d', $counter++);
            $supplier = self::SUPPLIERS[mt_rand(0, count(self::SUPPLIERS) - 1)];

            $id = (int) $db->insert('raw_materials', [
                'internal_code'         => $code,
                'supplier'              => $supplier,
                'supplier_product_name' => $add[1],
                'voc_wt'                => 0.0,
                'specific_gravity'      => round(mt_rand(90, 200) / 100, 3),
                'physical_state'        => mt_rand(0, 2) === 0 ? 'Solid' : 'Liquid',
                'appearance'            => mt_rand(0, 1) ? 'White powder' : 'Clear liquid',
                'odor'                  => 'Mild',
                'created_by'            => $userId,
            ]);

            $db->insert('raw_material_constituents', [
                'raw_material_id' => $id,
                'cas_number'      => $add[0],
                'chemical_name'   => $add[1],
                'pct_min'         => 95.0,
                'pct_max'         => 100.0,
                'sort_order'      => 1,
            ]);

            $ids[] = $id;
        }

        // Solvent-ink resins (~6)
        foreach (self::SOLVENT_RESINS as $res) {
            $code = sprintf('RM-%03d', $counter++);
            $supplier = self::SUPPLIERS[mt_rand(0, count(self::SUPPLIERS) - 1)];

            $id = (int) $db->insert('raw_materials', [
                'internal_code'         => $code,
                'supplier'              => $supplier,
                'supplier_product_name' => $res[1],
                'voc_wt'                => round(mt_rand(0, 30) / 10, 1),
                'specific_gravity'      => round(mt_rand(100, 130) / 100, 3),
                'flash_point_c'         => round(mt_rand(40, 120) + mt_rand(0, 9) / 10, 1),
                'physical_state'        => 'Solid',
                'appearance'            => 'Amber to brown flakes/pellets',
                'odor'                  => 'Mild resinous',
                'created_by'            => $userId,
            ]);

            $db->insert('raw_material_constituents', [
                'raw_material_id' => $id,
                'cas_number'      => $res[0],
                'chemical_name'   => $res[1],
                'pct_min'         => 90.0,
                'pct_max'         => 100.0,
                'sort_order'      => 1,
            ]);

            $ids[] = $id;
        }

        // Aqueous components (~3) + Aqueous resins (~4)
        foreach (self::AQUEOUS as $aq) {
            $code = sprintf('RM-%03d', $counter++);
            $supplier = self::SUPPLIERS[mt_rand(0, count(self::SUPPLIERS) - 1)];

            $id = (int) $db->insert('raw_materials', [
                'internal_code'         => $code,
                'supplier'              => $supplier,
                'supplier_product_name' => $aq[1],
                'voc_wt'                => 0.0,
                'water_wt'              => $aq[0] === '7732-18-5' ? 100.0 : null,
                'specific_gravity'      => $aq[0] === '7732-18-5' ? 1.000 : round(mt_rand(100, 115) / 100, 3),
                'physical_state'        => 'Liquid',
                'appearance'            => 'Clear',
                'odor'                  => $aq[0] === '7732-18-5' ? 'Odorless' : 'Mild',
                'created_by'            => $userId,
            ]);

            $db->insert('raw_material_constituents', [
                'raw_material_id' => $id,
                'cas_number'      => $aq[0],
                'chemical_name'   => $aq[1],
                'pct_exact'       => 100.0,
                'sort_order'      => 1,
            ]);

            $ids[] = $id;
        }

        foreach (self::AQUEOUS_RESINS as $res) {
            $code = sprintf('RM-%03d', $counter++);
            $supplier = self::SUPPLIERS[mt_rand(0, count(self::SUPPLIERS) - 1)];

            $id = (int) $db->insert('raw_materials', [
                'internal_code'         => $code,
                'supplier'              => $supplier,
                'supplier_product_name' => $res[1],
                'voc_wt'                => 0.0,
                'specific_gravity'      => round(mt_rand(100, 120) / 100, 3),
                'physical_state'        => 'Liquid',
                'appearance'            => 'Milky white emulsion',
                'odor'                  => 'Mild',
                'created_by'            => $userId,
            ]);

            $db->insert('raw_material_constituents', [
                'raw_material_id' => $id,
                'cas_number'      => $res[0],
                'chemical_name'   => $res[1],
                'pct_min'         => 40.0,
                'pct_max'         => 55.0,
                'sort_order'      => 1,
            ]);
            $db->insert('raw_material_constituents', [
                'raw_material_id' => $id,
                'cas_number'      => '7732-18-5',
                'chemical_name'   => 'Water',
                'pct_min'         => 45.0,
                'pct_max'         => 60.0,
                'sort_order'      => 2,
            ]);

            $ids[] = $id;
        }

        return $ids;
    }

    /* ------------------------------------------------------------------
     *  Private: finished good + formula generation
     * ----------------------------------------------------------------*/

    /**
     * @return array{goods: int, formulas: int}
     */
    private function createFinishedGoods(Database $db, int $userId, array $rmIds): array
    {
        // Categorize raw materials by their type based on creation order
        $pigmentRms     = array_slice($rmIds, 0, 20);
        $uvMonomerRms   = array_slice($rmIds, 20, 7);
        $uvResinRms     = array_slice($rmIds, 27, 4);
        $piRms          = array_slice($rmIds, 31, 6);
        $solventRms     = array_slice($rmIds, 37, 10);
        $additiveRms    = array_slice($rmIds, 47, 10);
        $solventResinRms = array_slice($rmIds, 57, 6);
        $aqueousRms     = array_slice($rmIds, 63, 3);
        $aqueousResinRms = array_slice($rmIds, 66, 4);

        $goodsCount = 0;
        $formulaCount = 0;
        $productCounter = 1;

        foreach (self::FAMILIES as $family) {
            $prefixes = self::FAMILY_PREFIXES[$family];
            $colorCount = mt_rand(18, 25);
            $selectedColors = self::pickRandom(self::COLORS, min($colorCount, count(self::COLORS)));

            foreach ($selectedColors as $colorIdx => $color) {
                $prefix = $prefixes[mt_rand(0, count($prefixes) - 1)];
                $productCode = sprintf('%s%04d', $prefix, $productCounter++);
                $description = "{$family} {$color}";

                $fgId = (int) $db->insert('finished_goods', [
                    'product_code'    => $productCode,
                    'description'     => $description,
                    'family'          => $family,
                    'is_active'       => 1,
                    'created_by'      => $userId,
                ]);
                $goodsCount++;

                // Build a formula based on the family type
                $lines = $this->buildFormula(
                    $family, $color,
                    $pigmentRms, $uvMonomerRms, $uvResinRms, $piRms,
                    $solventRms, $additiveRms, $solventResinRms,
                    $aqueousRms, $aqueousResinRms
                );

                // Insert formula
                $formulaId = (int) $db->insert('formulas', [
                    'finished_good_id' => $fgId,
                    'version'          => 1,
                    'is_current'       => 1,
                    'notes'            => 'Training data formula',
                    'created_by'       => $userId,
                ]);
                $formulaCount++;

                foreach ($lines as $i => $line) {
                    $db->insert('formula_lines', [
                        'formula_id'      => $formulaId,
                        'raw_material_id' => $line['rm_id'],
                        'pct'             => $line['pct'],
                        'sort_order'      => $i + 1,
                    ]);
                }
            }
        }

        return ['goods' => $goodsCount, 'formulas' => $formulaCount];
    }

    /**
     * Build formula lines for a finished good based on its family type.
     * Returns array of ['rm_id' => int, 'pct' => float] that sums to 100%.
     */
    private function buildFormula(
        string $family, string $color,
        array $pigmentRms, array $uvMonomerRms, array $uvResinRms, array $piRms,
        array $solventRms, array $additiveRms, array $solventResinRms,
        array $aqueousRms, array $aqueousResinRms
    ): array {
        $lines = [];

        $isVarnish = stripos($color, 'Varnish') !== false || stripos($color, 'Clear') !== false;

        switch ($family) {
            case 'UV Offset':
            case 'UV Flexo':
            case 'EB Cure':
                // Pigment (15-35%) unless varnish
                if (!$isVarnish) {
                    $pigPct = round(mt_rand(15, 35) + mt_rand(0, 99) / 100, 2);
                    $lines[] = ['rm_id' => $pigmentRms[mt_rand(0, count($pigmentRms) - 1)], 'pct' => $pigPct];
                }

                // UV monomers (25-45%)
                $monomerCount = mt_rand(1, 3);
                $monomerPct = round(mt_rand(25, 45) + mt_rand(0, 99) / 100, 2);
                $monomerRmsSelected = self::pickRandom($uvMonomerRms, $monomerCount);
                $perMonomer = round($monomerPct / $monomerCount, 2);
                foreach ($monomerRmsSelected as $j => $mId) {
                    $pct = ($j === $monomerCount - 1) ? round($monomerPct - $perMonomer * ($monomerCount - 1), 2) : $perMonomer;
                    $lines[] = ['rm_id' => $mId, 'pct' => $pct];
                }

                // UV resin (10-25%)
                $resinPct = round(mt_rand(10, 25) + mt_rand(0, 99) / 100, 2);
                $lines[] = ['rm_id' => $uvResinRms[mt_rand(0, count($uvResinRms) - 1)], 'pct' => $resinPct];

                // Photoinitiator (3-8%)
                $piPct = round(mt_rand(3, 8) + mt_rand(0, 99) / 100, 2);
                $lines[] = ['rm_id' => $piRms[mt_rand(0, count($piRms) - 1)], 'pct' => $piPct];

                // Additive (remainder)
                $addPct = round(100.0 - array_sum(array_column($lines, 'pct')), 2);
                if ($addPct > 0) {
                    $lines[] = ['rm_id' => $additiveRms[mt_rand(0, count($additiveRms) - 1)], 'pct' => $addPct];
                }
                break;

            case 'Solvent':
                // Pigment (10-25%) unless varnish
                if (!$isVarnish) {
                    $pigPct = round(mt_rand(10, 25) + mt_rand(0, 99) / 100, 2);
                    $lines[] = ['rm_id' => $pigmentRms[mt_rand(0, count($pigmentRms) - 1)], 'pct' => $pigPct];
                }

                // Resin (20-35%)
                $resinPct = round(mt_rand(20, 35) + mt_rand(0, 99) / 100, 2);
                $lines[] = ['rm_id' => $solventResinRms[mt_rand(0, count($solventResinRms) - 1)], 'pct' => $resinPct];

                // Solvents (30-50%)
                $solventCount = mt_rand(1, 2);
                $solventPct = round(mt_rand(30, 50) + mt_rand(0, 99) / 100, 2);
                $solventRmsSelected = self::pickRandom($solventRms, $solventCount);
                $perSolvent = round($solventPct / $solventCount, 2);
                foreach ($solventRmsSelected as $j => $sId) {
                    $pct = ($j === $solventCount - 1) ? round($solventPct - $perSolvent * ($solventCount - 1), 2) : $perSolvent;
                    $lines[] = ['rm_id' => $sId, 'pct' => $pct];
                }

                // Additive (remainder)
                $addPct = round(100.0 - array_sum(array_column($lines, 'pct')), 2);
                if ($addPct > 0) {
                    $lines[] = ['rm_id' => $additiveRms[mt_rand(0, count($additiveRms) - 1)], 'pct' => $addPct];
                }
                break;

            case 'Aqueous':
                // Pigment (10-20%) unless varnish
                if (!$isVarnish) {
                    $pigPct = round(mt_rand(10, 20) + mt_rand(0, 99) / 100, 2);
                    $lines[] = ['rm_id' => $pigmentRms[mt_rand(0, count($pigmentRms) - 1)], 'pct' => $pigPct];
                }

                // Aqueous resin (15-30%)
                $resinPct = round(mt_rand(15, 30) + mt_rand(0, 99) / 100, 2);
                $lines[] = ['rm_id' => $aqueousResinRms[mt_rand(0, count($aqueousResinRms) - 1)], 'pct' => $resinPct];

                // Water (40-60%)
                $waterRm = $aqueousRms[0]; // First aqueous RM is Water
                $waterPct = round(mt_rand(40, 60) + mt_rand(0, 99) / 100, 2);
                $lines[] = ['rm_id' => $waterRm, 'pct' => $waterPct];

                // Additive (remainder)
                $addPct = round(100.0 - array_sum(array_column($lines, 'pct')), 2);
                if ($addPct > 0) {
                    $lines[] = ['rm_id' => $additiveRms[mt_rand(0, count($additiveRms) - 1)], 'pct' => $addPct];
                }
                break;
        }

        // Ensure lines sum to exactly 100%
        $lines = $this->normalizeToHundred($lines);

        return $lines;
    }

    /**
     * Adjust the last line so all lines sum to exactly 100.00%.
     */
    private function normalizeToHundred(array $lines): array
    {
        if (empty($lines)) {
            return $lines;
        }

        $total = 0.0;
        foreach ($lines as $line) {
            $total += $line['pct'];
        }

        $diff = round(100.0 - $total, 2);
        if ($diff !== 0.0) {
            $lastIdx = count($lines) - 1;
            $lines[$lastIdx]['pct'] = round($lines[$lastIdx]['pct'] + $diff, 2);
            // If the adjustment made the last line <= 0, distribute differently
            if ($lines[$lastIdx]['pct'] <= 0) {
                $lines[$lastIdx]['pct'] = 0.01;
                // Re-normalize the rest
                $remaining = 99.99;
                $otherTotal = 0;
                for ($i = 0; $i < $lastIdx; $i++) {
                    $otherTotal += $lines[$i]['pct'];
                }
                if ($otherTotal > 0) {
                    for ($i = 0; $i < $lastIdx; $i++) {
                        $lines[$i]['pct'] = round(($lines[$i]['pct'] / $otherTotal) * $remaining, 2);
                    }
                }
                // Final fix
                $finalTotal = array_sum(array_column($lines, 'pct'));
                $lines[$lastIdx]['pct'] = round($lines[$lastIdx]['pct'] + (100.0 - $finalTotal), 2);
            }
        }

        return $lines;
    }

    /* ------------------------------------------------------------------
     *  Utility helpers
     * ----------------------------------------------------------------*/

    /**
     * Pick $count unique random elements from $array.
     */
    private static function pickRandom(array $array, int $count): array
    {
        if ($count >= count($array)) {
            return $array;
        }
        $keys = (array) array_rand($array, $count);
        $result = [];
        foreach ($keys as $key) {
            $result[] = $array[$key];
        }
        return $result;
    }

    private static function randomAppearance(string $pigmentName): string
    {
        $name = strtolower($pigmentName);
        if (str_contains($name, 'yellow')) return 'Yellow paste';
        if (str_contains($name, 'red') || str_contains($name, 'rubine')) return 'Red paste';
        if (str_contains($name, 'magenta')) return 'Magenta paste';
        if (str_contains($name, 'blue')) return 'Blue paste';
        if (str_contains($name, 'green')) return 'Green paste';
        if (str_contains($name, 'orange')) return 'Orange paste';
        if (str_contains($name, 'violet') || str_contains($name, 'purple')) return 'Purple paste';
        if (str_contains($name, 'black') || str_contains($name, 'carbon')) return 'Black powder';
        if (str_contains($name, 'titanium') || str_contains($name, 'white')) return 'White powder';
        if (str_contains($name, 'iron')) return 'Red-brown powder';
        return 'Colored paste';
    }

    private static function randomQty(): float
    {
        // Realistic shipping quantities in lbs
        $options = [50, 100, 200, 250, 500, 550, 1000, 2000, 2500, 5000];
        return (float) $options[mt_rand(0, count($options) - 1)];
    }
}
