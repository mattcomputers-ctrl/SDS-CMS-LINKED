<?php

declare(strict_types=1);

namespace SDS\Controllers;

use SDS\Core\Database;
use SDS\Services\AliasResolver;
use SDS\Services\SDSReadinessService;

/**
 * SDSReviewController — "Is this finished good ready for an SDS?"
 *
 * Given an FG (or alias, or partial search term), shows the list of
 * raw materials in the formula tree that still need user review. Uses
 * the same reviewed-RM definition as bulk publish, so if the review
 * page says "ready", bulk publish agrees.
 *
 * Routing:
 *   GET /sds-review                — empty search form
 *   GET /sds-review?q=TERM         — resolves TERM to an FG; if exactly
 *                                    one match, renders the readiness
 *                                    detail; otherwise shows a picker.
 *   GET /sds-review?fg_id=N        — renders the readiness detail for
 *                                    finished_good N.
 */
class SDSReviewController
{
    public function index(): void
    {
        if (!can_read('sds_review')) {
            $_SESSION['_flash']['error'] = 'You do not have permission to view the SDS Review page.';
            redirect('/');
        }

        $db = Database::getInstance();

        $q    = trim((string) ($_GET['q'] ?? ''));
        $fgId = isset($_GET['fg_id']) ? (int) $_GET['fg_id'] : 0;
        $rmId = isset($_GET['rm_id']) ? (int) $_GET['rm_id'] : 0;

        $review  = null;
        $matches = [];

        if ($fgId > 0) {
            $review = SDSReadinessService::review($fgId);
            if ($review === null) {
                $_SESSION['_flash']['error'] = 'Finished good #' . $fgId . ' not found.';
                redirect('/sds-review');
            }
        } elseif ($rmId > 0) {
            $review = SDSReadinessService::reviewRawMaterial($rmId);
            if ($review === null) {
                $_SESSION['_flash']['error'] = 'Raw material #' . $rmId . ' not found.';
                redirect('/sds-review');
            }
        } elseif ($q !== '') {
            // Try an exact FG product_code match first — the common case
            // when an operator types "E4404" directly.
            $exact = $db->fetch(
                "SELECT id FROM finished_goods WHERE product_code = ?",
                [$q]
            );
            if ($exact !== null) {
                redirect('/sds-review?fg_id=' . (int) $exact['id']);
            }

            // Alias — resolve either to a formula FG or a resale RM.
            // AliasResolver encapsulates the "does this base code have
            // an FG with a formula, otherwise an RM" rule so bulk
            // publish / label / SDS generation all agree.
            $aliasResolution = AliasResolver::resolveByCustomerCode($q);
            if ($aliasResolution !== null) {
                if ($aliasResolution['type'] === 'formula') {
                    redirect('/sds-review?fg_id=' . (int) $aliasResolution['fg']['id']);
                }
                if ($aliasResolution['type'] === 'resale') {
                    redirect('/sds-review?rm_id=' . (int) $aliasResolution['rm']['id']);
                }
            }

            // Direct RM code — an operator may type "DSS0100" or
            // "DSS0100-20" to check the resale item itself.
            $rmResolution = AliasResolver::resolveByRawMaterialCode($q);
            if ($rmResolution !== null) {
                redirect('/sds-review?rm_id=' . (int) $rmResolution['rm']['id']);
            }

            // Fall back to a partial-match picker so typos and description
            // searches still surface something useful. Includes resale
            // aliases (matched via RM internal_code) alongside FG/alias
            // matches.
            $like = '%' . $q . '%';
            $base = AliasResolver::stripPack($q);
            $matches = $this->findMatches($db, $like, $base);

            if (count($matches) === 1) {
                $row = $matches[0];
                if (($row['_kind'] ?? 'fg') === 'rm') {
                    redirect('/sds-review?rm_id=' . (int) $row['id']);
                }
                redirect('/sds-review?fg_id=' . (int) $row['id']);
            }
        }

        view('sds-review/index', [
            'pageTitle' => 'SDS Creation Readiness Check',
            'q'         => $q,
            'review'    => $review,
            'matches'   => $matches,
        ]);
    }

    /**
     * Union of FG/alias matches and resale-RM matches for the picker.
     *
     * Each row is tagged with '_kind' ('fg' | 'rm') so the view and the
     * auto-redirect logic know which URL to build.
     */
    private function findMatches(Database $db, string $like, string $base): array
    {
        $fgRows = $db->fetchAll(
            "SELECT DISTINCT fg.id, fg.product_code, fg.description,
                    fg.family, fg.is_active
             FROM finished_goods fg
             LEFT JOIN aliases a ON a.internal_code_base = fg.product_code
             WHERE fg.product_code LIKE ?
                OR fg.description LIKE ?
                OR a.customer_code LIKE ?
                OR a.description LIKE ?
             ORDER BY fg.product_code ASC
             LIMIT 50",
            [$like, $like, $like, $like]
        );

        // Resale RM matches — RMs whose base internal_code matches the
        // search, OR whose supplier_product_name/internal_code LIKE, that
        // don't also have a finished_goods row (that would make them
        // formula-based, which the FG query already covers).
        $rmRows = $db->fetchAll(
            "SELECT DISTINCT rm.id, rm.internal_code, rm.supplier, rm.supplier_product_name
             FROM raw_materials rm
             LEFT JOIN aliases a
                 ON SUBSTRING_INDEX(a.internal_code, '-', 1) = SUBSTRING_INDEX(rm.internal_code, '-', 1)
             WHERE (
                    rm.internal_code LIKE ?
                 OR rm.supplier_product_name LIKE ?
                 OR SUBSTRING_INDEX(rm.internal_code, '-', 1) = ?
                 OR a.customer_code LIKE ?
                 OR a.description LIKE ?
             )
               AND NOT EXISTS (
                   SELECT 1 FROM finished_goods fg
                    WHERE fg.product_code = SUBSTRING_INDEX(rm.internal_code, '-', 1)
               )
             ORDER BY rm.internal_code ASC
             LIMIT 50",
            [$like, $like, $base, $like, $like]
        );

        $rows = [];
        foreach ($fgRows as $r) {
            $r['_kind']        = 'fg';
            $r['display_code'] = $r['product_code'];
            $rows[] = $r;
        }
        foreach ($rmRows as $r) {
            $r['_kind']        = 'rm';
            $r['display_code'] = AliasResolver::stripPack((string) $r['internal_code']);
            $r['description']  = $r['supplier_product_name'];
            $r['family']       = null;
            $r['is_active']    = 1;
            $rows[] = $r;
        }
        return $rows;
    }
}
