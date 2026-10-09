<?php

declare(strict_types=1);

namespace SDS\Controllers;

use SDS\Core\App;
use SDS\Core\CSRF;
use SDS\Core\Database;
use SDS\Models\FinishedGood;
use SDS\Models\Manufacturer;
use SDS\Models\PrivateLabelItem;
use SDS\Services\AuditService;
use SDS\Services\PrivateLabelPublisher;
use SDS\Services\SDSGenerator;

/**
 * PrivateLabelController — manufacturer-scoped private label registry.
 *
 * Private label SDS documents are stored in a separate table
 * (private_label_sds) and are NOT exported by any other utility in the
 * system (bulk export, SDS lookup, SDS book, etc.). They are only
 * accessible from this section.
 *
 * Pages:
 *   /private-label                         manufacturers overview
 *   /private-label/manufacturer/{id}       items of one manufacturer -> latest PDF per language
 *   /private-label/items/{id}/history      every version of one item
 *   /private-label/documents               flat cross-manufacturer audit view
 *
 * Identity (what is printed on the document) is resolved by
 * PrivateLabelPublisher::resolveIdentity() — custom code, then shared
 * alias, then the base finished good — and frozen into each published
 * row. All publishing goes through PrivateLabelPublisher::republishItems().
 *
 * Permissions: 'private_label' read = browse / download / preview;
 * edit = item CRUD, publish, retire, delete, republish-stale.
 */
class PrivateLabelController
{
    /** Display names for the configured language codes (fallback: upper-cased code). */
    private const LANGUAGE_NAMES = [
        'en' => 'English',
        'es' => 'Spanish',
        'fr' => 'French',
        'de' => 'German',
    ];

    /* ------------------------------------------------------------------
     *  Overview pages
     * ----------------------------------------------------------------*/

    /**
     * GET /private-label — manufacturers with item / published / stale counts.
     */
    public function index(): void
    {
        $this->requireRead();

        $search    = trim($_GET['search'] ?? '');
        $languages = self::languages();
        $summaries = PrivateLabelItem::manufacturerSummaries($search);

        // Per-manufacturer stale / never-published counts: gather every active
        // item once, then one status pass (one latestByItem query + one
        // fgLatestBaseVersions query for the whole set).
        $allItems = [];
        foreach ($summaries as $s) {
            if ((int) ($s['active_count'] ?? 0) === 0) {
                continue;
            }
            foreach (PrivateLabelItem::forManufacturer((int) $s['id'], false, '') as $it) {
                $allItems[] = $it;
            }
        }

        $staleCounts = [];
        $neverCounts = [];
        foreach ($this->buildRows($allItems, $languages) as $row) {
            $mid  = (int) $row['item']['manufacturer_id'];
            $code = $row['status']['code'];
            if ($code === 'stale') {
                $staleCounts[$mid] = ($staleCounts[$mid] ?? 0) + 1;
            } elseif ($code === 'never') {
                $neverCounts[$mid] = ($neverCounts[$mid] ?? 0) + 1;
            }
        }

        view('private-label/index', [
            'pageTitle'   => 'Private Label SDS',
            'summaries'   => $summaries,
            'staleCounts' => $staleCounts,
            'neverCounts' => $neverCounts,
            'search'      => $search,
        ]);
    }

    /**
     * GET /private-label/documents — flat cross-manufacturer audit view
     * (the old index). Shows the frozen identity of every published row.
     */
    public function documents(): void
    {
        $this->requireRead();

        $db = Database::getInstance();

        $search             = trim($_GET['search'] ?? '');
        $manufacturerFilter = (int) ($_GET['manufacturer_id'] ?? 0);
        $fgFilter           = (int) ($_GET['fg_id'] ?? 0);
        $itemFilter         = (int) ($_GET['item_id'] ?? 0);

        $where  = [];
        $params = [];

        if ($search !== '') {
            $like    = '%' . $search . '%';
            $where[] = '(pl.product_code LIKE ? OR pl.product_description LIKE ? OR fg.product_code LIKE ?'
                     . ' OR fg.description LIKE ? OR a.customer_code LIKE ? OR m.name LIKE ?)';
            array_push($params, $like, $like, $like, $like, $like, $like);
        }
        if ($manufacturerFilter > 0) {
            $where[]  = 'pl.manufacturer_id = ?';
            $params[] = $manufacturerFilter;
        }
        if ($fgFilter > 0) {
            $where[]  = 'pl.finished_good_id = ?';
            $params[] = $fgFilter;
        }
        if ($itemFilter > 0) {
            $where[]  = 'pl.item_id = ?';
            $params[] = $itemFilter;
        }

        $whereSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        $items = $db->fetchAll(
            "SELECT pl.*,
                    fg.product_code AS fg_product_code, fg.description AS fg_description,
                    m.name AS manufacturer_name,
                    a.customer_code AS alias_code, a.description AS alias_description,
                    u.display_name AS published_by_name
             FROM private_label_sds pl
             JOIN finished_goods fg ON fg.id = pl.finished_good_id
             JOIN manufacturers m ON m.id = pl.manufacturer_id
             LEFT JOIN aliases a ON a.id = pl.alias_id
             LEFT JOIN users u ON u.id = pl.published_by
             {$whereSQL}
             ORDER BY m.name ASC,
                      COALESCE(pl.product_code, a.customer_code, fg.product_code) ASC,
                      pl.item_id ASC, pl.version DESC, pl.language ASC",
            $params
        );

        foreach ($items as &$row) {
            $row['display_code']        = self::frozenCode($row);
            $row['display_description'] = self::frozenDescription($row);
        }
        unset($row);

        view('private-label/documents', [
            'pageTitle'          => 'Private Label SDS — All Documents',
            'items'              => $items,
            'manufacturers'      => Manufacturer::all(),
            'search'             => $search,
            'manufacturerFilter' => $manufacturerFilter,
            'fgFilter'           => $fgFilter,
            'itemFilter'         => $itemFilter,
        ]);
    }

    /**
     * GET /private-label/create — the old create page. Bookmark safety:
     * items are now created from a manufacturer page.
     */
    public function legacyCreate(): void
    {
        $this->requireRead();

        $_SESSION['_flash']['info'] = 'Private label documents are now managed per manufacturer: pick a manufacturer, then use "Add item".';
        redirect('/private-label');
    }

    /**
     * GET /private-label/aliases-for-fg?fg_id=N — JSON [{id, code, description}]
     * of the shared aliases whose base code is the finished good's product
     * code, deduplicated by base code (pack extension stripped).
     */
    public function aliasesForFg(): void
    {
        header('Content-Type: application/json');

        if (!can_read('private_label')) {
            http_response_code(403);
            echo json_encode(['error' => 'Permission denied']);
            return;
        }

        $fgId = (int) ($_GET['fg_id'] ?? 0);
        echo json_encode(self::aliasOptionsForFg($fgId), JSON_UNESCAPED_UNICODE);
    }

    /**
     * GET /private-label/live-preview — fresh SDS data with the private
     * label identity applied. Accepts item_id (saved item) OR the ad-hoc
     * form fields (finished_good_id, manufacturer_id, identity_mode,
     * alias_id / borrow_alias_id, custom_code, custom_description) plus
     * lang (default: first configured language).
     */
    public function livePreview(): void
    {
        $this->requireRead();

        $languages = self::languages();
        $lang      = (string) ($_GET['lang'] ?? '');
        if (!in_array($lang, $languages, true)) {
            $lang = $languages[0];
        }

        $itemId  = (int) ($_GET['item_id'] ?? 0);
        $backUrl = '/private-label';

        try {
            if ($itemId > 0) {
                $item = PrivateLabelItem::findById($itemId);
                if ($item === null) {
                    throw new \RuntimeException('Private label item not found.');
                }
                $manufacturer = Manufacturer::findById((int) $item['manufacturer_id']);
                if ($manufacturer === null) {
                    throw new \RuntimeException('Manufacturer not found.');
                }
                $fgId    = (int) $item['finished_good_id'];
                $backUrl = '/private-label/manufacturer/' . (int) $item['manufacturer_id'];

                // R4 — same refusal as PrivateLabelPublisher::publishOne() and
                // adHocItem(): a CMS sync can re-point the shared alias to a
                // different product (or drop it), and the preview must not show
                // a document the Publish button on the same row would refuse.
                $aliasId = ((int) ($item['alias_id'] ?? 0) > 0) ? (int) $item['alias_id'] : null;
                if ($aliasId !== null) {
                    $aliasBase = $item['alias_internal_code_base'] ?? null;
                    if ($aliasBase === null) {
                        throw new \RuntimeException('Shared alias #' . $aliasId . ' not found.');
                    }
                    if (strcasecmp((string) $aliasBase, (string) $item['fg_product_code']) !== 0) {
                        throw new \RuntimeException(
                            'Shared alias ' . strip_pack_extension((string) ($item['alias_customer_code'] ?? ('#' . $aliasId)))
                            . ' no longer belongs to ' . $item['fg_product_code'] . '.'
                        );
                    }
                }
            } else {
                $fgId           = (int) ($_GET['finished_good_id'] ?? 0);
                $manufacturerId = (int) ($_GET['manufacturer_id'] ?? 0);

                $manufacturer = $manufacturerId > 0 ? Manufacturer::findById($manufacturerId) : null;
                if ($manufacturer === null) {
                    throw new \RuntimeException('Please select a valid manufacturer.');
                }
                $backUrl = '/private-label/manufacturer/' . $manufacturerId;

                $fg = $fgId > 0 ? FinishedGood::findById($fgId) : null;
                if ($fg === null) {
                    throw new \RuntimeException('Please select a valid finished good.');
                }

                $item = $this->adHocItem($fg, $manufacturer, $_GET);
            }

            $identity = PrivateLabelPublisher::resolveIdentity($item);

            $generator = new SDSGenerator();
            $sdsData   = $generator->generate($fgId, $lang);
            $sdsData   = SDSGenerator::createPrivateLabelVariant(
                $sdsData,
                $identity['code'],
                $identity['description'],
                Manufacturer::toCompanyInfo($manufacturer)
            );
        } catch (\Throwable $e) {
            $_SESSION['_flash']['error'] = 'Private label preview failed: ' . $e->getMessage();
            redirect($backUrl);
        }

        // Audit #38 — the preview is the real PDF (see SDSPreviewResponse).
        $mode = \SDS\Services\SDSPreviewResponse::mode($_GET);
        if ($mode === \SDS\Services\SDSPreviewResponse::MODE_PDF) {
            try {
                $bytes = (new \SDS\Services\PDFService())->generateString($sdsData);
            } catch (\Throwable $e) {
                $_SESSION['_flash']['error'] = 'Private label preview failed: ' . $e->getMessage();
                redirect($backUrl);
            }
            \SDS\Services\SDSPreviewResponse::send($bytes, \SDS\Services\SDSPreviewResponse::filename($sdsData));
        }

        view($mode === \SDS\Services\SDSPreviewResponse::MODE_HTML ? 'sds/preview' : 'sds/preview-pdf', [
            'pageTitle'      => 'Private Label SDS Live Preview (' . strtoupper($lang) . '): '
                                . $identity['code'] . ' / ' . $manufacturer['name'],
            'finishedGood'   => ['product_code' => $identity['code']],
            'sds'            => $sdsData,
            'language'       => $lang,
            'privateLabelId' => 0,
            'backUrl'        => $backUrl,
            'backLabel'      => 'Back to ' . $manufacturer['name'],
        ]);
    }

    /* ------------------------------------------------------------------
     *  Manufacturer page
     * ----------------------------------------------------------------*/

    /**
     * GET /private-label/manufacturer/{id} — items with the latest PDF per
     * language and a staleness badge.
     */
    public function manufacturer(string $id): void
    {
        $this->requireRead();

        $mid          = (int) $id;
        $manufacturer = Manufacturer::findById($mid);
        if ($manufacturer === null) {
            $_SESSION['_flash']['error'] = 'Manufacturer not found.';
            redirect('/private-label');
        }

        $search      = trim($_GET['search'] ?? '');
        $showRetired = !empty($_GET['show_retired']);
        $languages   = self::languages();

        $items = PrivateLabelItem::forManufacturer($mid, $showRetired, $search);
        $rows  = $this->buildRows($items, $languages);

        // "Republish stale (n)" acts on every active item of the manufacturer
        // regardless of the search box, so n is counted on the unfiltered set.
        $countRows = $search === ''
            ? $rows
            : $this->buildRows(PrivateLabelItem::forManufacturer($mid, false, ''), $languages);
        $staleCount = 0;
        foreach ($countRows as $r) {
            if ((int) $r['item']['is_active'] === 1 && in_array($r['status']['code'], ['stale', 'never'], true)) {
                $staleCount++;
            }
        }

        view('private-label/manufacturer', [
            'pageTitle'    => 'Private Label: ' . $manufacturer['name'],
            'manufacturer' => $manufacturer,
            'rows'         => $rows,
            'languages'    => $languages,
            'langNames'    => self::languageNames($languages),
            'search'       => $search,
            'showRetired'  => $showRetired,
            'staleCount'   => $staleCount,
        ]);
    }

    /* ------------------------------------------------------------------
     *  Item CRUD
     * ----------------------------------------------------------------*/

    /**
     * GET /private-label/manufacturer/{id}/items/create
     */
    public function createItem(string $id): void
    {
        $this->requireEdit();

        $mid          = (int) $id;
        $manufacturer = Manufacturer::findById($mid);
        if ($manufacturer === null) {
            $_SESSION['_flash']['error'] = 'Manufacturer not found.';
            redirect('/private-label');
        }

        $languages     = self::languages();
        $finishedGoods = $this->eligibleFinishedGoods();

        $fgIds            = array_map(static fn(array $f): int => (int) $f['id'], $finishedGoods);
        $fgLatestVersions = $fgIds !== [] ? PrivateLabelItem::fgLatestBaseVersions($fgIds) : [];

        // Redirect-back after a validation error: preload the alias options
        // for the finished good the user had picked.
        $oldFgId        = (int) ($_SESSION['_flash']['_old_input']['finished_good_id'] ?? 0);
        $initialAliases = $oldFgId > 0 ? self::aliasOptionsForFg($oldFgId) : [];

        view('private-label/item-form', [
            'pageTitle'        => 'Add Private Label Item — ' . $manufacturer['name'],
            'mode'             => 'create',
            'item'             => null,
            'manufacturer'     => $manufacturer,
            'finishedGood'     => null,
            'finishedGoods'    => $finishedGoods,
            'languages'        => $languages,
            'langNames'        => self::languageNames($languages),
            'fgLatestVersions' => $fgLatestVersions,
            'initialAliases'   => $initialAliases,
            'identity'         => null,
            'identityMode'     => 'base',
        ]);
    }

    /**
     * POST /private-label/manufacturer/{id}/items
     */
    public function storeItem(string $id): void
    {
        $this->requireEdit();
        CSRF::validateRequest();

        $mid          = (int) $id;
        $manufacturer = Manufacturer::findById($mid);
        if ($manufacturer === null) {
            $_SESSION['_flash']['error'] = 'Manufacturer not found.';
            redirect('/private-label');
        }

        $formUrl = '/private-label/manufacturer/' . $mid . '/items/create';
        $listUrl = '/private-label/manufacturer/' . $mid;

        $fgId = (int) ($_POST['finished_good_id'] ?? 0);
        $fg   = $fgId > 0 ? FinishedGood::findById($fgId) : null;
        if ($fg === null) {
            $this->failBack('Please select a valid finished good.', $formUrl);
        }

        $fields = $this->identityFields($_POST);
        $notes  = trim((string) ($_POST['notes'] ?? ''));

        $data = [
            'manufacturer_id'    => $mid,
            'finished_good_id'   => $fgId,
            'identity_mode'      => $fields['identity_mode'],
            'alias_id'           => $fields['alias_id'],
            'custom_code'        => $fields['custom_code'],
            'custom_description' => $fields['custom_description'],
            'is_active'          => 1,
            'auto_republish'     => !empty($_POST['auto_republish']) ? 1 : 0,
            'notes'              => $notes !== '' ? $notes : null,
            'created_by'         => current_user_id(),
        ];

        $newId = 0;
        try {
            $error = PrivateLabelItem::validate($data, $fg);
            if ($error !== null) {
                $this->failBack($error, $formUrl);
            }

            $createData = $data;
            unset($createData['identity_mode']);
            $newId = PrivateLabelItem::create($createData);
        } catch (\PDOException $e) {
            $this->failBack(self::pdoMessage($e), $formUrl);
        } catch (\Throwable $e) {
            $this->failBack($e->getMessage(), $formUrl);
        }

        AuditService::log('private_label_item', (string) $newId, 'create', [
            'manufacturer_id'    => $mid,
            'manufacturer'       => $manufacturer['name'],
            'finished_good_id'   => $fgId,
            'fg_product_code'    => $fg['product_code'],
            'identity_mode'      => $fields['identity_mode'],
            'alias_id'           => $fields['alias_id'],
            'custom_code'        => $fields['custom_code'],
            'custom_description' => $fields['custom_description'],
            'auto_republish'     => $data['auto_republish'],
            'notes'              => $data['notes'],
        ]);

        $success = 'Item saved.';
        $warning = null;

        if (!empty($_POST['publish_now'])) {
            $result = (new PrivateLabelPublisher())->republishItems(
                [$newId],
                current_user_id(),
                'Initial private label SDS',
                'item_create'
            );
            [$ok, $problems] = $this->summarisePublish($result, self::languages());
            if ($ok !== null) {
                $success = 'Item saved (' . $ok . ').';
            }
            if ($problems !== null) {
                $warning = 'Private label SDS not published: ' . $problems;
            }
        }

        $_SESSION['_flash']['success'] = $success;
        if ($warning !== null) {
            $_SESSION['_flash']['warning'] = $warning;
        }
        redirect($listUrl);
    }

    /**
     * GET /private-label/items/{id}/edit
     */
    public function editItem(string $id): void
    {
        $this->requireEdit();

        $itemId = (int) $id;
        $item   = PrivateLabelItem::findById($itemId);
        if ($item === null) {
            $_SESSION['_flash']['error'] = 'Private label item not found.';
            redirect('/private-label');
        }

        $manufacturer = Manufacturer::findById((int) $item['manufacturer_id']);
        if ($manufacturer === null) {
            $_SESSION['_flash']['error'] = 'Manufacturer not found.';
            redirect('/private-label');
        }

        $fgId      = (int) $item['finished_good_id'];
        $languages = self::languages();

        view('private-label/item-form', [
            'pageTitle'        => 'Edit Private Label Item — ' . $manufacturer['name'],
            'mode'             => 'edit',
            'item'             => $item,
            'manufacturer'     => $manufacturer,
            'finishedGood'     => [
                'id'           => $fgId,
                'product_code' => $item['fg_product_code'] ?? '',
                'description'  => $item['fg_description'] ?? '',
                'is_active'    => (int) ($item['fg_is_active'] ?? 1),
            ],
            'finishedGoods'    => [],
            'languages'        => $languages,
            'langNames'        => self::languageNames($languages),
            'fgLatestVersions' => PrivateLabelItem::fgLatestBaseVersions([$fgId]),
            'initialAliases'   => self::aliasOptionsForFg(
                $fgId,
                $item['alias_id'] !== null ? (int) $item['alias_id'] : null
            ),
            'identity'         => PrivateLabelPublisher::resolveIdentity($item),
            'identityMode'     => self::identityModeOf($item),
        ]);
    }

    /**
     * POST /private-label/items/{id}
     */
    public function updateItem(string $id): void
    {
        $this->requireEdit();
        CSRF::validateRequest();

        $itemId = (int) $id;
        $item   = PrivateLabelItem::findById($itemId);
        if ($item === null) {
            $_SESSION['_flash']['error'] = 'Private label item not found.';
            redirect('/private-label');
        }

        $mid     = (int) $item['manufacturer_id'];
        $formUrl = '/private-label/items/' . $itemId . '/edit';
        $listUrl = '/private-label/manufacturer/' . $mid;

        // The base product is immutable on edit (history must not be
        // re-pointed): any posted finished_good_id is ignored.
        $fgId = (int) $item['finished_good_id'];
        $fg   = FinishedGood::findById($fgId);
        if ($fg === null) {
            $this->failBack('The base finished good for this item no longer exists.', $formUrl);
        }

        $fields = $this->identityFields($_POST);
        $notes  = trim((string) ($_POST['notes'] ?? ''));

        $updateData = [
            'alias_id'           => $fields['alias_id'],
            'custom_code'        => $fields['custom_code'],
            'custom_description' => $fields['custom_description'],
            'auto_republish'     => !empty($_POST['auto_republish']) ? 1 : 0,
            'notes'              => $notes !== '' ? $notes : null,
        ];

        $data = $updateData + [
            'manufacturer_id'  => $mid,
            'finished_good_id' => $fgId,
            'identity_mode'    => $fields['identity_mode'],
            'is_active'        => (int) $item['is_active'],
        ];

        try {
            $error = PrivateLabelItem::validate($data, $fg, $itemId);
            if ($error !== null) {
                $this->failBack($error, $formUrl);
            }
            PrivateLabelItem::update($itemId, $updateData);
        } catch (\PDOException $e) {
            $this->failBack(self::pdoMessage($e), $formUrl);
        } catch (\Throwable $e) {
            $this->failBack($e->getMessage(), $formUrl);
        }

        $before = [];
        foreach (array_keys($updateData) as $col) {
            $before[$col] = $item[$col] ?? null;
        }
        $diff = AuditService::diff($before, $updateData);
        AuditService::log('private_label_item', (string) $itemId, 'update', [
            'manufacturer_id'  => $mid,
            'finished_good_id' => $fgId,
            'changes'          => $diff,
        ]);

        $identityChanged = isset($diff['alias_id']) || isset($diff['custom_code']) || isset($diff['custom_description']);

        $success = 'Item updated.';
        $warning = null;

        if (!empty($_POST['publish_now'])) {
            $result = (new PrivateLabelPublisher())->republishItems(
                [$itemId],
                current_user_id(),
                'Republished after identity change',
                'item_edit'
            );
            [$ok, $problems] = $this->summarisePublish($result, self::languages());
            if ($ok !== null) {
                $success = 'Item updated (' . $ok . ').';
            }
            if ($problems !== null) {
                $warning = 'Private label SDS not published: ' . $problems;
            }
        } elseif ($identityChanged) {
            $success .= ' The identity changed, so the item is marked stale until it is republished.';
        }

        $_SESSION['_flash']['success'] = $success;
        if ($warning !== null) {
            $_SESSION['_flash']['warning'] = $warning;
        }
        redirect($listUrl);
    }

    /**
     * POST /private-label/items/{id}/publish — explicit republish of one
     * item (frozen auto_republish = 0 items included: operator action).
     */
    public function publishItem(string $id): void
    {
        $this->requireEdit();
        CSRF::validateRequest();

        $itemId = (int) $id;
        $item   = PrivateLabelItem::findById($itemId);
        if ($item === null) {
            $_SESSION['_flash']['error'] = 'Private label item not found.';
            redirect('/private-label');
        }

        $listUrl = '/private-label/manufacturer/' . (int) $item['manufacturer_id'];
        $code    = PrivateLabelPublisher::resolveIdentity($item)['code'];

        if ((int) $item['is_active'] !== 1) {
            $_SESSION['_flash']['error'] = $code . ' is retired — restore it before publishing.';
            redirect($listUrl);
        }

        $result = (new PrivateLabelPublisher())->republishItems(
            [$itemId],
            current_user_id(),
            'Republished from private label page',
            'manual_item'
        );
        [$ok, $problems] = $this->summarisePublish($result, self::languages());

        if ($ok !== null) {
            $_SESSION['_flash']['success'] = $code . ': ' . $ok;
        }
        if ($problems !== null) {
            $_SESSION['_flash'][$ok === null ? 'error' : 'warning'] = 'Private label SDS not published: ' . $problems;
        }
        redirect($listUrl);
    }

    /**
     * POST /private-label/items/{id}/retire — toggles is_active.
     */
    public function retireItem(string $id): void
    {
        $this->requireEdit();
        CSRF::validateRequest();

        $itemId = (int) $id;
        $item   = PrivateLabelItem::findById($itemId);
        if ($item === null) {
            $_SESSION['_flash']['error'] = 'Private label item not found.';
            redirect('/private-label');
        }

        $listUrl   = '/private-label/manufacturer/' . (int) $item['manufacturer_id'];
        $code      = PrivateLabelPublisher::resolveIdentity($item)['code'];
        $newActive = (int) $item['is_active'] === 1 ? 0 : 1;

        try {
            PrivateLabelItem::update($itemId, ['is_active' => $newActive]);
        } catch (\Throwable $e) {
            $_SESSION['_flash']['error'] = 'Could not update item: ' . $e->getMessage();
            redirect($listUrl);
        }

        AuditService::log('private_label_item', (string) $itemId, $newActive === 1 ? 'restore' : 'retire', [
            'manufacturer_id'  => (int) $item['manufacturer_id'],
            'finished_good_id' => (int) $item['finished_good_id'],
            'code'             => $code,
        ]);

        $_SESSION['_flash']['success'] = $newActive === 1
            ? $code . ' restored.'
            : $code . ' retired — it is excluded from every republish until restored; its history stays downloadable.';
        redirect($listUrl);
    }

    /**
     * POST /private-label/items/{id}/delete — only when the item has no
     * published history (FK RESTRICT would refuse anyway).
     */
    public function deleteItem(string $id): void
    {
        $this->requireEdit();
        CSRF::validateRequest();

        $itemId = (int) $id;
        $item   = PrivateLabelItem::findById($itemId);
        if ($item === null) {
            $_SESSION['_flash']['error'] = 'Private label item not found.';
            redirect('/private-label');
        }

        $listUrl = '/private-label/manufacturer/' . (int) $item['manufacturer_id'];
        $code    = PrivateLabelPublisher::resolveIdentity($item)['code'];
        $db      = Database::getInstance();

        $history = $db->fetch(
            "SELECT COUNT(*) AS c FROM private_label_sds WHERE item_id = ?",
            [$itemId]
        );
        if ((int) ($history['c'] ?? 0) > 0) {
            $_SESSION['_flash']['error'] = 'This item has published history — retire it instead.';
            redirect($listUrl);
        }

        try {
            $db->query("DELETE FROM private_label_items WHERE id = ?", [$itemId]);
        } catch (\PDOException $e) {
            $_SESSION['_flash']['error'] = (string) $e->getCode() === '23000'
                ? 'This item has published history — retire it instead.'
                : 'Could not delete item: ' . $e->getMessage();
            redirect($listUrl);
        }

        AuditService::log('private_label_item', (string) $itemId, 'delete', [
            'manufacturer_id'    => (int) $item['manufacturer_id'],
            'finished_good_id'   => (int) $item['finished_good_id'],
            'alias_id'           => $item['alias_id'] !== null ? (int) $item['alias_id'] : null,
            'custom_code'        => $item['custom_code'],
            'custom_description' => $item['custom_description'],
            'code'               => $code,
        ]);

        $_SESSION['_flash']['success'] = 'Item ' . $code . ' deleted.';
        redirect($listUrl);
    }

    /**
     * GET /private-label/items/{id}/history — every version of one item.
     */
    public function itemHistory(string $id): void
    {
        $this->requireRead();

        $itemId = (int) $id;
        $item   = PrivateLabelItem::findById($itemId);
        if ($item === null) {
            $_SESSION['_flash']['error'] = 'Private label item not found.';
            redirect('/private-label');
        }

        $languages = self::languages();
        $rows      = $this->buildRows([$item], $languages);
        $row       = $rows[$itemId];

        $versions = Database::getInstance()->fetchAll(
            "SELECT pl.*, u.display_name AS published_by_name
             FROM private_label_sds pl
             LEFT JOIN users u ON u.id = pl.published_by
             WHERE pl.item_id = ?
             ORDER BY pl.version DESC, pl.language ASC",
            [$itemId]
        );

        view('private-label/history', [
            'pageTitle' => 'Private Label History: ' . $row['identity']['code'] . ' / ' . ($item['manufacturer_name'] ?? ''),
            'item'      => $item,
            'row'       => $row,
            'versions'  => $versions,
            'languages' => $languages,
            'langNames' => self::languageNames($languages),
        ]);
    }

    /**
     * POST /private-label/manufacturer/{id}/republish-stale — every active
     * item whose status is stale or never published (regardless of
     * auto_republish: the operator asked explicitly).
     */
    public function republishStale(string $id): void
    {
        $this->requireEdit();
        CSRF::validateRequest();

        $mid          = (int) $id;
        $manufacturer = Manufacturer::findById($mid);
        if ($manufacturer === null) {
            $_SESSION['_flash']['error'] = 'Manufacturer not found.';
            redirect('/private-label');
        }

        $listUrl   = '/private-label/manufacturer/' . $mid;
        $languages = self::languages();

        $ids = [];
        foreach ($this->buildRows(PrivateLabelItem::forManufacturer($mid, false, ''), $languages) as $row) {
            if ((int) $row['item']['is_active'] === 1 && in_array($row['status']['code'], ['stale', 'never'], true)) {
                $ids[] = (int) $row['item']['id'];
            }
        }

        if ($ids === []) {
            $_SESSION['_flash']['info'] = 'No stale or unpublished private label items for ' . $manufacturer['name'] . '.';
            redirect($listUrl);
        }

        $result = (new PrivateLabelPublisher())->republishItems(
            $ids,
            current_user_id(),
            'Republished (stale) from private label page',
            'manufacturer_republish_stale'
        );

        $published = (int) ($result['published'] ?? 0);
        $problems  = array_merge($result['skipped'] ?? [], $result['failed'] ?? []);

        if ($published > 0) {
            $_SESSION['_flash']['success'] = $published . ' of ' . count($ids) . ' private label item(s) republished for '
                . $manufacturer['name'] . ': ' . implode(', ', array_map('strtoupper', $languages)) . '.';
        }
        if ($problems !== []) {
            $_SESSION['_flash'][$published > 0 ? 'warning' : 'error'] = 'Not republished: ' . implode('; ', $problems);
        }
        redirect($listUrl);
    }

    /* ------------------------------------------------------------------
     *  Document download / preview
     * ----------------------------------------------------------------*/

    /**
     * GET /private-label/{id}/download — streams the stored PDF.
     * Filename uses the identity frozen on the row (never re-resolved).
     */
    public function download(string $id): void
    {
        $this->requireRead();

        $db      = Database::getInstance();
        $version = $db->fetch(
            "SELECT pl.*, fg.product_code AS fg_product_code, fg.description AS fg_description,
                    m.name AS manufacturer_name,
                    a.customer_code AS alias_code, a.description AS alias_description
             FROM private_label_sds pl
             JOIN finished_goods fg ON fg.id = pl.finished_good_id
             JOIN manufacturers m ON m.id = pl.manufacturer_id
             LEFT JOIN aliases a ON a.id = pl.alias_id
             WHERE pl.id = ?",
            [(int) $id]
        );

        if ($version === null) {
            $_SESSION['_flash']['error'] = 'Private label SDS not found.';
            redirect('/private-label');
        }

        $backUrl = '/private-label/manufacturer/' . (int) $version['manufacturer_id'];
        $pdfPath = App::basePath() . '/' . (string) $version['pdf_path'];

        if (empty($version['pdf_path']) || !file_exists($pdfPath)) {
            $_SESSION['_flash']['error'] = 'PDF file not found on disk.';
            redirect($backUrl);
        }

        // Audit the primary key of the row actually served (the route segment
        // is matched as [^/]+ and only cast to int for the lookup above).
        AuditService::log('private_label_sds', (string) (int) $version['id'], 'download', [
            'item_id'         => $version['item_id'] !== null ? (int) $version['item_id'] : null,
            'manufacturer_id' => (int) $version['manufacturer_id'],
            'product_code'    => self::frozenCode($version),
            'version'         => (int) $version['version'],
            'language'        => $version['language'],
        ]);

        $filename = 'PL_SDS_' . self::slug(self::frozenCode($version))
                  . '_' . self::slug((string) $version['manufacturer_name'])
                  . '_v' . (int) $version['version']
                  . '_' . $version['language'] . '.pdf';

        $disposition = !empty($_COOKIE['sds_pdf_download']) ? 'attachment' : 'inline';
        header('Content-Type: application/pdf');
        header('Content-Disposition: ' . $disposition . '; filename="' . $filename . '"');
        header('Content-Length: ' . filesize($pdfPath));
        readfile($pdfPath);
        exit;
    }

    /**
     * GET /private-label/{id}/preview — renders the stored snapshot (what
     * the customer received). Falls back to live regeneration only when
     * no snapshot was stored.
     */
    public function preview(string $id): void
    {
        $this->requireRead();

        $db      = Database::getInstance();
        $version = $db->fetch(
            "SELECT pl.*, fg.product_code AS fg_product_code, fg.description AS fg_description,
                    m.name AS manufacturer_name,
                    a.customer_code AS alias_code, a.description AS alias_description
             FROM private_label_sds pl
             JOIN finished_goods fg ON fg.id = pl.finished_good_id
             JOIN manufacturers m ON m.id = pl.manufacturer_id
             LEFT JOIN aliases a ON a.id = pl.alias_id
             WHERE pl.id = ?",
            [(int) $id]
        );

        if ($version === null) {
            $_SESSION['_flash']['error'] = 'Private label SDS not found.';
            redirect('/private-label');
        }

        $code        = self::frozenCode($version);
        $description = self::frozenDescription($version);
        $language    = (string) $version['language'];
        $backUrl     = '/private-label/manufacturer/' . (int) $version['manufacturer_id'];

        $sdsData = null;
        if ($version['snapshot_json'] !== null && $version['snapshot_json'] !== '') {
            $decoded = json_decode((string) $version['snapshot_json'], true);
            if (is_array($decoded) && !empty($decoded['sections'])) {
                $sdsData = $decoded;
            }
        }
        $isSnapshot = $sdsData !== null;

        if ($sdsData === null) {
            try {
                $generator = new SDSGenerator();
                $sdsData   = $generator->generate((int) $version['finished_good_id'], $language);

                $manufacturer = Manufacturer::findById((int) $version['manufacturer_id']);
                $mfgInfo      = $manufacturer !== null ? Manufacturer::toCompanyInfo($manufacturer) : [];

                $sdsData = SDSGenerator::createPrivateLabelVariant($sdsData, $code, $description, $mfgInfo);
            } catch (\Throwable $e) {
                $_SESSION['_flash']['error'] = 'SDS preview failed: ' . $e->getMessage();
                redirect($backUrl);
            }
        }

        // Audit #38 — the preview is the real PDF, rendered from the stored
        // snapshot (or the regenerated data above when none was stored).
        $mode = \SDS\Services\SDSPreviewResponse::mode($_GET);
        if ($mode === \SDS\Services\SDSPreviewResponse::MODE_PDF) {
            try {
                $bytes = (new \SDS\Services\PDFService())->generateString($sdsData);
            } catch (\Throwable $e) {
                $_SESSION['_flash']['error'] = 'SDS preview failed: ' . $e->getMessage();
                redirect($backUrl);
            }
            \SDS\Services\SDSPreviewResponse::send($bytes, \SDS\Services\SDSPreviewResponse::filename($sdsData));
        }

        $storedPdfUrl = null;
        if (!empty($version['pdf_path']) && file_exists(App::basePath() . '/' . (string) $version['pdf_path'])) {
            $storedPdfUrl = '/private-label/' . (int) $version['id'] . '/download';
        }

        $livePreviewUrl = null;
        if ($version['item_id'] !== null) {
            $livePreviewUrl = '/private-label/live-preview?item_id=' . (int) $version['item_id']
                            . '&lang=' . rawurlencode($language);
        }

        view($mode === \SDS\Services\SDSPreviewResponse::MODE_HTML ? 'sds/preview' : 'sds/preview-pdf', [
            'pageTitle'      => 'Private Label SDS v' . (int) $version['version'] . ' (' . strtoupper($language) . ') — '
                                . $code . ' / ' . $version['manufacturer_name']
                                . ($isSnapshot ? ' — published snapshot' : ' — regenerated (no snapshot stored)'),
            'finishedGood'   => ['product_code' => $code],
            'sds'            => $sdsData,
            'language'       => $language,
            'privateLabelId' => (int) $id,
            'backUrl'        => $backUrl,
            'backLabel'      => 'Back to ' . $version['manufacturer_name'],
            'livePreviewUrl' => $livePreviewUrl,
            'storedPdfUrl'   => $storedPdfUrl,
        ]);
    }

    /* ------------------------------------------------------------------
     *  Helpers
     * ----------------------------------------------------------------*/

    private function requireRead(): void
    {
        if (!can_read('private_label')) {
            $_SESSION['_flash']['error'] = 'Permission denied.';
            redirect('/');
        }
    }

    private function requireEdit(): void
    {
        if (!can_edit('private_label')) {
            $_SESSION['_flash']['error'] = 'Permission denied.';
            redirect('/private-label');
        }
    }

    /**
     * Flash the error and the submitted input, then redirect back to the form.
     */
    private function failBack(string $message, string $url): never
    {
        $_SESSION['_flash']['error']      = $message;
        $_SESSION['_flash']['_old_input'] = $_POST;
        redirect($url);
    }

    /**
     * Configured SDS languages (never empty).
     *
     * @return list<string>
     */
    private static function languages(): array
    {
        $langs = App::config('sds.supported_languages', ['en', 'es', 'fr', 'de']);
        if (!is_array($langs) || $langs === []) {
            return ['en'];
        }
        return array_values(array_map('strval', $langs));
    }

    /**
     * @param  list<string> $languages
     * @return array<string, string>  code => display name
     */
    private static function languageNames(array $languages): array
    {
        $names = [];
        foreach ($languages as $lang) {
            $names[$lang] = self::LANGUAGE_NAMES[$lang] ?? strtoupper($lang);
        }
        return $names;
    }

    /**
     * Active finished goods that have a current formula (the only ones a
     * private label item can be created for — validate() enforces the same).
     */
    private function eligibleFinishedGoods(): array
    {
        return Database::getInstance()->fetchAll(
            "SELECT fg.id, fg.product_code, fg.description
             FROM finished_goods fg
             WHERE fg.is_active = 1
               AND EXISTS (SELECT 1 FROM formulas f WHERE f.finished_good_id = fg.id AND f.is_current = 1)
             ORDER BY fg.product_code ASC"
        );
    }

    /**
     * Shared aliases for a finished good, deduplicated by base code.
     *
     * $preferId (the item's stored alias_id on the edit form) is moved to
     * the front of the list before deduplication so it becomes the single
     * representative for its base code. Without this, a CMS sync that adds
     * a pack size sorting before the stored row (e.g. CODE-1G after the
     * item stored CODE-2G) would drop the stored id from the options, the
     * form would force the operator to re-pick the visually identical
     * representative, and that alias_id change would flag the item stale
     * ("identity changed") although nothing printed changed.
     *
     * @return list<array{id:int, code:string, description:string}>
     */
    private static function aliasOptionsForFg(int $fgId, ?int $preferId = null): array
    {
        if ($fgId <= 0) {
            return [];
        }
        $fg = FinishedGood::findById($fgId);
        if ($fg === null) {
            return [];
        }

        $rows = Database::getInstance()->fetchAll(
            "SELECT id, customer_code, description
             FROM aliases
             WHERE internal_code_base = ?
             ORDER BY customer_code ASC",
            [$fg['product_code']]
        );

        if ($preferId !== null && $preferId > 0) {
            foreach ($rows as $idx => $row) {
                if ((int) $row['id'] === $preferId) {
                    unset($rows[$idx]);
                    array_unshift($rows, $row);
                    break;
                }
            }
        }

        $out = [];
        foreach (BulkPublishController::deduplicateAliasesByBaseCode($rows) as $a) {
            $out[] = [
                'id'          => (int) $a['id'],
                'code'        => (string) $a['customer_code'],
                'description' => (string) ($a['description'] ?? ''),
            ];
        }
        return $out;
    }

    /**
     * Normalise the identity inputs according to identity_mode:
     *   base         -> alias_id NULL, custom_code NULL
     *   shared_alias -> custom_code NULL (alias_id required — validated by the model)
     *   custom       -> custom_code as typed (trimmed, verbatim otherwise);
     *                   alias_id optional ("borrow description"), read from
     *                   borrow_alias_id when present, else alias_id.
     * custom_description applies to every mode. '' -> NULL throughout.
     *
     * @return array{identity_mode:string, alias_id:?int, custom_code:?string, custom_description:?string}
     */
    private function identityFields(array $in): array
    {
        $mode = (string) ($in['identity_mode'] ?? 'base');
        if (!in_array($mode, ['base', 'shared_alias', 'custom'], true)) {
            $mode = 'base';
        }

        $aliasRaw = $in['alias_id'] ?? null;
        if ($mode === 'custom' && isset($in['borrow_alias_id']) && (string) $in['borrow_alias_id'] !== '') {
            $aliasRaw = $in['borrow_alias_id'];
        }
        $aliasId = (int) ($aliasRaw ?? 0);
        $aliasId = $aliasId > 0 ? $aliasId : null;

        $customCode = trim((string) ($in['custom_code'] ?? ''));
        $customDesc = trim((string) ($in['custom_description'] ?? ''));

        if ($mode === 'base') {
            $aliasId    = null;
            $customCode = '';
        } elseif ($mode === 'shared_alias') {
            $customCode = '';
        }

        return [
            'identity_mode'      => $mode,
            'alias_id'           => $aliasId,
            'custom_code'        => $customCode !== '' ? $customCode : null,
            'custom_description' => $customDesc !== '' ? $customDesc : null,
        ];
    }

    /**
     * Identity mode implied by a stored item row (for the edit form radios).
     */
    private static function identityModeOf(array $item): string
    {
        if ($item['custom_code'] !== null && trim((string) $item['custom_code']) !== '') {
            return 'custom';
        }
        if ($item['alias_id'] !== null) {
            return 'shared_alias';
        }
        return 'base';
    }

    /**
     * Build a pseudo "joined row" from ad-hoc form fields so the live
     * preview resolves identity exactly like a saved item would.
     */
    private function adHocItem(array $fg, array $manufacturer, array $in): array
    {
        $fields = $this->identityFields($in);

        $alias = null;
        if ($fields['alias_id'] !== null) {
            $alias = Database::getInstance()->fetch(
                "SELECT id, customer_code, description, internal_code_base FROM aliases WHERE id = ?",
                [$fields['alias_id']]
            );
            if ($alias === null || strcasecmp((string) $alias['internal_code_base'], (string) $fg['product_code']) !== 0) {
                throw new \RuntimeException('The selected shared alias does not belong to ' . $fg['product_code'] . '.');
            }
        }

        return [
            'id'                       => 0,
            'manufacturer_id'          => (int) $manufacturer['id'],
            'finished_good_id'         => (int) $fg['id'],
            'alias_id'                 => $alias !== null ? (int) $alias['id'] : null,
            'custom_code'              => $fields['custom_code'],
            'custom_description'       => $fields['custom_description'],
            'is_active'                => 1,
            'auto_republish'           => 1,
            'notes'                    => null,
            'fg_product_code'          => $fg['product_code'],
            'fg_description'           => $fg['description'] ?? '',
            'fg_is_active'             => (int) ($fg['is_active'] ?? 1),
            'manufacturer_name'        => $manufacturer['name'],
            'manufacturer_updated_at'  => $manufacturer['updated_at'] ?? null,
            'manufacturer_logo_path'   => $manufacturer['logo_path'] ?? null,
            'alias_customer_code'      => $alias['customer_code'] ?? null,
            'alias_description'        => $alias['description'] ?? null,
            'alias_internal_code_base' => $alias['internal_code_base'] ?? null,
        ];
    }

    /**
     * Decorate joined item rows with resolved identity, latest-version data
     * and staleness status. One latestByItem query + one
     * fgLatestBaseVersions query for the whole set.
     *
     * @return array<int, array>  keyed by item id, in input order
     */
    private function buildRows(array $items, array $languages): array
    {
        if ($items === []) {
            return [];
        }

        $itemIds = [];
        $fgIds   = [];
        foreach ($items as $it) {
            $itemIds[]                        = (int) $it['id'];
            $fgIds[(int) $it['finished_good_id']] = true;
        }

        $latest   = PrivateLabelItem::latestByItem($itemIds);
        $fgLatest = PrivateLabelItem::fgLatestBaseVersions(array_keys($fgIds));

        $rows = [];
        foreach ($items as $it) {
            $id    = (int) $it['id'];
            $fgId  = (int) $it['finished_good_id'];
            $langs = $latest[$id] ?? [];

            $fgLatestVersion = isset($fgLatest[$fgId]) ? (int) $fgLatest[$fgId] : null;

            $identity = PrivateLabelPublisher::resolveIdentity($it);
            $status   = PrivateLabelItem::status($it, $langs, $fgLatestVersion, $languages);

            // V = max version across languages; date / source / frozen code
            // are taken from the rows at V.
            $v = null;
            foreach ($langs as $plRow) {
                $rv = (int) $plRow['version'];
                if ($v === null || $rv > $v) {
                    $v = $rv;
                }
            }

            $vDate   = null;
            $vSource = null;
            $vCode   = null;
            $vDesc   = null;
            if ($v !== null) {
                foreach ($langs as $plRow) {
                    if ((int) $plRow['version'] !== $v) {
                        continue;
                    }
                    $d = $plRow['published_at'] ?? $plRow['created_at'] ?? null;
                    if ($d !== null && $d !== '' && ($vDate === null || strtotime((string) $d) > strtotime((string) $vDate))) {
                        $vDate = (string) $d;
                    }
                    if ($vSource === null && isset($plRow['source_fg_version']) && $plRow['source_fg_version'] !== null) {
                        $vSource = (int) $plRow['source_fg_version'];
                    }
                    if ($vCode === null && !empty($plRow['product_code'])) {
                        $vCode = (string) $plRow['product_code'];
                    }
                    if ($vDesc === null && !empty($plRow['product_description'])) {
                        $vDesc = (string) $plRow['product_description'];
                    }
                }
            }

            // Preview target: latest row in the first configured language,
            // else the first language that has one.
            $previewId = null;
            foreach ($languages as $lang) {
                if (!empty($langs[$lang]['id'])) {
                    $previewId = (int) $langs[$lang]['id'];
                    break;
                }
            }
            if ($previewId === null) {
                foreach ($langs as $plRow) {
                    if (!empty($plRow['id'])) {
                        $previewId = (int) $plRow['id'];
                        break;
                    }
                }
            }

            $rows[$id] = [
                'item'                     => $it,
                'identity'                 => $identity,
                'status'                   => $status,
                'latest_version'           => $v,
                'latest_date'              => $vDate,
                'latest_source_fg_version' => $vSource,
                'latest_code'              => $vCode,
                'latest_description'       => $vDesc,
                'fg_latest_version'        => $fgLatestVersion,
                'langs'                    => $langs,
                'preview_id'               => $previewId,
                'has_history'              => $langs !== [],
            ];
        }

        return $rows;
    }

    /**
     * Turn a republishItems() result into [success text|null, problems text|null].
     *
     * @return array{0:?string, 1:?string}
     */
    private function summarisePublish(array $result, array $languages): array
    {
        $published = (int) ($result['published'] ?? 0);
        $versions  = $result['versions'] ?? [];
        $problems  = array_merge($result['skipped'] ?? [], $result['failed'] ?? []);
        $langList  = implode(', ', array_map('strtoupper', $languages));

        $success = null;
        if ($published > 0) {
            if (count($versions) === 1) {
                $success = 'private label SDS v' . (int) reset($versions) . ' published: ' . $langList;
            } else {
                $success = $published . ' private label SDS published: ' . $langList;
            }
        }

        return [$success, $problems !== [] ? implode('; ', $problems) : null];
    }

    /**
     * Friendly message for a PDOException raised by a registry write.
     */
    private static function pdoMessage(\PDOException $e): string
    {
        if ((string) $e->getCode() === '23000') {
            return 'This manufacturer already has an item with that code / alias / base product.';
        }
        return 'Database error: ' . $e->getMessage();
    }

    /**
     * The product code printed on a private_label_sds row: the frozen
     * pl.product_code, falling back (legacy rows) to the alias base code,
     * then the FG product code. Expects the row joined with alias_code and
     * fg_product_code.
     */
    private static function frozenCode(array $pl): string
    {
        if (isset($pl['product_code']) && $pl['product_code'] !== null && $pl['product_code'] !== '') {
            return (string) $pl['product_code'];
        }
        if (!empty($pl['alias_code'])) {
            return strip_pack_extension((string) $pl['alias_code']);
        }
        return (string) ($pl['fg_product_code'] ?? '');
    }

    /**
     * The description printed on a private_label_sds row (frozen, with the
     * same legacy fallbacks as frozenCode()).
     */
    private static function frozenDescription(array $pl): string
    {
        if (isset($pl['product_description']) && $pl['product_description'] !== null && $pl['product_description'] !== '') {
            return (string) $pl['product_description'];
        }
        if (!empty($pl['alias_description'])) {
            return (string) $pl['alias_description'];
        }
        return (string) ($pl['fg_description'] ?? '');
    }

    /**
     * Filename-safe slug: runs of non-alphanumerics become "_", trimmed.
     */
    private static function slug(string $value): string
    {
        $slug = trim((string) preg_replace('/[^A-Za-z0-9]+/', '_', $value), '_');
        return $slug !== '' ? $slug : 'SDS';
    }
}
