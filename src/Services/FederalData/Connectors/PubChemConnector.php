<?php

namespace SDS\Services\FederalData\Connectors;

use SDS\Core\Database;
use SDS\Services\FederalData\FederalDataInterface;

/**
 * PubChemConnector
 *
 * PRIMARY federal data source.  Integrates with the PubChem PUG REST
 * and PUG View APIs to retrieve compound properties and GHS hazard
 * classifications for a given CAS Registry Number.
 *
 * API docs: https://pubchem.ncbi.nlm.nih.gov/docs/pug-rest
 *           https://pubchem.ncbi.nlm.nih.gov/docs/pug-view
 */
class PubChemConnector implements FederalDataInterface
{
    /* ------------------------------------------------------------------ */
    /*  Constants & config                                                 */
    /* ------------------------------------------------------------------ */

    private const SOURCE_NAME = 'PubChem';

    /** Base URLs */
    private const PUG_REST_BASE = 'https://pubchem.ncbi.nlm.nih.gov/rest/pug';
    private const PUG_VIEW_BASE = 'https://pubchem.ncbi.nlm.nih.gov/rest/pug_view';

    /** Default minimum milliseconds between consecutive HTTP requests. */
    private const DEFAULT_RATE_LIMIT_MS = 200;

    /** HTTP timeout in seconds for each request. */
    private const HTTP_TIMEOUT = 30;

    /** Maximum retries on transient failure. */
    private const MAX_RETRIES = 2;

    /* ------------------------------------------------------------------ */
    /*  Instance state                                                     */
    /* ------------------------------------------------------------------ */

    private Database $db;

    /** Milliseconds to sleep between successive HTTP requests. */
    private int $rateLimitMs;

    /** Microtime of the last HTTP request (float seconds). */
    private float $lastRequestTime = 0.0;

    /** In-memory log of errors during the current process. */
    private array $errors = [];

    /* ------------------------------------------------------------------ */
    /*  Constructor                                                        */
    /* ------------------------------------------------------------------ */

    /**
     * @param Database|null $db          Database instance (defaults to singleton)
     * @param int           $rateLimitMs Minimum ms between HTTP requests
     */
    public function __construct(?Database $db = null, int $rateLimitMs = self::DEFAULT_RATE_LIMIT_MS)
    {
        $this->db          = $db ?? Database::getInstance();
        $this->rateLimitMs = max(0, $rateLimitMs);
    }

    /* ------------------------------------------------------------------ */
    /*  FederalDataInterface                                               */
    /* ------------------------------------------------------------------ */

    public function getSourceName(): string
    {
        return self::SOURCE_NAME;
    }

    /**
     * Test connectivity by hitting the PubChem status endpoint.
     */
    public function isAvailable(): bool
    {
        try {
            $url  = self::PUG_REST_BASE . '/compound/name/water/cids/JSON';
            $body = $this->httpGet($url);
            return $body !== null && str_contains($body, 'IdentifierList');
        } catch (\Throwable $e) {
            $this->logError('isAvailable', $e->getMessage());
            return false;
        }
    }

    /**
     * Look up a CAS number against PubChem.
     *
     * Steps:
     *  1. Resolve CAS -> CID
     *  2. Fetch compound properties (formula, weight, IUPAC name)
     *  3. Fetch GHS classification from PUG View
     *  4. Parse GHS data
     *  5. Persist everything to database
     *
     * @return array|null  Structured result or null on failure
     */
    public function lookupCas(string $cas): ?array
    {
        $cas = $this->normalizeCas($cas);
        if ($cas === '') {
            return null;
        }

        try {
            /* ---- step 1: CAS -> CID ---- */
            $cid = $this->resolveCasToCid($cas);
            if ($cid === null) {
                $this->logError($cas, 'Could not resolve CAS to PubChem CID');
                return null;
            }

            /* ---- step 2: compound properties ---- */
            $properties = $this->fetchCompoundProperties($cid);

            /* ---- step 3: GHS classification (PUG View) ---- */
            $ghsRaw = $this->fetchGHSClassification($cid);
            $ghs    = $this->parseGHSData($ghsRaw);

            /* ---- assemble result ---- */
            $result = [
                'source'      => self::SOURCE_NAME,
                'cas'         => $cas,
                'cid'         => $cid,
                'properties'  => $properties,
                'ghs'         => $ghs,
                'retrieved_at' => gmdate('Y-m-d\TH:i:s\Z'),
            ];

            /* ---- step 5: persist ---- */
            $this->storeResult($cas, $result);

            return $result;

        } catch (\Throwable $e) {
            $this->logError($cas, $e->getMessage());
            return null;
        }
    }

    public function getLastRefresh(): ?string
    {
        $row = $this->db->fetch(
            'SELECT MAX(retrieved_at) AS last_refresh
               FROM hazard_source_records
              WHERE source_name = ?',
            [self::SOURCE_NAME]
        );
        return $row['last_refresh'] ?? null;
    }

    public function refreshAll(array $casList, callable $progressCallback = null): array
    {
        $success = [];
        $failed  = [];
        $total   = count($casList);

        foreach (array_values($casList) as $index => $cas) {
            if ($progressCallback !== null) {
                $progressCallback($cas, $index, $total);
            }

            $result = $this->lookupCas($cas);
            if ($result !== null) {
                $success[] = $cas;
            } else {
                $failed[] = $cas;
            }
        }

        return ['success' => $success, 'failed' => $failed];
    }

    /* ------------------------------------------------------------------ */
    /*  PubChem API methods                                                */
    /* ------------------------------------------------------------------ */

    /**
     * Resolve a CAS number to a PubChem Compound ID (CID).
     *
     * GET /compound/name/{CAS}/cids/JSON
     */
    private function resolveCasToCid(string $cas): ?int
    {
        $url  = self::PUG_REST_BASE . '/compound/name/' . urlencode($cas) . '/cids/JSON';
        $body = $this->httpGet($url);

        if ($body === null) {
            return null;
        }

        $data = json_decode($body, true);
        if (!isset($data['IdentifierList']['CID'][0])) {
            return null;
        }

        return (int) $data['IdentifierList']['CID'][0];
    }

    /**
     * Fetch molecular properties for a CID.
     *
     * GET /compound/cid/{CID}/property/MolecularFormula,MolecularWeight,IUPACName/JSON
     *
     * @return array  ['molecular_formula', 'molecular_weight', 'iupac_name']
     */
    private function fetchCompoundProperties(int $cid): array
    {
        $props = 'MolecularFormula,MolecularWeight,IUPACName';
        $url   = self::PUG_REST_BASE . '/compound/cid/' . $cid . '/property/' . $props . '/JSON';
        $body  = $this->httpGet($url);

        $defaults = [
            'molecular_formula' => null,
            'molecular_weight'  => null,
            'iupac_name'        => null,
        ];

        if ($body === null) {
            return $defaults;
        }

        $data = json_decode($body, true);
        $row  = $data['PropertyTable']['Properties'][0] ?? [];

        return [
            'molecular_formula' => $row['MolecularFormula'] ?? null,
            'molecular_weight'  => isset($row['MolecularWeight']) ? (float) $row['MolecularWeight'] : null,
            'iupac_name'        => $row['IUPACName'] ?? null,
        ];
    }

    /**
     * Fetch the full GHS classification section from PUG View.
     *
     * GET /data/compound/{CID}/JSON?heading=GHS+Classification
     *
     * @return array|null  Raw decoded JSON or null
     */
    private function fetchGHSClassification(int $cid): ?array
    {
        $url  = self::PUG_VIEW_BASE . '/data/compound/' . $cid . '/JSON?heading=GHS+Classification';
        $body = $this->httpGet($url);

        if ($body === null) {
            return null;
        }

        return json_decode($body, true);
    }

    /* ------------------------------------------------------------------ */
    /*  GHS parsing                                                        */
    /* ------------------------------------------------------------------ */

    /**
     * Parse a PUG View response to extract GHS classification data.
     *
     * The PUG View JSON nests data as:
     *   Record > Section[] > Section[] > Section[] > Information[]
     *
     * We look for the "GHS Classification" heading and then drill into
     * subsections for Pictograms, Signal, Hazard Statements, etc.
     *
     * @param  array|null $viewData  Full decoded PUG View response
     * @return array      Structured GHS data
     */
    public function parseGHSData(?array $viewData): array
    {
        $result = [
            'signal_word'    => null,
            'pictogram_codes' => [],
            'hazard_classes' => [],
            'hazard_statements'     => [],  // ['code' => 'H225', 'text' => '...']
            'precautionary_statements' => [],
        ];

        if ($viewData === null) {
            return $result;
        }

        /* Locate the top-level Record > Section array. */
        $sections = $viewData['Record']['Section'] ?? [];

        /* Walk through all sections to find GHS-related data. */
        $ghsSections = $this->findSectionsByHeading($sections, 'GHS Classification');
        if (empty($ghsSections)) {
            /* Try one level deeper — PUG View sometimes wraps in "Safety and Hazards" */
            foreach ($sections as $topSection) {
                $subSections = $topSection['Section'] ?? [];
                $ghsSections = array_merge(
                    $ghsSections,
                    $this->findSectionsByHeading($subSections, 'GHS Classification')
                );
            }
        }

        foreach ($ghsSections as $ghsSection) {
            $this->extractFromGHSSection($ghsSection, $result);
        }

        /* De-duplicate */
        $result['pictogram_codes']          = array_values(array_unique($result['pictogram_codes']));
        $result['hazard_statements']        = $this->uniqueStatements($result['hazard_statements']);
        $result['precautionary_statements'] = $this->uniqueStatements($result['precautionary_statements']);
        $result['hazard_classes']           = $this->uniqueHazardClasses($result['hazard_classes']);

        return $result;
    }

    /**
     * Recursively search a Section array for sections with a matching heading.
     */
    private function findSectionsByHeading(array $sections, string $heading): array
    {
        $found = [];
        foreach ($sections as $section) {
            $sectionHeading = $section['TOCHeading'] ?? '';
            if (strcasecmp($sectionHeading, $heading) === 0) {
                $found[] = $section;
            }
            /* Recurse into child sections */
            if (!empty($section['Section'])) {
                $found = array_merge($found, $this->findSectionsByHeading($section['Section'], $heading));
            }
        }
        return $found;
    }

    /**
     * Extract GHS data from a "GHS Classification" section node.
     */
    private function extractFromGHSSection(array $section, array &$result): void
    {
        /* Process Information nodes directly on this section */
        foreach ($section['Information'] ?? [] as $info) {
            $this->extractFromInformationNode($info, $result);
        }

        /* Process child sections (Pictogram, Signal, H-statements, etc.) */
        foreach ($section['Section'] ?? [] as $child) {
            $childHeading = strtolower($child['TOCHeading'] ?? '');

            if (str_contains($childHeading, 'pictogram')) {
                $this->extractPictograms($child, $result);
            } elseif (str_contains($childHeading, 'signal')) {
                $this->extractSignalWord($child, $result);
            } elseif (str_contains($childHeading, 'hazard statement')) {
                $this->extractStatements($child, $result, 'hazard_statements');
            } elseif (str_contains($childHeading, 'precautionary statement')) {
                $this->extractStatements($child, $result, 'precautionary_statements');
            } elseif (str_contains($childHeading, 'hazard class')) {
                $this->extractHazardClasses($child, $result);
            }

            /* Recurse for nested GHS data */
            $this->extractFromGHSSection($child, $result);
        }
    }

    /**
     * Extract data from an individual Information node.
     *
     * PubChem encodes GHS data in several possible Value structures.
     */
    private function extractFromInformationNode(array $info, array &$result): void
    {
        $name = $info['Name'] ?? '';
        $value = $info['Value'] ?? [];

        /* ---- Signal word ---- */
        if (stripos($name, 'Signal') !== false) {
            $text = $this->extractStringValue($value);
            if ($text !== null && ($text === 'Danger' || $text === 'Warning')) {
                $result['signal_word'] = $text;
            }
        }

        /* ---- Pictograms (markup images with extra references) ---- */
        if (stripos($name, 'Pictogram') !== false || stripos($name, 'GHS') !== false) {
            /* Pictograms may come as Markup with Extra containing GHS code */
            foreach ($value['StringWithMarkup'] ?? [] as $swm) {
                foreach ($swm['Markup'] ?? [] as $markup) {
                    $extra = $markup['Extra'] ?? '';
                    if (preg_match('/GHS\d{2}/', $extra, $m)) {
                        $result['pictogram_codes'][] = $m[0];
                    }
                    $url = $markup['URL'] ?? '';
                    if (preg_match('/GHS(\d{2})/', $url, $m)) {
                        $result['pictogram_codes'][] = 'GHS' . $m[1];
                    }
                }
                /* Also check the string itself */
                $str = $swm['String'] ?? '';
                if (preg_match_all('/GHS\d{2}/', $str, $m)) {
                    $result['pictogram_codes'] = array_merge($result['pictogram_codes'], $m[0]);
                }
            }
        }

        /* ---- Hazard statements (H-codes) ---- */
        if (stripos($name, 'Hazard Statement') !== false || stripos($name, 'H Statement') !== false) {
            $this->extractStatementsFromValue($value, $result, 'hazard_statements', 'H');
        }

        /* ---- Precautionary statements (P-codes) ---- */
        if (stripos($name, 'Precautionary Statement') !== false || stripos($name, 'P Statement') !== false) {
            $this->extractStatementsFromValue($value, $result, 'precautionary_statements', 'P');
        }

        /* ---- Hazard class and category ---- */
        if (stripos($name, 'Hazard Class') !== false || stripos($name, 'GHS Classification') !== false) {
            $text = $this->extractStringValue($value);
            if ($text !== null) {
                $parsed = $this->parseHazardClassText($text);
                if ($parsed !== null) {
                    $result['hazard_classes'][] = $parsed;
                }
            }
        }
    }

    /**
     * Extract pictogram codes from a Pictogram subsection.
     */
    private function extractPictograms(array $section, array &$result): void
    {
        foreach ($section['Information'] ?? [] as $info) {
            $value = $info['Value'] ?? [];
            foreach ($value['StringWithMarkup'] ?? [] as $swm) {
                foreach ($swm['Markup'] ?? [] as $markup) {
                    $extra = $markup['Extra'] ?? '';
                    if (preg_match('/GHS\d{2}/', $extra, $m)) {
                        $result['pictogram_codes'][] = $m[0];
                    }
                    $url = $markup['URL'] ?? '';
                    if (preg_match('/GHS(\d{2})/', $url, $m)) {
                        $result['pictogram_codes'][] = 'GHS' . $m[1];
                    }
                }
                $str = $swm['String'] ?? '';
                if (preg_match_all('/GHS\d{2}/', $str, $m)) {
                    $result['pictogram_codes'] = array_merge($result['pictogram_codes'], $m[0]);
                }
            }
        }
    }

    /**
     * Extract signal word from a Signal subsection.
     */
    private function extractSignalWord(array $section, array &$result): void
    {
        foreach ($section['Information'] ?? [] as $info) {
            $value = $info['Value'] ?? [];
            $text  = $this->extractStringValue($value);
            if ($text !== null) {
                $text = trim($text);
                if (strcasecmp($text, 'Danger') === 0) {
                    $result['signal_word'] = 'Danger';
                } elseif (strcasecmp($text, 'Warning') === 0) {
                    $result['signal_word'] = 'Warning';
                }
            }
        }
    }

    /**
     * Extract H- or P-statements from a subsection.
     */
    private function extractStatements(array $section, array &$result, string $key): void
    {
        $prefix = ($key === 'hazard_statements') ? 'H' : 'P';

        foreach ($section['Information'] ?? [] as $info) {
            $value = $info['Value'] ?? [];
            $this->extractStatementsFromValue($value, $result, $key, $prefix);
        }
    }

    /**
     * Extract statement codes + text from a Value node.
     */
    private function extractStatementsFromValue(array $value, array &$result, string $key, string $prefix): void
    {
        foreach ($value['StringWithMarkup'] ?? [] as $swm) {
            $str = $swm['String'] ?? '';
            if ($str === '') {
                continue;
            }

            /* Pattern: "H225: Highly flammable liquid and vapour" or "H225 - Highly flammable..."
               Also handles combined codes like "H302+H312+H332" */
            $pattern = '/(' . $prefix . '\d{3}(?:\s*\+\s*' . $prefix . '\d{3})*)\s*[:;\-\s]\s*(.*)/i';
            if (preg_match($pattern, $str, $m)) {
                $result[$key][] = [
                    'code' => strtoupper(preg_replace('/\s+/', '', $m[1])),
                    'text' => trim($m[2]),
                ];
            } else {
                /* Bare code or plain text */
                $codePattern = '/' . $prefix . '\d{3}(?:\s*\+\s*' . $prefix . '\d{3})*/i';
                if (preg_match($codePattern, $str, $m)) {
                    $result[$key][] = [
                        'code' => strtoupper(preg_replace('/\s+/', '', $m[0])),
                        'text' => trim($str),
                    ];
                }
            }
        }
    }

    /**
     * Extract hazard class/category entries from a subsection.
     */
    private function extractHazardClasses(array $section, array &$result): void
    {
        foreach ($section['Information'] ?? [] as $info) {
            $value = $info['Value'] ?? [];
            $text  = $this->extractStringValue($value);
            if ($text !== null) {
                $parsed = $this->parseHazardClassText($text);
                if ($parsed !== null) {
                    $result['hazard_classes'][] = $parsed;
                }
            }
        }
    }

    /**
     * Parse a hazard class + category string.
     *
     * Examples:
     *   "Flammable Liquids, Category 2"
     *   "Acute Toxicity - Oral, Category 4"
     *   "Skin Corrosion/Irritation Category 2"
     *
     * @return array|null  ['class' => ..., 'category' => ...]
     */
    private function parseHazardClassText(string $text): ?array
    {
        $text = trim($text);
        if ($text === '') {
            return null;
        }

        $category = null;
        if (preg_match('/[,\s]+Category\s+(\S+)/i', $text, $m)) {
            $category = trim($m[1]);
            $text     = trim(preg_replace('/[,\s]+Category\s+\S+/i', '', $text));
        }

        if ($text === '') {
            return null;
        }

        return [
            'class'    => $text,
            'category' => $category,
        ];
    }

    /* ------------------------------------------------------------------ */
    /*  Value-extraction helpers                                           */
    /* ------------------------------------------------------------------ */

    /**
     * Pull a plain string out of a PUG View Value structure.
     */
    private function extractStringValue(array $value): ?string
    {
        /* StringWithMarkup is the most common container */
        if (!empty($value['StringWithMarkup'])) {
            return $value['StringWithMarkup'][0]['String'] ?? null;
        }
        /* Fallback to Number */
        if (isset($value['Number'])) {
            return (string) $value['Number'][0];
        }
        return null;
    }

    /**
     * De-duplicate statements by code.
     */
    private function uniqueStatements(array $statements): array
    {
        $seen = [];
        $out  = [];
        foreach ($statements as $s) {
            $code = $s['code'] ?? '';
            if ($code !== '' && !isset($seen[$code])) {
                $seen[$code] = true;
                $out[] = $s;
            }
        }
        return $out;
    }

    /**
     * De-duplicate hazard classes by class name.
     */
    private function uniqueHazardClasses(array $classes): array
    {
        $seen = [];
        $out  = [];
        foreach ($classes as $c) {
            $key = strtolower($c['class'] ?? '');
            if ($key !== '' && !isset($seen[$key])) {
                $seen[$key] = true;
                $out[] = $c;
            }
        }
        return $out;
    }

    /* ------------------------------------------------------------------ */
    /*  Database persistence                                               */
    /* ------------------------------------------------------------------ */

    /**
     * Store a lookup result into hazard_source_records, hazard_classifications,
     * and (if present) exposure_limits.
     */
    private function storeResult(string $cas, array $result): void
    {
        $payloadJson = json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $payloadHash = hash('sha256', $payloadJson);

        /* Check for existing record with same hash (no change) */
        $existing = $this->db->fetch(
            'SELECT id FROM hazard_source_records
              WHERE cas_number = ? AND source_name = ? AND payload_hash = ?',
            [$cas, self::SOURCE_NAME, $payloadHash]
        );

        if ($existing !== null) {
            /* Data unchanged — just touch retrieved_at */
            $this->db->update(
                'hazard_source_records',
                ['retrieved_at' => gmdate('Y-m-d H:i:s')],
                'id = ?',
                [$existing['id']]
            );
            return;
        }

        $this->db->beginTransaction();
        try {
            /* ---- source record ---- */
            $sourceUrl = self::PUG_REST_BASE . '/compound/name/' . urlencode($cas) . '/cids/JSON';

            $recordId = $this->db->insert('hazard_source_records', [
                'cas_number'   => $cas,
                'source_name'  => self::SOURCE_NAME,
                'source_url'   => $sourceUrl,
                'retrieved_at' => gmdate('Y-m-d H:i:s'),
                'payload_hash' => $payloadHash,
                'payload_json' => $payloadJson,
            ]);

            /* ---- hazard classifications ---- */
            $ghs = $result['ghs'] ?? [];

            foreach ($ghs['hazard_classes'] ?? [] as $hc) {
                $this->db->insert('hazard_classifications', [
                    'hazard_source_record_id' => $recordId,
                    'cas_number'              => $cas,
                    'class_name'              => $hc['class'],
                    'category'                => $hc['category'] ?? '',
                    'signal_word'             => $ghs['signal_word'],
                    'h_statements_json'       => json_encode($ghs['hazard_statements'] ?? [], JSON_UNESCAPED_UNICODE),
                    'p_statements_json'       => json_encode($ghs['precautionary_statements'] ?? [], JSON_UNESCAPED_UNICODE),
                    'pictograms_json'         => json_encode($ghs['pictogram_codes'] ?? [], JSON_UNESCAPED_UNICODE),
                ]);
            }

            /* If no hazard classes were found but we have H-statements, store a generic row */
            if (empty($ghs['hazard_classes']) && !empty($ghs['hazard_statements'])) {
                $this->db->insert('hazard_classifications', [
                    'hazard_source_record_id' => $recordId,
                    'cas_number'              => $cas,
                    'class_name'              => 'Unclassified',
                    'category'                => '',
                    'signal_word'             => $ghs['signal_word'],
                    'h_statements_json'       => json_encode($ghs['hazard_statements'], JSON_UNESCAPED_UNICODE),
                    'p_statements_json'       => json_encode($ghs['precautionary_statements'] ?? [], JSON_UNESCAPED_UNICODE),
                    'pictograms_json'         => json_encode($ghs['pictogram_codes'] ?? [], JSON_UNESCAPED_UNICODE),
                ]);
            }

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollback();
            $this->logError($cas, 'DB persist failed: ' . $e->getMessage());
            throw $e;
        }
    }

    /* ------------------------------------------------------------------ */
    /*  HTTP layer                                                         */
    /* ------------------------------------------------------------------ */

    /**
     * Perform an HTTP GET with rate-limiting, timeout, and retry logic.
     *
     * Prefers curl when available; falls back to file_get_contents with
     * a stream context.
     *
     * @return string|null  Response body or null on failure
     */
    private function httpGet(string $url): ?string
    {
        $this->enforceRateLimit();

        for ($attempt = 0; $attempt <= self::MAX_RETRIES; $attempt++) {
            if ($attempt > 0) {
                /* Exponential back-off: 500ms, 1000ms */
                usleep($attempt * 500_000);
                $this->enforceRateLimit();
            }

            $body = $this->doHttpGet($url);
            if ($body !== null) {
                return $body;
            }
        }

        return null;
    }

    /**
     * Single-attempt HTTP GET.
     */
    private function doHttpGet(string $url): ?string
    {
        $this->lastRequestTime = microtime(true);

        /* ---- prefer curl ---- */
        if (function_exists('curl_init')) {
            return $this->curlGet($url);
        }

        /* ---- fallback: file_get_contents ---- */
        return $this->fgcGet($url);
    }

    /**
     * HTTP GET via curl.
     */
    private function curlGet(string $url): ?string
    {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_TIMEOUT        => self::HTTP_TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_USERAGENT      => 'SDS-System/1.0 (FederalDataService; PHP)',
            CURLOPT_HTTPHEADER     => ['Accept: application/json'],
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $body     = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error    = curl_error($ch);
        curl_close($ch);

        if ($body === false || $httpCode < 200 || $httpCode >= 300) {
            $this->logError('HTTP', "curl GET {$url} -> HTTP {$httpCode}: {$error}");
            return null;
        }

        return $body;
    }

    /**
     * HTTP GET via file_get_contents + stream context.
     */
    private function fgcGet(string $url): ?string
    {
        $context = stream_context_create([
            'http' => [
                'method'        => 'GET',
                'header'        => "Accept: application/json\r\nUser-Agent: SDS-System/1.0 (FederalDataService; PHP)\r\n",
                'timeout'       => self::HTTP_TIMEOUT,
                'ignore_errors' => true,
            ],
            'ssl' => [
                'verify_peer'      => true,
                'verify_peer_name' => true,
            ],
        ]);

        $body = @file_get_contents($url, false, $context);

        /* Check response code from $http_response_header */
        $httpCode = 0;
        if (isset($http_response_header) && is_array($http_response_header)) {
            foreach ($http_response_header as $header) {
                if (preg_match('/^HTTP\/[\d.]+ (\d{3})/', $header, $m)) {
                    $httpCode = (int) $m[1];
                }
            }
        }

        if ($body === false || $httpCode < 200 || $httpCode >= 300) {
            $this->logError('HTTP', "fgc GET {$url} -> HTTP {$httpCode}");
            return null;
        }

        return $body;
    }

    /**
     * Enforce the rate-limit delay between requests.
     */
    private function enforceRateLimit(): void
    {
        if ($this->rateLimitMs <= 0 || $this->lastRequestTime <= 0.0) {
            return;
        }

        $elapsed = (microtime(true) - $this->lastRequestTime) * 1000;
        $wait    = $this->rateLimitMs - $elapsed;

        if ($wait > 0) {
            usleep((int) ($wait * 1000));
        }
    }

    /* ------------------------------------------------------------------ */
    /*  Utility                                                            */
    /* ------------------------------------------------------------------ */

    /**
     * Normalize a CAS number: trim whitespace, strip surrounding quotes.
     */
    private function normalizeCas(string $cas): string
    {
        $cas = trim($cas, " \t\n\r\0\x0B\"'");
        /* Basic CAS validation: digits-digits-digit */
        if (!preg_match('/^\d{1,7}-\d{2}-\d$/', $cas)) {
            /* Also accept without dashes for lookup, reformatting is not our job */
            if (preg_match('/^\d+$/', $cas)) {
                return $cas;
            }
            /* Accept dashed variants with more digits (some have 8-digit segments) */
            if (preg_match('/^\d+-\d+-\d+$/', $cas)) {
                return $cas;
            }
            return '';
        }
        return $cas;
    }

    /**
     * Append to internal error log.
     */
    private function logError(string $context, string $message): void
    {
        $entry = [
            'time'    => gmdate('Y-m-d\TH:i:s\Z'),
            'source'  => self::SOURCE_NAME,
            'context' => $context,
            'message' => $message,
        ];
        $this->errors[] = $entry;
        error_log('[PubChemConnector] ' . json_encode($entry));
    }

    /**
     * Return errors accumulated during this process lifecycle.
     */
    public function getErrors(): array
    {
        return $this->errors;
    }
}
