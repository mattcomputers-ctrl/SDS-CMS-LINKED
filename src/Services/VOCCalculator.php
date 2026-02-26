<?php

declare(strict_types=1);

namespace SDS\Services;

/**
 * VOCCalculator -- EPA Method 24 style VOC calculations for ink/coatings.
 *
 * Computes VOC content in lb/gal (both as-is and less-water-and-exempt),
 * mixture specific gravity, solids percentages, and tracks every assumption
 * for full audit traceability.
 *
 * Reference: 40 CFR Part 60, Appendix A, Method 24
 * NAPIM (National Association of Printing Ink Manufacturers) defaults apply
 * for missing data on pigments, oligomers, and resins.
 */
class VOCCalculator
{
    /* ------------------------------------------------------------------
     *  Constants
     * ----------------------------------------------------------------*/

    /** Density of water at 25 C in lb/gal (US liquid gallon). */
    public const WATER_DENSITY_LB_GAL = 8.345;

    /** Conversion factor: pounds per kilogram. */
    public const LB_PER_KG = 2.20462;

    /** Default specific gravity when none is provided. */
    private const DEFAULT_SG = 1.0;

    /** CAS number for water. */
    private const CAS_WATER = '7732-18-5';

    /* ------------------------------------------------------------------
     *  Properties
     * ----------------------------------------------------------------*/

    /**
     * Array of formula lines. Each element must contain:
     *   - raw_material_id   (int)
     *   - internal_code     (string)
     *   - supplier_product_name (string)
     *   - pct               (float)   weight percent in formula (0-100)
     *   - voc_wt            (float|null) VOC wt% of this raw material
     *   - exempt_voc_wt     (float|null) exempt VOC wt%
     *   - water_wt          (float|null) water wt%
     *   - specific_gravity  (float|null)
     *   - solids_wt         (float|null) solids wt%
     *   - solids_vol        (float|null) solids vol%
     *   - flash_point_c     (float|null)
     *   - constituents      (array) each: [cas_number, chemical_name, pct_exact, pct_min, pct_max]
     *
     * @var array
     */
    private array $formulaLines;

    /**
     * Calculation mode.
     *   'method24_standard'            -- VOC lb/gal of coating as-is
     *   'method24_less_water_exempt'   -- VOC lb/gal less water and exempt solvents
     *
     * @var string
     */
    private string $calcMode;

    /** Running list of assumptions made during calculation. */
    private array $assumptions = [];

    /** Full structured trace for audit. */
    private array $trace = [];

    /** Cached results after calculate() runs. */
    private ?array $results = null;

    /* ------------------------------------------------------------------
     *  Constructor
     * ----------------------------------------------------------------*/

    /**
     * @param array  $formulaLines  See property doc above.
     * @param string $calcMode      'method24_standard' or 'method24_less_water_exempt'
     */
    public function __construct(array $formulaLines, string $calcMode = 'method24_standard')
    {
        $this->formulaLines = $formulaLines;
        $this->calcMode     = $calcMode;
    }

    /* ------------------------------------------------------------------
     *  Main entry point
     * ----------------------------------------------------------------*/

    /**
     * Run the full calculation and return all computed values.
     *
     * @return array {
     *   total_voc_wt_pct:           float,
     *   total_exempt_voc_wt_pct:    float,
     *   total_water_wt_pct:         float,
     *   mixture_sg:                 float,
     *   voc_lb_per_gal:             float,
     *   voc_lb_per_gal_less_water_exempt: float,
     *   solids_wt_pct:              float,
     *   solids_vol_pct:             float|null,
     *   calc_mode:                  string,
     *   assumptions:                array,
     *   trace:                      array,
     * }
     */
    public function calculate(): array
    {
        $this->assumptions = [];
        $this->trace       = [];

        $this->traceStep('start', 'Beginning VOC calculation', [
            'calc_mode'   => $this->calcMode,
            'line_count'  => count($this->formulaLines),
        ]);

        // Validate that formula percentages sum to ~100
        $totalFormulaPct = 0.0;
        foreach ($this->formulaLines as $line) {
            $totalFormulaPct += (float) ($line['pct'] ?? 0);
        }
        if (abs($totalFormulaPct - 100.0) > 0.5) {
            $this->traceStep('warning', 'Formula weight percentages sum to ' . round($totalFormulaPct, 4) . '%, expected ~100%', [
                'total_formula_pct' => $totalFormulaPct,
            ]);
        }

        // Apply NAPIM defaults where data is missing
        $this->applyDefaults();

        $totalVOCWtPct         = $this->getTotalVOCWeightPercent();
        $totalExemptVOCWtPct   = $this->getTotalExemptVOCWeightPercent();
        $totalWaterWtPct       = $this->getTotalWaterWeightPercent();
        $mixtureSG             = $this->getMixtureSG();
        $vocLbPerGal           = $this->getVOCLbPerGal();
        $vocLbPerGalLessWE     = $this->getVOCLbPerGalLessWaterExempt();
        $solidsWtPct           = $this->getSolidsWeightPercent();
        $solidsVolPct          = $this->getSolidsVolumePercent();

        $this->traceStep('complete', 'VOC calculation completed', [
            'total_voc_wt_pct'                  => $totalVOCWtPct,
            'total_exempt_voc_wt_pct'           => $totalExemptVOCWtPct,
            'total_water_wt_pct'                => $totalWaterWtPct,
            'mixture_sg'                        => $mixtureSG,
            'voc_lb_per_gal'                    => $vocLbPerGal,
            'voc_lb_per_gal_less_water_exempt'  => $vocLbPerGalLessWE,
            'solids_wt_pct'                     => $solidsWtPct,
            'solids_vol_pct'                    => $solidsVolPct,
        ]);

        $this->results = [
            'total_voc_wt_pct'                  => round($totalVOCWtPct, 4),
            'total_exempt_voc_wt_pct'           => round($totalExemptVOCWtPct, 4),
            'total_water_wt_pct'                => round($totalWaterWtPct, 4),
            'mixture_sg'                        => round($mixtureSG, 5),
            'voc_lb_per_gal'                    => round($vocLbPerGal, 4),
            'voc_lb_per_gal_less_water_exempt'  => round($vocLbPerGalLessWE, 4),
            'solids_wt_pct'                     => round($solidsWtPct, 4),
            'solids_vol_pct'                    => $solidsVolPct !== null ? round($solidsVolPct, 4) : null,
            'calc_mode'                         => $this->calcMode,
            'assumptions'                       => $this->assumptions,
            'trace'                             => $this->trace,
        ];

        return $this->results;
    }

    /* ------------------------------------------------------------------
     *  Public calculation methods
     * ----------------------------------------------------------------*/

    /**
     * Weighted average VOC weight percent of the mixture.
     * mixture_voc_wt% = SUM(line_pct/100 * rm_voc_wt%) for each line
     */
    public function getTotalVOCWeightPercent(): float
    {
        $vocWtPct = 0.0;
        foreach ($this->formulaLines as $line) {
            $lineFraction = ((float) ($line['pct'] ?? 0)) / 100.0;
            $rmVocWt      = (float) ($line['_effective_voc_wt'] ?? $line['voc_wt'] ?? 0);
            $vocWtPct    += $lineFraction * $rmVocWt;

            $this->traceStep('voc_wt_contribution', null, [
                'raw_material'  => $line['internal_code'] ?? $line['raw_material_id'] ?? '?',
                'line_pct'      => (float) ($line['pct'] ?? 0),
                'rm_voc_wt'     => $rmVocWt,
                'contribution'  => $lineFraction * $rmVocWt,
            ]);
        }
        return $vocWtPct;
    }

    /**
     * Weighted average exempt VOC weight percent of the mixture.
     */
    public function getTotalExemptVOCWeightPercent(): float
    {
        $exemptWtPct = 0.0;
        foreach ($this->formulaLines as $line) {
            $lineFraction = ((float) ($line['pct'] ?? 0)) / 100.0;
            $rmExemptVoc  = (float) ($line['_effective_exempt_voc_wt'] ?? $line['exempt_voc_wt'] ?? 0);
            $exemptWtPct += $lineFraction * $rmExemptVoc;
        }
        return $exemptWtPct;
    }

    /**
     * Weighted average water weight percent of the mixture.
     */
    public function getTotalWaterWeightPercent(): float
    {
        $waterWtPct = 0.0;
        foreach ($this->formulaLines as $line) {
            $lineFraction = ((float) ($line['pct'] ?? 0)) / 100.0;
            $rmWaterWt    = (float) ($line['_effective_water_wt'] ?? $line['water_wt'] ?? 0);
            $waterWtPct  += $lineFraction * $rmWaterWt;
        }
        return $waterWtPct;
    }

    /**
     * Weighted average specific gravity of the mixture.
     *
     * Uses volume-weighted blending:
     *   1/SG_mix = SUM( (Wi/100) / SGi ) where Wi = weight fraction
     *
     * For practical purposes in ink/coatings, we use weight-average SG:
     *   SG_mix = 1 / SUM( Wi / SGi )
     */
    public function getMixtureSG(): float
    {
        $sumWiOverSGi = 0.0;
        $totalWt      = 0.0;

        foreach ($this->formulaLines as $line) {
            $wi = (float) ($line['pct'] ?? 0);
            $sg = (float) ($line['_effective_sg'] ?? $line['specific_gravity'] ?? self::DEFAULT_SG);

            if ($sg <= 0) {
                $sg = self::DEFAULT_SG;
            }

            $sumWiOverSGi += $wi / $sg;
            $totalWt      += $wi;
        }

        if ($sumWiOverSGi <= 0 || $totalWt <= 0) {
            $this->traceStep('sg_error', 'Cannot compute mixture SG, returning default', []);
            return self::DEFAULT_SG;
        }

        $mixtureSG = $totalWt / $sumWiOverSGi;

        $this->traceStep('mixture_sg', 'Computed mixture specific gravity', [
            'mixture_sg'     => $mixtureSG,
            'sum_wi_over_sgi' => $sumWiOverSGi,
            'total_wt'       => $totalWt,
        ]);

        return $mixtureSG;
    }

    /**
     * Total VOC in lb/gal of coating.
     *
     * VOC (lb/gal) = VOC_wt_fraction x SG_mixture x 8.345
     *
     * 8.345 lb/gal is the density of water; multiplying SG by this
     * gives the mixture density in lb/gal.
     */
    public function getVOCLbPerGal(): float
    {
        $vocWtFraction = $this->getTotalVOCWeightPercent() / 100.0;
        $mixtureSG     = $this->getMixtureSG();
        $mixtureDensityLbGal = $mixtureSG * self::WATER_DENSITY_LB_GAL;

        $vocLbPerGal = $vocWtFraction * $mixtureDensityLbGal;

        $this->traceStep('voc_lb_per_gal', 'VOC lb/gal of coating', [
            'voc_wt_fraction'        => $vocWtFraction,
            'mixture_sg'             => $mixtureSG,
            'mixture_density_lb_gal' => $mixtureDensityLbGal,
            'voc_lb_per_gal'         => $vocLbPerGal,
            'formula'                => 'VOC_wt_fraction x SG x 8.345',
        ]);

        return $vocLbPerGal;
    }

    /**
     * VOC lb/gal on a less-water-and-exempt-solvent basis.
     *
     * EPA Method 24 formula:
     *   VOC (lb/gal less W&E) = (Wv) / (Vm - Vw - Ve)
     *
     * Where:
     *   Wv = weight of VOC per gallon of coating = VOC_wt% x mixture_density
     *   Vm = volume of coating = 1.0 gal (basis)
     *   Vw = volume of water in 1 gal coating
     *   Ve = volume of exempt solvent in 1 gal coating
     *
     * Working with a 1-gallon basis:
     *   Total coating weight (lb) = SG_mix * 8.345
     *   Wv (lb) = VOC_wt_fraction * total_weight
     *   Water weight (lb) = water_wt_fraction * total_weight
     *   Exempt weight (lb) = exempt_voc_wt_fraction * total_weight
     *   Vw (gal) = water_weight / (SG_water * 8.345) = water_weight / 8.345
     *   Ve (gal) = exempt_weight / (SG_exempt * 8.345)
     *      -- For exempt solvents, we approximate SG_exempt from constituent data
     *      -- or default to a typical exempt solvent SG.
     */
    public function getVOCLbPerGalLessWaterExempt(): float
    {
        $vocWtFraction     = $this->getTotalVOCWeightPercent() / 100.0;
        $waterWtFraction   = $this->getTotalWaterWeightPercent() / 100.0;
        $exemptWtFraction  = $this->getTotalExemptVOCWeightPercent() / 100.0;
        $mixtureSG         = $this->getMixtureSG();
        $mixtureDensityLbGal = $mixtureSG * self::WATER_DENSITY_LB_GAL;

        // Basis: 1 gallon of coating
        $totalWeightLb = $mixtureDensityLbGal; // lb per gallon

        // Weight of VOC in 1 gallon
        $wv = $vocWtFraction * $totalWeightLb;

        // Volume of coating basis
        $vm = 1.0; // gallon

        // Volume of water in 1 gallon of coating
        $waterWeightLb = $waterWtFraction * $totalWeightLb;
        $sgWater       = 1.0; // SG of water
        $vw            = $waterWeightLb / ($sgWater * self::WATER_DENSITY_LB_GAL);

        // Volume of exempt solvent in 1 gallon of coating
        // Use estimated SG for exempt solvents; common exempt solvents
        // (acetone CAS 67-64-1, t-butyl acetate CAS 540-88-5, PCBTF CAS 460-00-4)
        // have SG roughly 0.78 - 0.87. We estimate from the formula or default 0.80.
        $exemptSG = $this->estimateExemptSolventSG();
        $exemptWeightLb = $exemptWtFraction * $totalWeightLb;
        $ve = ($exemptSG > 0) ? $exemptWeightLb / ($exemptSG * self::WATER_DENSITY_LB_GAL) : 0.0;

        // Denominator: volume of coating less water and exempt
        $denominator = $vm - $vw - $ve;

        if ($denominator <= 0.001) {
            // Edge case: coating is essentially all water and exempt solvent
            $this->traceStep('voc_less_we_edge', 'Denominator <= 0; coating is essentially all water/exempt', [
                'vm' => $vm, 'vw' => $vw, 've' => $ve,
            ]);
            return 0.0;
        }

        $vocLbPerGalLessWE = $wv / $denominator;

        $this->traceStep('voc_lb_per_gal_less_we', 'VOC lb/gal less water and exempt', [
            'wv'               => $wv,
            'vm'               => $vm,
            'vw'               => $vw,
            've'               => $ve,
            'exempt_sg'        => $exemptSG,
            'denominator'      => $denominator,
            'voc_less_we'      => $vocLbPerGalLessWE,
            'formula'          => 'Wv / (Vm - Vw - Ve)',
        ]);

        return $vocLbPerGalLessWE;
    }

    /**
     * Weighted average solids by weight.
     */
    public function getSolidsWeightPercent(): float
    {
        $solidsWtPct = 0.0;
        foreach ($this->formulaLines as $line) {
            $lineFraction = ((float) ($line['pct'] ?? 0)) / 100.0;
            $rmSolidsWt   = (float) ($line['_effective_solids_wt'] ?? $line['solids_wt'] ?? 0);
            $solidsWtPct += $lineFraction * $rmSolidsWt;
        }
        return $solidsWtPct;
    }

    /**
     * Solids by volume.
     *
     * If all raw materials have solids_vol data, compute weighted volume-average.
     * Otherwise, estimate from solids weight and SG:
     *   Solids_vol% = (solids_wt% / SG_solids) / (100 / SG_mix) * 100
     *
     * For estimation, we assume SG_solids ~ SG_mix / (solids_wt/100) * (solids_vol/100)
     * but since we do not have SG_solids directly, we use a simpler approach:
     *   Solids_vol% approx = Solids_wt% * (SG_mix / SG_solids_est)
     *
     * If we cannot estimate, return null.
     */
    public function getSolidsVolumePercent(): ?float
    {
        // Try direct weighted average from raw material solids_vol data
        $allHaveSolidsVol = true;
        $solidsVolPct     = 0.0;

        foreach ($this->formulaLines as $line) {
            $rmSolidsVol = $line['solids_vol'] ?? null;
            if ($rmSolidsVol === null || $rmSolidsVol === '') {
                $allHaveSolidsVol = false;
                break;
            }
            $lineFraction = ((float) ($line['pct'] ?? 0)) / 100.0;
            $solidsVolPct += $lineFraction * (float) $rmSolidsVol;
        }

        if ($allHaveSolidsVol) {
            $this->traceStep('solids_vol_direct', 'Solids volume % computed from raw material data', [
                'solids_vol_pct' => $solidsVolPct,
            ]);
            return $solidsVolPct;
        }

        // Estimate from weight solids and SG
        // Approach: compute volumes of solid and total, assuming non-volatile
        // components have an average SG.
        //
        // Volume of 100 lb of mixture = 100 / (SG_mix * 8.345) gal [in lb-gal system]
        // Actually, in a dimensionless ratio:
        //   Vol_total = SUM( Wi / SGi )  (relative volumes)
        //   Vol_solids = SUM( Wi_solids / SGi_solids )
        //
        // We can compute this per-line if we know which portion is solids.
        // For each RM with solids_wt:
        //   RM contributes: line_weight = pct (out of 100 total)
        //   Solids portion weight = line_weight * solids_wt% / 100
        //   Solids volume = solids_weight / SG_solids
        //
        // SG_solids for each RM is unknown unless we have solids_vol.
        // For RMs that have both solids_wt and solids_vol, we can derive SG_solids.
        // For others, we approximate SG_solids = SG_rm (conservative).

        $totalVolume  = 0.0;
        $solidsVolume = 0.0;
        $canEstimate  = true;

        foreach ($this->formulaLines as $line) {
            $wi = (float) ($line['pct'] ?? 0);
            $sg = (float) ($line['_effective_sg'] ?? $line['specific_gravity'] ?? self::DEFAULT_SG);
            if ($sg <= 0) {
                $sg = self::DEFAULT_SG;
            }

            $totalVolume += $wi / $sg;

            $rmSolidsWt  = $line['_effective_solids_wt'] ?? $line['solids_wt'] ?? null;
            $rmSolidsVol = $line['solids_vol'] ?? null;

            if ($rmSolidsWt === null || $rmSolidsWt === '') {
                // Cannot estimate without solids weight data
                $canEstimate = false;
                break;
            }

            $solidsWeight = $wi * ((float) $rmSolidsWt) / 100.0;

            // Derive SG of solids from the RM if we have solids_vol
            if ($rmSolidsVol !== null && $rmSolidsVol !== '' && (float) $rmSolidsVol > 0) {
                // SG_solids = (solids_wt / solids_vol) * SG_rm
                // More precisely, if wt%_solids = 60 and vol%_solids = 40, and SG_rm = 1.2:
                //   total_vol = 100 / (SG_rm) = 100/1.2 = 83.33
                //   solids_vol = 40% of 83.33 = 33.33
                //   solids_wt = 60
                //   SG_solids = 60 / 33.33 = 1.8
                $totalVolumeRM = 100.0 / $sg; // volume of 100 wt units of this RM
                $solidsVolumeRM = ((float) $rmSolidsVol / 100.0) * $totalVolumeRM;
                if ($solidsVolumeRM > 0) {
                    $sgSolids = (float) $rmSolidsWt / $solidsVolumeRM;
                } else {
                    $sgSolids = $sg;
                }
            } else {
                // Approximate: solids SG roughly same as RM SG (conservative)
                $sgSolids = $sg;
                $this->addAssumption(
                    $line['internal_code'] ?? ('RM-' . ($line['raw_material_id'] ?? '?')),
                    $line['supplier_product_name'] ?? '',
                    'Solids SG assumed equal to RM SG (' . round($sg, 4) . ') for volume estimation'
                );
            }

            $solidsVolume += ($sgSolids > 0) ? $solidsWeight / $sgSolids : 0;
        }

        if (!$canEstimate || $totalVolume <= 0) {
            $this->traceStep('solids_vol_unavailable', 'Cannot estimate solids volume percent', []);
            return null;
        }

        $estimatedSolidsVol = ($solidsVolume / $totalVolume) * 100.0;

        $this->traceStep('solids_vol_estimated', 'Solids volume % estimated from weight solids and SG', [
            'total_volume'         => $totalVolume,
            'solids_volume'        => $solidsVolume,
            'estimated_solids_vol' => $estimatedSolidsVol,
        ]);

        $this->addAssumption('MIXTURE', '', 'Solids volume % estimated from weight data and SG; may differ from laboratory measurement');

        return $estimatedSolidsVol;
    }

    /**
     * Return all assumptions made during calculation.
     */
    public function getAssumptions(): array
    {
        return $this->assumptions;
    }

    /**
     * Return the full calculation trace for audit.
     */
    public function getTrace(): array
    {
        return $this->trace;
    }

    /* ------------------------------------------------------------------
     *  Private helpers
     * ----------------------------------------------------------------*/

    /**
     * Apply NAPIM-style defaults for missing data.
     *
     * - If voc_wt is NULL and constituents are all pigments/oligomers/resins
     *   (no known VOC CAS numbers), default VOC to 0% with a warning.
     * - If specific_gravity is NULL, default to 1.0 with a warning.
     * - If water_wt is NULL, check if CAS 7732-18-5 is in constituents.
     * - If solids_wt is NULL, estimate as 100 - voc_wt - exempt_voc_wt - water_wt.
     */
    private function applyDefaults(): void
    {
        // Known VOC CAS numbers that are common solvents
        // (a small representative set; in production, this would be looked up)
        $knownSolventCAS = [
            '67-56-1',    // Methanol
            '67-63-0',    // Isopropanol
            '64-17-5',    // Ethanol
            '71-43-2',    // Benzene
            '108-88-3',   // Toluene
            '1330-20-7',  // Xylene (mixed isomers)
            '100-41-4',   // Ethylbenzene
            '78-93-3',    // MEK
            '141-78-6',   // Ethyl acetate
            '123-86-4',   // n-Butyl acetate
            '110-19-0',   // Isobutyl acetate
            '109-99-9',   // THF
            '108-10-1',   // MIBK
            '71-36-3',    // n-Butanol
            '78-83-1',    // Isobutanol
            '111-76-2',   // 2-Butoxyethanol
            '34590-94-8', // Dipropylene glycol methyl ether
            '107-98-2',   // 1-Methoxy-2-propanol (PM)
            '111-15-9',   // 2-Ethoxyethyl acetate
            '112-07-2',   // 2-Butoxyethyl acetate
            '763-69-9',   // Ethyl 3-ethoxypropionate
            '57-55-6',    // Propylene glycol
            '111-46-6',   // Diethylene glycol
            '8052-41-3',  // Stoddard solvent
            '64742-88-7', // Medium aliphatic solvent naphtha
            '64742-95-6', // Light aromatic solvent naphtha
            '64742-89-8', // Aliphatic petroleum naphtha
        ];

        for ($i = 0; $i < count($this->formulaLines); $i++) {
            $line = &$this->formulaLines[$i];
            $rmCode = $line['internal_code'] ?? ('RM-' . ($line['raw_material_id'] ?? $i));
            $rmName = $line['supplier_product_name'] ?? '';

            // --- Specific Gravity ---
            if ($line['specific_gravity'] === null || $line['specific_gravity'] === '') {
                $line['_effective_sg'] = self::DEFAULT_SG;
                $this->addAssumption($rmCode, $rmName, 'Specific gravity assumed ' . self::DEFAULT_SG . ' (data not provided)');
                $this->traceStep('default_sg', "SG defaulted to 1.0 for $rmCode", [
                    'raw_material' => $rmCode,
                    'default_sg'   => self::DEFAULT_SG,
                ]);
            } else {
                $line['_effective_sg'] = (float) $line['specific_gravity'];
            }

            // --- VOC Weight Percent ---
            // If the <1% VOC checkbox is checked, use 0.99% for calculations
            $vocLessThanOne = (int) ($line['voc_less_than_one'] ?? 0);

            if ($vocLessThanOne) {
                $line['_effective_voc_wt'] = 0.99;
                $this->addAssumption($rmCode, $rmName, 'VOC wt% set to 0.99% (raw material marked as <1% VOC)');
                $this->traceStep('voc_less_than_one', "VOC <1% flag set for $rmCode, using 0.99%", [
                    'raw_material' => $rmCode,
                    'effective_voc_wt' => 0.99,
                ]);
            } elseif ($line['voc_wt'] === null || $line['voc_wt'] === '') {
                // Check constituents: if all are non-VOC (pigments, oligomers, resins), default to 0
                $hasVolatileCAS = false;
                $constituents   = $line['constituents'] ?? [];

                foreach ($constituents as $constituent) {
                    $cas = $constituent['cas_number'] ?? '';
                    if (in_array($cas, $knownSolventCAS, true)) {
                        $hasVolatileCAS = true;
                        break;
                    }
                    // Water is not a VOC
                    if ($cas === self::CAS_WATER) {
                        continue;
                    }
                }

                if (!$hasVolatileCAS) {
                    $line['_effective_voc_wt'] = 0.0;
                    $reason = 'VOC assumed 0% (no known volatile CAS in constituents';
                    if (!empty($constituents)) {
                        $reason .= '; NAPIM pigment/oligomer default)';
                    } else {
                        $reason .= '; no constituent data available)';
                    }
                    $this->addAssumption($rmCode, $rmName, $reason);
                } else {
                    // Has known solvent CAS but no VOC wt% -- this is a data gap
                    $line['_effective_voc_wt'] = 0.0;
                    $this->addAssumption($rmCode, $rmName, 'VOC wt% is NULL but known solvent CAS detected; defaulted to 0% -- DATA GAP, supplier SDS review required');
                    $this->traceStep('voc_data_gap', "VOC data missing for $rmCode with solvent CAS", [
                        'raw_material' => $rmCode,
                    ]);
                }
            } else {
                $line['_effective_voc_wt'] = (float) $line['voc_wt'];
            }

            // --- Exempt VOC Weight Percent ---
            if ($line['exempt_voc_wt'] === null || $line['exempt_voc_wt'] === '') {
                $line['_effective_exempt_voc_wt'] = 0.0;
                // Only note as assumption if this RM has any VOC content
                if (($line['_effective_voc_wt'] ?? 0) > 0) {
                    $this->addAssumption($rmCode, $rmName, 'Exempt VOC assumed 0% (data not provided)');
                }
            } else {
                $line['_effective_exempt_voc_wt'] = (float) $line['exempt_voc_wt'];
            }

            // --- Water Weight Percent ---
            if ($line['water_wt'] === null || $line['water_wt'] === '') {
                // Check if CAS 7732-18-5 (water) is in constituents
                $waterPct    = 0.0;
                $foundWater  = false;
                $constituents = $line['constituents'] ?? [];

                foreach ($constituents as $constituent) {
                    if (($constituent['cas_number'] ?? '') === self::CAS_WATER) {
                        $waterPct  = (float) ($constituent['pct_exact'] ?? $constituent['pct_max'] ?? $constituent['pct_min'] ?? 0);
                        $foundWater = true;
                        break;
                    }
                }

                if ($foundWater && $waterPct > 0) {
                    $line['_effective_water_wt'] = $waterPct;
                    $this->addAssumption($rmCode, $rmName, 'Water wt% derived from constituent data: ' . round($waterPct, 2) . '%');
                } else {
                    $line['_effective_water_wt'] = 0.0;
                }
            } else {
                $line['_effective_water_wt'] = (float) $line['water_wt'];
            }

            // --- Solids Weight Percent ---
            if ($line['solids_wt'] === null || $line['solids_wt'] === '') {
                // Estimate: Solids = 100 - VOC - Exempt VOC - Water
                $vocEff     = $line['_effective_voc_wt'] ?? 0;
                $exemptEff  = $line['_effective_exempt_voc_wt'] ?? 0;
                $waterEff   = $line['_effective_water_wt'] ?? 0;
                $estimatedSolids = max(0, 100.0 - $vocEff - $exemptEff - $waterEff);

                $line['_effective_solids_wt'] = $estimatedSolids;

                if ($estimatedSolids > 0) {
                    $this->addAssumption($rmCode, $rmName, 'Solids wt% estimated as ' . round($estimatedSolids, 2) . '% (100 - VOC - Exempt - Water)');
                }
            } else {
                $line['_effective_solids_wt'] = (float) $line['solids_wt'];
            }
        }
        unset($line);
    }

    /**
     * Estimate the specific gravity of the exempt solvent portion.
     *
     * Looks through formula lines for exempt VOC content and uses known SG values
     * of common exempt solvents. Falls back to 0.80 if no data available.
     */
    private function estimateExemptSolventSG(): float
    {
        // Common exempt solvents and their SG
        $exemptSolventSG = [
            '67-64-1'  => 0.791,  // Acetone
            '540-88-5' => 0.866,  // t-Butyl acetate
            '460-00-4' => 1.344,  // PCBTF (parachlorobenzotrifluoride)
            '75-65-0'  => 0.775,  // t-Butanol
            '100-42-5' => 0.906,  // Dimethyl carbonate
            '616-38-6' => 1.069,  // Dimethyl carbonate
        ];

        $weightedSG = 0.0;
        $totalExemptWt = 0.0;

        foreach ($this->formulaLines as $line) {
            $exemptVoc = (float) ($line['_effective_exempt_voc_wt'] ?? $line['exempt_voc_wt'] ?? 0);
            if ($exemptVoc <= 0) {
                continue;
            }
            $lineExemptWeight = ((float) ($line['pct'] ?? 0)) * $exemptVoc / 100.0;

            // Try to find exempt solvent CAS in constituents
            $foundExemptSG = false;
            $constituents = $line['constituents'] ?? [];
            foreach ($constituents as $constituent) {
                $cas = $constituent['cas_number'] ?? '';
                if (isset($exemptSolventSG[$cas])) {
                    $weightedSG += $lineExemptWeight * $exemptSolventSG[$cas];
                    $totalExemptWt += $lineExemptWeight;
                    $foundExemptSG = true;
                    break;
                }
            }

            if (!$foundExemptSG) {
                // Use the RM SG as a rough proxy, or default
                $sg = (float) ($line['_effective_sg'] ?? $line['specific_gravity'] ?? 0.80);
                $weightedSG += $lineExemptWeight * $sg;
                $totalExemptWt += $lineExemptWeight;
            }
        }

        if ($totalExemptWt > 0) {
            $result = $weightedSG / $totalExemptWt;
            $this->traceStep('exempt_sg_estimate', 'Exempt solvent SG estimated', [
                'estimated_sg' => $result,
            ]);
            return $result;
        }

        // Default exempt solvent SG
        return 0.80;
    }

    /**
     * Add an assumption to the running list.
     */
    private function addAssumption(string $rmCode, string $rmName, string $message): void
    {
        $label = $rmCode;
        if ($rmName !== '') {
            $label .= ' ' . $rmName;
        }

        $this->assumptions[] = [
            'raw_material' => $rmCode,
            'name'         => $rmName,
            'message'      => $label . ': ' . $message,
        ];
    }

    /**
     * Add a step to the calculation trace.
     */
    private function traceStep(string $stepType, ?string $description, array $data): void
    {
        $this->trace[] = [
            'step'        => $stepType,
            'description' => $description,
            'data'        => $data,
            'timestamp'   => microtime(true),
        ];
    }
}
