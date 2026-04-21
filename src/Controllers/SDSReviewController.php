<?php

declare(strict_types=1);

namespace SDS\Controllers;

use SDS\Core\Database;
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

        $review  = null;
        $matches = [];

        if ($fgId > 0) {
            $review = SDSReadinessService::review($fgId);
            if ($review === null) {
                $_SESSION['_flash']['error'] = 'Finished good #' . $fgId . ' not found.';
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

            // Then try alias customer_code — the user may have typed a
            // customer-facing code like "BK1008" or "BK1008-50" (pack-ext
            // variant). Check both the raw input and the base code with
            // the pack-extension stripped.
            $aliasBase = strpos($q, '-') !== false
                ? substr($q, 0, strpos($q, '-'))
                : $q;
            $aliasFg = $db->fetch(
                "SELECT fg.id
                 FROM aliases a
                 INNER JOIN finished_goods fg ON fg.product_code = a.internal_code_base
                 WHERE a.customer_code = ?
                    OR SUBSTRING_INDEX(a.customer_code, '-', 1) = ?
                 LIMIT 1",
                [$q, $aliasBase]
            );
            if ($aliasFg !== null) {
                redirect('/sds-review?fg_id=' . (int) $aliasFg['id']);
            }

            // Fall back to a partial-match picker so typos and description
            // searches still surface something useful.
            $like = '%' . $q . '%';
            $matches = $db->fetchAll(
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

            if (count($matches) === 1) {
                redirect('/sds-review?fg_id=' . (int) $matches[0]['id']);
            }
        }

        view('sds-review/index', [
            'pageTitle' => 'SDS Creation Readiness Check',
            'q'         => $q,
            'review'    => $review,
            'matches'   => $matches,
        ]);
    }
}
