<?php

declare(strict_types=1);

namespace SDS\Services;

/**
 * GHSHazardData — Complete GHS hazard classification lookup table.
 *
 * Maps each GHS hazard class + category to its associated:
 *   - H-statement codes
 *   - P-statement codes (prevention, response, storage, disposal)
 *   - Pictogram(s)
 *   - Signal word (Danger / Warning)
 *
 * Based on UN GHS Rev.9, OSHA 29 CFR 1910.1200 Appendix C,
 * and PubChem GHS classification data.
 *
 * Used by the CAS Number Determination form to auto-populate codes
 * when a hazard statement is selected.
 */
class GHSHazardData
{
    /**
     * Master hazard classification table.
     *
     * Each entry is keyed by a unique identifier (hazard class + category)
     * and contains the full GHS classification for that hazard.
     */
    public const HAZARD_CLASSIFICATIONS = [
        // ── Explosives ──────────────────────────────────────────────
        'Explosives - Division 1.1' => [
            'class'       => 'Explosives',
            'category'    => 'Division 1.1',
            'h_codes'     => ['H201'],
            'p_codes'     => ['P210', 'P230', 'P234', 'P240', 'P250', 'P280', 'P370+P380', 'P372', 'P373', 'P401', 'P501'],
            'pictograms'  => ['GHS01'],
            'signal_word' => 'Danger',
        ],
        'Explosives - Division 1.2' => [
            'class'       => 'Explosives',
            'category'    => 'Division 1.2',
            'h_codes'     => ['H202'],
            'p_codes'     => ['P210', 'P230', 'P234', 'P240', 'P250', 'P280', 'P370+P380', 'P372', 'P373', 'P401', 'P501'],
            'pictograms'  => ['GHS01'],
            'signal_word' => 'Danger',
        ],
        'Explosives - Division 1.3' => [
            'class'       => 'Explosives',
            'category'    => 'Division 1.3',
            'h_codes'     => ['H203'],
            'p_codes'     => ['P210', 'P230', 'P234', 'P240', 'P250', 'P280', 'P370+P380', 'P372', 'P373', 'P401', 'P501'],
            'pictograms'  => ['GHS01'],
            'signal_word' => 'Danger',
        ],
        'Explosives - Division 1.4' => [
            'class'       => 'Explosives',
            'category'    => 'Division 1.4',
            'h_codes'     => ['H204'],
            'p_codes'     => ['P210', 'P234', 'P240', 'P250', 'P280', 'P370+P380', 'P401', 'P501'],
            'pictograms'  => ['GHS01'],
            'signal_word' => 'Warning',
        ],
        'Explosives - Division 1.5' => [
            'class'       => 'Explosives',
            'category'    => 'Division 1.5',
            'h_codes'     => ['H205'],
            'p_codes'     => ['P210', 'P230', 'P234', 'P240', 'P250', 'P280', 'P370+P380', 'P401', 'P501'],
            'pictograms'  => ['GHS01'],
            'signal_word' => 'Danger',
        ],

        // ── Flammable Gases ──────────────────────────────────────────
        'Flammable Gases - Category 1' => [
            'class'       => 'Flammable Gases',
            'category'    => 'Category 1',
            'h_codes'     => ['H220'],
            'p_codes'     => ['P210', 'P377', 'P381', 'P403'],
            'pictograms'  => ['GHS02'],
            'signal_word' => 'Danger',
        ],
        'Flammable Gases - Category 2' => [
            'class'       => 'Flammable Gases',
            'category'    => 'Category 2',
            'h_codes'     => ['H221'],
            'p_codes'     => ['P210', 'P377', 'P381', 'P403'],
            'pictograms'  => [],
            'signal_word' => 'Warning',
        ],

        // ── Flammable Aerosols ──────────────────────────────────────
        'Flammable Aerosols - Category 1' => [
            'class'       => 'Flammable Aerosols',
            'category'    => 'Category 1',
            'h_codes'     => ['H222', 'H229'],
            'p_codes'     => ['P210', 'P211', 'P251', 'P410+P412'],
            'pictograms'  => ['GHS02'],
            'signal_word' => 'Danger',
        ],
        'Flammable Aerosols - Category 2' => [
            'class'       => 'Flammable Aerosols',
            'category'    => 'Category 2',
            'h_codes'     => ['H223', 'H229'],
            'p_codes'     => ['P210', 'P211', 'P251', 'P410+P412'],
            'pictograms'  => ['GHS02'],
            'signal_word' => 'Warning',
        ],

        // ── Flammable Liquids ───────────────────────────────────────
        'Flammable Liquids - Category 1' => [
            'class'       => 'Flammable Liquids',
            'category'    => 'Category 1',
            'h_codes'     => ['H224'],
            'p_codes'     => ['P210', 'P233', 'P240', 'P241', 'P242', 'P243', 'P280', 'P303+P361+P353', 'P370+P378', 'P403+P235', 'P501'],
            'pictograms'  => ['GHS02'],
            'signal_word' => 'Danger',
        ],
        'Flammable Liquids - Category 2' => [
            'class'       => 'Flammable Liquids',
            'category'    => 'Category 2',
            'h_codes'     => ['H225'],
            'p_codes'     => ['P210', 'P233', 'P240', 'P241', 'P242', 'P243', 'P280', 'P303+P361+P353', 'P370+P378', 'P403+P235', 'P501'],
            'pictograms'  => ['GHS02'],
            'signal_word' => 'Danger',
        ],
        'Flammable Liquids - Category 3' => [
            'class'       => 'Flammable Liquids',
            'category'    => 'Category 3',
            'h_codes'     => ['H226'],
            'p_codes'     => ['P210', 'P233', 'P240', 'P241', 'P242', 'P243', 'P280', 'P303+P361+P353', 'P370+P378', 'P403+P235', 'P501'],
            'pictograms'  => ['GHS02'],
            'signal_word' => 'Warning',
        ],
        'Flammable Liquids - Category 4' => [
            'class'       => 'Flammable Liquids',
            'category'    => 'Category 4',
            'h_codes'     => ['H227'],
            'p_codes'     => ['P210', 'P280', 'P370+P378', 'P403+P235', 'P501'],
            'pictograms'  => [],
            'signal_word' => 'Warning',
        ],

        // ── Flammable Solids ────────────────────────────────────────
        'Flammable Solids - Category 1' => [
            'class'       => 'Flammable Solids',
            'category'    => 'Category 1',
            'h_codes'     => ['H228'],
            'p_codes'     => ['P210', 'P240', 'P241', 'P280', 'P370+P378'],
            'pictograms'  => ['GHS02'],
            'signal_word' => 'Danger',
        ],
        'Flammable Solids - Category 2' => [
            'class'       => 'Flammable Solids',
            'category'    => 'Category 2',
            'h_codes'     => ['H228'],
            'p_codes'     => ['P210', 'P240', 'P241', 'P280', 'P370+P378'],
            'pictograms'  => ['GHS02'],
            'signal_word' => 'Warning',
        ],

        // ── Self-Reactive Substances ──────────────────────────────
        'Self-Reactive - Type A' => [
            'class'       => 'Self-Reactive Substances',
            'category'    => 'Type A',
            'h_codes'     => ['H240'],
            'p_codes'     => ['P210', 'P220', 'P234', 'P280', 'P370+P380+P375', 'P403+P235', 'P411', 'P501'],
            'pictograms'  => ['GHS01'],
            'signal_word' => 'Danger',
        ],
        'Self-Reactive - Type B' => [
            'class'       => 'Self-Reactive Substances',
            'category'    => 'Type B',
            'h_codes'     => ['H241'],
            'p_codes'     => ['P210', 'P220', 'P234', 'P280', 'P370+P380+P375', 'P403+P235', 'P411', 'P501'],
            'pictograms'  => ['GHS01', 'GHS02'],
            'signal_word' => 'Danger',
        ],
        'Self-Reactive - Type C/D' => [
            'class'       => 'Self-Reactive Substances',
            'category'    => 'Type C & D',
            'h_codes'     => ['H242'],
            'p_codes'     => ['P210', 'P220', 'P234', 'P280', 'P370+P378', 'P403+P235', 'P411', 'P501'],
            'pictograms'  => ['GHS02'],
            'signal_word' => 'Danger',
        ],
        'Self-Reactive - Type E/F' => [
            'class'       => 'Self-Reactive Substances',
            'category'    => 'Type E & F',
            'h_codes'     => ['H242'],
            'p_codes'     => ['P210', 'P220', 'P234', 'P280', 'P370+P378', 'P403+P235', 'P411', 'P501'],
            'pictograms'  => ['GHS02'],
            'signal_word' => 'Warning',
        ],

        // ── Pyrophoric Liquids ──────────────────────────────────────
        'Pyrophoric Liquids - Category 1' => [
            'class'       => 'Pyrophoric Liquids',
            'category'    => 'Category 1',
            'h_codes'     => ['H250'],
            'p_codes'     => ['P210', 'P222', 'P280', 'P302+P334', 'P370+P378'],
            'pictograms'  => ['GHS02'],
            'signal_word' => 'Danger',
        ],

        // ── Pyrophoric Solids ───────────────────────────────────────
        'Pyrophoric Solids - Category 1' => [
            'class'       => 'Pyrophoric Solids',
            'category'    => 'Category 1',
            'h_codes'     => ['H250'],
            'p_codes'     => ['P210', 'P222', 'P280', 'P335+P334', 'P370+P378'],
            'pictograms'  => ['GHS02'],
            'signal_word' => 'Danger',
        ],

        // ── Self-Heating Substances ─────────────────────────────────
        'Self-Heating - Category 1' => [
            'class'       => 'Self-Heating Substances',
            'category'    => 'Category 1',
            'h_codes'     => ['H251'],
            'p_codes'     => ['P235', 'P280', 'P407', 'P413', 'P420'],
            'pictograms'  => ['GHS02'],
            'signal_word' => 'Danger',
        ],
        'Self-Heating - Category 2' => [
            'class'       => 'Self-Heating Substances',
            'category'    => 'Category 2',
            'h_codes'     => ['H252'],
            'p_codes'     => ['P235', 'P280', 'P407', 'P413', 'P420'],
            'pictograms'  => ['GHS02'],
            'signal_word' => 'Warning',
        ],

        // ── Water-Reactive (emit flammable gases) ───────────────────
        'Water-Reactive - Category 1' => [
            'class'       => 'Substances which, in contact with water, emit flammable gases',
            'category'    => 'Category 1',
            'h_codes'     => ['H260'],
            'p_codes'     => ['P223', 'P231+P232', 'P280', 'P302+P335+P334', 'P370+P378', 'P402+P404', 'P501'],
            'pictograms'  => ['GHS02'],
            'signal_word' => 'Danger',
        ],
        'Water-Reactive - Category 2' => [
            'class'       => 'Substances which, in contact with water, emit flammable gases',
            'category'    => 'Category 2',
            'h_codes'     => ['H261'],
            'p_codes'     => ['P223', 'P231+P232', 'P280', 'P302+P335+P334', 'P370+P378', 'P402+P404', 'P501'],
            'pictograms'  => ['GHS02'],
            'signal_word' => 'Danger',
        ],
        'Water-Reactive - Category 3' => [
            'class'       => 'Substances which, in contact with water, emit flammable gases',
            'category'    => 'Category 3',
            'h_codes'     => ['H261'],
            'p_codes'     => ['P223', 'P231+P232', 'P280', 'P370+P378', 'P402+P404', 'P501'],
            'pictograms'  => ['GHS02'],
            'signal_word' => 'Warning',
        ],

        // ── Oxidizing Liquids ───────────────────────────────────────
        'Oxidizing Liquids - Category 1' => [
            'class'       => 'Oxidizing Liquids',
            'category'    => 'Category 1',
            'h_codes'     => ['H271'],
            'p_codes'     => ['P210', 'P220', 'P280', 'P283', 'P306+P360', 'P371+P380+P375', 'P370+P378', 'P420', 'P501'],
            'pictograms'  => ['GHS03'],
            'signal_word' => 'Danger',
        ],
        'Oxidizing Liquids - Category 2' => [
            'class'       => 'Oxidizing Liquids',
            'category'    => 'Category 2',
            'h_codes'     => ['H272'],
            'p_codes'     => ['P210', 'P220', 'P280', 'P370+P378', 'P501'],
            'pictograms'  => ['GHS03'],
            'signal_word' => 'Danger',
        ],
        'Oxidizing Liquids - Category 3' => [
            'class'       => 'Oxidizing Liquids',
            'category'    => 'Category 3',
            'h_codes'     => ['H272'],
            'p_codes'     => ['P210', 'P220', 'P280', 'P370+P378', 'P501'],
            'pictograms'  => ['GHS03'],
            'signal_word' => 'Warning',
        ],

        // ── Oxidizing Solids ────────────────────────────────────────
        'Oxidizing Solids - Category 1' => [
            'class'       => 'Oxidizing Solids',
            'category'    => 'Category 1',
            'h_codes'     => ['H271'],
            'p_codes'     => ['P210', 'P220', 'P280', 'P283', 'P306+P360', 'P371+P380+P375', 'P370+P378', 'P420', 'P501'],
            'pictograms'  => ['GHS03'],
            'signal_word' => 'Danger',
        ],
        'Oxidizing Solids - Category 2' => [
            'class'       => 'Oxidizing Solids',
            'category'    => 'Category 2',
            'h_codes'     => ['H272'],
            'p_codes'     => ['P210', 'P220', 'P280', 'P370+P378', 'P501'],
            'pictograms'  => ['GHS03'],
            'signal_word' => 'Danger',
        ],
        'Oxidizing Solids - Category 3' => [
            'class'       => 'Oxidizing Solids',
            'category'    => 'Category 3',
            'h_codes'     => ['H272'],
            'p_codes'     => ['P210', 'P220', 'P280', 'P370+P378', 'P501'],
            'pictograms'  => ['GHS03'],
            'signal_word' => 'Warning',
        ],

        // ── Oxidizing Gases ────────────────────────────────────────
        'Oxidizing Gases - Category 1' => [
            'class'       => 'Oxidizing Gases',
            'category'    => 'Category 1',
            'h_codes'     => ['H270'],
            'p_codes'     => ['P220', 'P244', 'P370+P376', 'P403'],
            'pictograms'  => ['GHS03'],
            'signal_word' => 'Danger',
        ],

        // ── Gases Under Pressure ────────────────────────────────────
        'Gases Under Pressure - Compressed' => [
            'class'       => 'Gases Under Pressure',
            'category'    => 'Compressed gas',
            'h_codes'     => ['H280'],
            'p_codes'     => ['P410+P403'],
            'pictograms'  => ['GHS04'],
            'signal_word' => 'Warning',
        ],
        'Gases Under Pressure - Liquefied' => [
            'class'       => 'Gases Under Pressure',
            'category'    => 'Liquefied gas',
            'h_codes'     => ['H280'],
            'p_codes'     => ['P410+P403'],
            'pictograms'  => ['GHS04'],
            'signal_word' => 'Warning',
        ],
        'Gases Under Pressure - Refrigerated' => [
            'class'       => 'Gases Under Pressure',
            'category'    => 'Refrigerated liquefied gas',
            'h_codes'     => ['H281'],
            'p_codes'     => ['P282', 'P336', 'P315', 'P403'],
            'pictograms'  => ['GHS04'],
            'signal_word' => 'Warning',
        ],
        'Gases Under Pressure - Dissolved' => [
            'class'       => 'Gases Under Pressure',
            'category'    => 'Dissolved gas',
            'h_codes'     => ['H280'],
            'p_codes'     => ['P410+P403'],
            'pictograms'  => ['GHS04'],
            'signal_word' => 'Warning',
        ],

        // ── Corrosive to Metals ─────────────────────────────────────
        'Corrosive to Metals - Category 1' => [
            'class'       => 'Corrosive to Metals',
            'category'    => 'Category 1',
            'h_codes'     => ['H290'],
            'p_codes'     => ['P234', 'P390', 'P406'],
            'pictograms'  => ['GHS05'],
            'signal_word' => 'Warning',
        ],

        // ── Acute Toxicity — Oral ───────────────────────────────────
        'Acute Toxicity Oral - Category 1' => [
            'class'       => 'Acute Toxicity (Oral)',
            'category'    => 'Category 1',
            'h_codes'     => ['H300'],
            'p_codes'     => ['P264', 'P270', 'P301+P310', 'P321', 'P330', 'P405', 'P501'],
            'pictograms'  => ['GHS06'],
            'signal_word' => 'Danger',
        ],
        'Acute Toxicity Oral - Category 2' => [
            'class'       => 'Acute Toxicity (Oral)',
            'category'    => 'Category 2',
            'h_codes'     => ['H300'],
            'p_codes'     => ['P264', 'P270', 'P301+P310', 'P321', 'P330', 'P405', 'P501'],
            'pictograms'  => ['GHS06'],
            'signal_word' => 'Danger',
        ],
        'Acute Toxicity Oral - Category 3' => [
            'class'       => 'Acute Toxicity (Oral)',
            'category'    => 'Category 3',
            'h_codes'     => ['H301'],
            'p_codes'     => ['P264', 'P270', 'P301+P310', 'P321', 'P330', 'P405', 'P501'],
            'pictograms'  => ['GHS06'],
            'signal_word' => 'Danger',
        ],
        'Acute Toxicity Oral - Category 4' => [
            'class'       => 'Acute Toxicity (Oral)',
            'category'    => 'Category 4',
            'h_codes'     => ['H302'],
            'p_codes'     => ['P264', 'P270', 'P301+P312', 'P330', 'P501'],
            'pictograms'  => ['GHS07'],
            'signal_word' => 'Warning',
        ],
        'Acute Toxicity Oral - Category 5' => [
            'class'       => 'Acute Toxicity (Oral)',
            'category'    => 'Category 5',
            'h_codes'     => ['H303'],
            'p_codes'     => ['P312'],
            'pictograms'  => [],
            'signal_word' => 'Warning',
        ],

        // ── Acute Toxicity — Dermal ─────────────────────────────────
        'Acute Toxicity Dermal - Category 1' => [
            'class'       => 'Acute Toxicity (Dermal)',
            'category'    => 'Category 1',
            'h_codes'     => ['H310'],
            'p_codes'     => ['P262', 'P264', 'P270', 'P280', 'P302+P352', 'P310', 'P321', 'P361', 'P364', 'P405', 'P501'],
            'pictograms'  => ['GHS06'],
            'signal_word' => 'Danger',
        ],
        'Acute Toxicity Dermal - Category 2' => [
            'class'       => 'Acute Toxicity (Dermal)',
            'category'    => 'Category 2',
            'h_codes'     => ['H310'],
            'p_codes'     => ['P262', 'P264', 'P270', 'P280', 'P302+P352', 'P310', 'P321', 'P361', 'P364', 'P405', 'P501'],
            'pictograms'  => ['GHS06'],
            'signal_word' => 'Danger',
        ],
        'Acute Toxicity Dermal - Category 3' => [
            'class'       => 'Acute Toxicity (Dermal)',
            'category'    => 'Category 3',
            'h_codes'     => ['H311'],
            'p_codes'     => ['P280', 'P302+P352', 'P310', 'P321', 'P361', 'P364', 'P405', 'P501'],
            'pictograms'  => ['GHS06'],
            'signal_word' => 'Danger',
        ],
        'Acute Toxicity Dermal - Category 4' => [
            'class'       => 'Acute Toxicity (Dermal)',
            'category'    => 'Category 4',
            'h_codes'     => ['H312'],
            'p_codes'     => ['P280', 'P302+P352', 'P312', 'P321', 'P362+P364'],
            'pictograms'  => ['GHS07'],
            'signal_word' => 'Warning',
        ],
        'Acute Toxicity Dermal - Category 5' => [
            'class'       => 'Acute Toxicity (Dermal)',
            'category'    => 'Category 5',
            'h_codes'     => ['H313'],
            'p_codes'     => ['P312'],
            'pictograms'  => [],
            'signal_word' => 'Warning',
        ],

        // ── Acute Toxicity — Inhalation ─────────────────────────────
        'Acute Toxicity Inhalation - Category 1' => [
            'class'       => 'Acute Toxicity (Inhalation)',
            'category'    => 'Category 1',
            'h_codes'     => ['H330'],
            'p_codes'     => ['P260', 'P271', 'P284', 'P304+P340', 'P310', 'P320', 'P403+P233', 'P405', 'P501'],
            'pictograms'  => ['GHS06'],
            'signal_word' => 'Danger',
        ],
        'Acute Toxicity Inhalation - Category 2' => [
            'class'       => 'Acute Toxicity (Inhalation)',
            'category'    => 'Category 2',
            'h_codes'     => ['H330'],
            'p_codes'     => ['P260', 'P271', 'P284', 'P304+P340', 'P310', 'P320', 'P403+P233', 'P405', 'P501'],
            'pictograms'  => ['GHS06'],
            'signal_word' => 'Danger',
        ],
        'Acute Toxicity Inhalation - Category 3' => [
            'class'       => 'Acute Toxicity (Inhalation)',
            'category'    => 'Category 3',
            'h_codes'     => ['H331'],
            'p_codes'     => ['P261', 'P271', 'P304+P340', 'P311', 'P321', 'P403+P233', 'P405', 'P501'],
            'pictograms'  => ['GHS06'],
            'signal_word' => 'Danger',
        ],
        'Acute Toxicity Inhalation - Category 4' => [
            'class'       => 'Acute Toxicity (Inhalation)',
            'category'    => 'Category 4',
            'h_codes'     => ['H332'],
            'p_codes'     => ['P261', 'P271', 'P304+P340', 'P312'],
            'pictograms'  => ['GHS07'],
            'signal_word' => 'Warning',
        ],
        'Acute Toxicity Inhalation - Category 5' => [
            'class'       => 'Acute Toxicity (Inhalation)',
            'category'    => 'Category 5',
            'h_codes'     => ['H333'],
            'p_codes'     => ['P304+P312'],
            'pictograms'  => [],
            'signal_word' => 'Warning',
        ],

        // ── Skin Corrosion/Irritation ───────────────────────────────
        'Skin Corrosion - Category 1' => [
            'class'       => 'Skin Corrosion/Irritation',
            'category'    => 'Category 1 (1A/1B/1C)',
            'h_codes'     => ['H314'],
            'p_codes'     => ['P260', 'P264', 'P280', 'P301+P330+P331', 'P303+P361+P353', 'P304+P340', 'P305+P351+P338', 'P310', 'P321', 'P363', 'P405', 'P501'],
            'pictograms'  => ['GHS05'],
            'signal_word' => 'Danger',
        ],
        'Skin Irritation - Category 2' => [
            'class'       => 'Skin Corrosion/Irritation',
            'category'    => 'Category 2',
            'h_codes'     => ['H315'],
            'p_codes'     => ['P264', 'P280', 'P302+P352', 'P321', 'P332+P313', 'P362+P364'],
            'pictograms'  => ['GHS07'],
            'signal_word' => 'Warning',
        ],
        'Skin Irritation - Category 3' => [
            'class'       => 'Skin Corrosion/Irritation',
            'category'    => 'Category 3',
            'h_codes'     => ['H316'],
            'p_codes'     => ['P332+P313'],
            'pictograms'  => [],
            'signal_word' => 'Warning',
        ],

        // ── Serious Eye Damage/Irritation ───────────────────────────
        'Serious Eye Damage - Category 1' => [
            'class'       => 'Serious Eye Damage/Eye Irritation',
            'category'    => 'Category 1',
            'h_codes'     => ['H318'],
            'p_codes'     => ['P280', 'P305+P351+P338', 'P310'],
            'pictograms'  => ['GHS05'],
            'signal_word' => 'Danger',
        ],
        'Eye Irritation - Category 2A' => [
            'class'       => 'Serious Eye Damage/Eye Irritation',
            'category'    => 'Category 2A',
            'h_codes'     => ['H319'],
            'p_codes'     => ['P264', 'P280', 'P305+P351+P338', 'P337+P313'],
            'pictograms'  => ['GHS07'],
            'signal_word' => 'Warning',
        ],
        'Eye Irritation - Category 2B' => [
            'class'       => 'Serious Eye Damage/Eye Irritation',
            'category'    => 'Category 2B',
            'h_codes'     => ['H320'],
            'p_codes'     => ['P264', 'P305+P351+P338', 'P337+P313'],
            'pictograms'  => [],
            'signal_word' => 'Warning',
        ],

        // ── Respiratory Sensitization ───────────────────────────────
        'Respiratory Sensitization - Category 1' => [
            'class'       => 'Respiratory Sensitization',
            'category'    => 'Category 1 (1A/1B)',
            'h_codes'     => ['H334'],
            'p_codes'     => ['P261', 'P284', 'P304+P341', 'P342+P311', 'P501'],
            'pictograms'  => ['GHS08'],
            'signal_word' => 'Danger',
        ],

        // ── Skin Sensitization ──────────────────────────────────────
        'Skin Sensitization - Category 1' => [
            'class'       => 'Skin Sensitization',
            'category'    => 'Category 1 (1A/1B)',
            'h_codes'     => ['H317'],
            'p_codes'     => ['P261', 'P272', 'P280', 'P302+P352', 'P333+P313', 'P321', 'P363', 'P501'],
            'pictograms'  => ['GHS07'],
            'signal_word' => 'Warning',
        ],

        // ── Germ Cell Mutagenicity ──────────────────────────────────
        'Germ Cell Mutagenicity - Category 1' => [
            'class'       => 'Germ Cell Mutagenicity',
            'category'    => 'Category 1 (1A/1B)',
            'h_codes'     => ['H340'],
            'p_codes'     => ['P201', 'P202', 'P280', 'P308+P313', 'P405', 'P501'],
            'pictograms'  => ['GHS08'],
            'signal_word' => 'Danger',
        ],
        'Germ Cell Mutagenicity - Category 2' => [
            'class'       => 'Germ Cell Mutagenicity',
            'category'    => 'Category 2',
            'h_codes'     => ['H341'],
            'p_codes'     => ['P201', 'P202', 'P280', 'P308+P313', 'P405', 'P501'],
            'pictograms'  => ['GHS08'],
            'signal_word' => 'Warning',
        ],

        // ── Carcinogenicity ─────────────────────────────────────────
        'Carcinogenicity - Category 1A' => [
            'class'       => 'Carcinogenicity',
            'category'    => 'Category 1A',
            'h_codes'     => ['H350'],
            'p_codes'     => ['P201', 'P202', 'P280', 'P308+P313', 'P405', 'P501'],
            'pictograms'  => ['GHS08'],
            'signal_word' => 'Danger',
        ],
        'Carcinogenicity - Category 1B' => [
            'class'       => 'Carcinogenicity',
            'category'    => 'Category 1B',
            'h_codes'     => ['H350'],
            'p_codes'     => ['P201', 'P202', 'P280', 'P308+P313', 'P405', 'P501'],
            'pictograms'  => ['GHS08'],
            'signal_word' => 'Danger',
        ],
        'Carcinogenicity - Category 2' => [
            'class'       => 'Carcinogenicity',
            'category'    => 'Category 2',
            'h_codes'     => ['H351'],
            'p_codes'     => ['P201', 'P202', 'P280', 'P308+P313', 'P405', 'P501'],
            'pictograms'  => ['GHS08'],
            'signal_word' => 'Warning',
        ],

        // ── Reproductive Toxicity ───────────────────────────────────
        'Reproductive Toxicity - Category 1' => [
            'class'       => 'Reproductive Toxicity',
            'category'    => 'Category 1 (1A/1B)',
            'h_codes'     => ['H360'],
            'p_codes'     => ['P201', 'P202', 'P263', 'P280', 'P308+P313', 'P405', 'P501'],
            'pictograms'  => ['GHS08'],
            'signal_word' => 'Danger',
        ],
        'Reproductive Toxicity - Category 2' => [
            'class'       => 'Reproductive Toxicity',
            'category'    => 'Category 2',
            'h_codes'     => ['H361'],
            'p_codes'     => ['P201', 'P202', 'P263', 'P280', 'P308+P313', 'P405', 'P501'],
            'pictograms'  => ['GHS08'],
            'signal_word' => 'Warning',
        ],
        'Reproductive Toxicity - Lactation' => [
            'class'       => 'Reproductive Toxicity',
            'category'    => 'Lactation',
            'h_codes'     => ['H362'],
            'p_codes'     => ['P201', 'P260', 'P263', 'P264', 'P270'],
            'pictograms'  => [],
            'signal_word' => null,
        ],

        // ── STOT Single Exposure ────────────────────────────────────
        'STOT Single Exposure - Category 1' => [
            'class'       => 'STOT — Single Exposure',
            'category'    => 'Category 1',
            'h_codes'     => ['H370'],
            'p_codes'     => ['P260', 'P264', 'P270', 'P308+P311', 'P321', 'P405', 'P501'],
            'pictograms'  => ['GHS08'],
            'signal_word' => 'Danger',
        ],
        'STOT Single Exposure - Category 2' => [
            'class'       => 'STOT — Single Exposure',
            'category'    => 'Category 2',
            'h_codes'     => ['H371'],
            'p_codes'     => ['P260', 'P264', 'P270', 'P308+P311', 'P405', 'P501'],
            'pictograms'  => ['GHS08'],
            'signal_word' => 'Warning',
        ],
        'STOT Single Exposure - Category 3 (RI)' => [
            'class'       => 'STOT — Single Exposure',
            'category'    => 'Category 3 (Respiratory Irritation)',
            'h_codes'     => ['H335'],
            'p_codes'     => ['P261', 'P271', 'P304+P340', 'P312', 'P403+P233', 'P405', 'P501'],
            'pictograms'  => ['GHS07'],
            'signal_word' => 'Warning',
        ],
        'STOT Single Exposure - Category 3 (Narcotic)' => [
            'class'       => 'STOT — Single Exposure',
            'category'    => 'Category 3 (Narcotic Effects)',
            'h_codes'     => ['H336'],
            'p_codes'     => ['P261', 'P271', 'P304+P340', 'P312', 'P403+P233', 'P405', 'P501'],
            'pictograms'  => ['GHS07'],
            'signal_word' => 'Warning',
        ],

        // ── STOT Repeated Exposure ──────────────────────────────────
        'STOT Repeated Exposure - Category 1' => [
            'class'       => 'STOT — Repeated Exposure',
            'category'    => 'Category 1',
            'h_codes'     => ['H372'],
            'p_codes'     => ['P260', 'P264', 'P270', 'P314', 'P501'],
            'pictograms'  => ['GHS08'],
            'signal_word' => 'Danger',
        ],
        'STOT Repeated Exposure - Category 2' => [
            'class'       => 'STOT — Repeated Exposure',
            'category'    => 'Category 2',
            'h_codes'     => ['H373'],
            'p_codes'     => ['P260', 'P314', 'P501'],
            'pictograms'  => ['GHS08'],
            'signal_word' => 'Warning',
        ],

        // ── Aspiration Hazard ───────────────────────────────────────
        'Aspiration Hazard - Category 1' => [
            'class'       => 'Aspiration Hazard',
            'category'    => 'Category 1',
            'h_codes'     => ['H304'],
            'p_codes'     => ['P301+P310', 'P331', 'P405', 'P501'],
            'pictograms'  => ['GHS08'],
            'signal_word' => 'Danger',
        ],
        'Aspiration Hazard - Category 2' => [
            'class'       => 'Aspiration Hazard',
            'category'    => 'Category 2',
            'h_codes'     => ['H305'],
            'p_codes'     => ['P301+P310', 'P331', 'P405', 'P501'],
            'pictograms'  => [],
            'signal_word' => 'Warning',
        ],

        // ── Hazardous to Aquatic Environment — Acute ────────────────
        'Aquatic Acute - Category 1' => [
            'class'       => 'Hazardous to the Aquatic Environment (Acute)',
            'category'    => 'Category 1',
            'h_codes'     => ['H400'],
            'p_codes'     => ['P273', 'P391', 'P501'],
            'pictograms'  => ['GHS09'],
            'signal_word' => 'Warning',
        ],
        'Aquatic Acute - Category 2' => [
            'class'       => 'Hazardous to the Aquatic Environment (Acute)',
            'category'    => 'Category 2',
            'h_codes'     => ['H401'],
            'p_codes'     => ['P273', 'P501'],
            'pictograms'  => [],
            'signal_word' => null,
        ],
        'Aquatic Acute - Category 3' => [
            'class'       => 'Hazardous to the Aquatic Environment (Acute)',
            'category'    => 'Category 3',
            'h_codes'     => ['H402'],
            'p_codes'     => ['P273', 'P501'],
            'pictograms'  => [],
            'signal_word' => null,
        ],

        // ── Hazardous to Aquatic Environment — Chronic ──────────────
        'Aquatic Chronic - Category 1' => [
            'class'       => 'Hazardous to the Aquatic Environment (Chronic)',
            'category'    => 'Category 1',
            'h_codes'     => ['H410'],
            'p_codes'     => ['P273', 'P391', 'P501'],
            'pictograms'  => ['GHS09'],
            'signal_word' => 'Warning',
        ],
        'Aquatic Chronic - Category 2' => [
            'class'       => 'Hazardous to the Aquatic Environment (Chronic)',
            'category'    => 'Category 2',
            'h_codes'     => ['H411'],
            'p_codes'     => ['P273', 'P391', 'P501'],
            'pictograms'  => ['GHS09'],
            'signal_word' => null,
        ],
        'Aquatic Chronic - Category 3' => [
            'class'       => 'Hazardous to the Aquatic Environment (Chronic)',
            'category'    => 'Category 3',
            'h_codes'     => ['H412'],
            'p_codes'     => ['P273', 'P501'],
            'pictograms'  => [],
            'signal_word' => null,
        ],
        'Aquatic Chronic - Category 4' => [
            'class'       => 'Hazardous to the Aquatic Environment (Chronic)',
            'category'    => 'Category 4',
            'h_codes'     => ['H413'],
            'p_codes'     => ['P273', 'P501'],
            'pictograms'  => [],
            'signal_word' => null,
        ],

        // ── Hazardous to the Ozone Layer ────────────────────────────
        'Ozone Layer - Category 1' => [
            'class'       => 'Hazardous to the Ozone Layer',
            'category'    => 'Category 1',
            'h_codes'     => ['H420'],
            'p_codes'     => ['P502'],
            'pictograms'  => ['GHS07'],
            'signal_word' => 'Warning',
        ],
    ];

    /**
     * Get all hazard classifications as a flat array for JSON output.
     */
    public static function all(): array
    {
        return self::HAZARD_CLASSIFICATIONS;
    }

    /**
     * Get all unique hazard class names (for grouping in the UI).
     */
    public static function hazardClassNames(): array
    {
        $names = [];
        foreach (self::HAZARD_CLASSIFICATIONS as $entry) {
            $names[$entry['class']] = true;
        }
        return array_keys($names);
    }

    /**
     * Get classifications grouped by hazard class name.
     */
    public static function groupedByClass(): array
    {
        $grouped = [];
        foreach (self::HAZARD_CLASSIFICATIONS as $key => $entry) {
            $grouped[$entry['class']][$key] = $entry;
        }
        return $grouped;
    }

    /**
     * Get the full data for the determination form as a JSON-encodable structure.
     * Includes H/P code descriptions from GHSStatements.
     */
    public static function forJavaScript(): array
    {
        $data = [];
        foreach (self::HAZARD_CLASSIFICATIONS as $key => $entry) {
            $hDescriptions = [];
            foreach ($entry['h_codes'] as $code) {
                $hDescriptions[$code] = GHSStatements::hText($code);
            }
            $pDescriptions = [];
            foreach ($entry['p_codes'] as $code) {
                $pDescriptions[$code] = GHSStatements::pText($code);
            }

            $data[$key] = [
                'class'            => $entry['class'],
                'category'         => $entry['category'],
                'h_codes'          => $entry['h_codes'],
                'h_descriptions'   => $hDescriptions,
                'p_codes'          => $entry['p_codes'],
                'p_descriptions'   => $pDescriptions,
                'pictograms'       => $entry['pictograms'],
                'signal_word'      => $entry['signal_word'],
            ];
        }
        return $data;
    }
}
