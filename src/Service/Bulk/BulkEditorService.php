<?php
/**
 * SmartBulk — BulkEditorService
 *
 * Orchestrates deterministic bulk edits:
 *   - preview(): returns before/after diffs for a sample of products
 *   - createBatch(): persists a massedit record + product IDs; returns status
 *   - processNext(): applies actions to a chunk of products, logs diffs
 *   - undo(): restores old values for a completed massedit
 *
 * Works on fields declared in FieldDefinitions. Multi-lang fields can target a
 * specific id_lang or 'all' languages.
 *
 * @author    marcingajewski.pl
 * @copyright 2026 marcingajewski.pl
 * @license   https://opensource.org/licenses/AFL-3.0 AFL-3.0
 */

declare(strict_types=1);

namespace SmartBulk\Service\Bulk;

use Context;
use Db;
use InvalidArgumentException;
use Language;
use RuntimeException;
use SmartBulk\Repository\MasseditRepository;
use SmartBulk\Repository\PromptRepository;
use SmartBulk\Service\Ai\AIService;

final class BulkEditorService
{
    public function __construct(
        private readonly ActionApplier $applier,
        private readonly MasseditRepository $masseditRepo,
        private readonly AIService $aiService,
        private readonly PromptRepository $promptRepo,
    ) {
    }

    /**
     * Preview diffs for the first N products in the given scope.
     *
     * @param int[] $productIds
     * @param array<int,array<string,mixed>> $actions
     * @return array<int,array<string,mixed>>  Per-product diff
     */
    public function preview(array $productIds, array $actions, ?int $idLang = null, int $limit = 10): array
    {
        $ctx  = Context::getContext();
        $lang = $idLang ?? (int) $ctx->language->id;
        $shop = (int) $ctx->shop->id;

        $slice = array_slice(array_values($productIds), 0, max(1, $limit));
        $diffs = [];

        foreach ($slice as $idProduct) {
            $diffs[] = $this->diffProduct((int) $idProduct, $actions, $shop, $lang);
        }

        return $diffs;
    }

    /**
     * Full preview analysis — iterates over ALL productIds (up to MAX_PREVIEW),
     * returns summary, per-field stats, full diff list with flags, and
     * detected same-value collisions across the batch.
     *
     * @param int[]  $productIds
     * @param array<int,array<string,mixed>> $actions
     * @return array<string,mixed> { summary, diffs, truncated }
     */
    public function previewSummary(array $productIds, array $actions, ?int $idLang = null): array
    {
        $ctx  = Context::getContext();
        $lang = $idLang ?? (int) $ctx->language->id;
        $shop = (int) $ctx->shop->id;

        $allIds = array_values($productIds);
        $total  = count($allIds);

        // Detailed diff rows are kept only for a sample (rendering 50k rows is pointless).
        // The change / no-change COUNTS below cover the FULL scope, not this sample.
        $detailLimit   = 1000;
        $detailIds     = array_slice($allIds, 0, $detailLimit);
        $detailSampled = $total > $detailLimit;

        $diffs = [];
        foreach ($detailIds as $idProduct) {
            $diffs[] = $this->diffProduct((int) $idProduct, $actions, $shop, $lang);
        }

        // Sample summary — per-field length averages and collisions come from the
        // detailed sample; the headline counts are overwritten with full-scope numbers.
        $summary = $this->buildSummary($diffs, $actions, $total);

        // REAL full-scope counts (batched) — this is what "will change" must reflect.
        $full = $this->fullScopeCounts($allIds, $actions, $shop, $lang);
        $summary['will_change'] = $full['will_change'];
        $summary['no_change']   = $full['no_change'];
        $summary['with_flags']  = $full['with_flags'];
        foreach ($full['flag_counts'] as $fk => $fv) {
            if ($fk !== 'same_value_batch') {
                $summary['flag_counts'][$fk] = $fv;
            }
        }
        foreach ($summary['by_field'] as &$bf) {
            if (isset($full['by_field_changes'][$bf['field']])) {
                $bf['changes'] = $full['by_field_changes'][$bf['field']];
            }
        }
        unset($bf);

        // Second pass: tag sample diffs that are part of a same_value_batch collision.
        if (!empty($summary['collisions'])) {
            $collisionMap = []; // fid => [value => true]
            foreach ($summary['collisions'] as $col) {
                $collisionMap[$col['field']][$col['value']] = true;
            }
            foreach ($diffs as &$d) {
                foreach ($d['changes'] as &$c) {
                    $fid = (string) $c['field'];
                    $nv  = (string) $c['new'];
                    if (isset($collisionMap[$fid][$nv])) {
                        $flags = (array) ($c['flags'] ?? []);
                        $flags[] = 'same_value_batch';
                        $c['flags'] = array_values(array_unique($flags));
                        $d['has_flags'] = true;
                    }
                }
                unset($c);
            }
            unset($d);
        }

        return [
            'summary'         => $summary,
            'diffs'           => $diffs,
            'total_scope'     => $total,
            'analyzed'        => count($diffs),
            'detail_sampled'  => $detailSampled,
            'count_estimated' => $full['estimated'],
            'truncated'       => $detailSampled,
        ];
    }

    /**
     * Count, across the FULL scope, how many products will actually change vs stay
     * unchanged. Products are loaded in batches (not one query each) so it stays fast
     * at scale. Above a safety cap the counts are extrapolated to avoid timeouts on
     * very large catalogs (flagged via `estimated`).
     *
     * @param int[] $productIds
     * @param array<int,array<string,mixed>> $actions
     * @return array{will_change:int,no_change:int,with_flags:int,flag_counts:array<string,int>,by_field_changes:array<string,int>,counted:int,estimated:bool}
     */
    private function fullScopeCounts(array $productIds, array $actions, int $idShop, int $idLang): array
    {
        $total      = count($productIds);
        $safetyCap  = 100000; // count this many exactly; beyond → extrapolate
        $ids        = $total > $safetyCap ? array_slice($productIds, 0, $safetyCap) : $productIds;
        $estimated  = $total > $safetyCap;

        $flagCounts = [
            'truncated' => 0, 'pattern_mismatch' => 0, 'below_min_clamped' => 0,
            'empty_old' => 0, 'conflict_gt' => 0, 'fallback_to_name' => 0,
            'empty_source' => 0, 'same_value_batch' => 0,
        ];
        $byFieldChanges = [];
        $will = 0; $no = 0; $withFlags = 0; $counted = 0;

        foreach (array_chunk($ids, 2000) as $chunk) {
            $loaded = $this->loadProductFieldsBatch($chunk, $idShop, $idLang);
            foreach ($chunk as $pid) {
                $pid = (int) $pid;
                $counted++;
                $product = $loaded[$pid] ?? null;
                if ($product === null) { $no++; continue; }
                $d = $this->diffProductFromData($pid, $product, $actions, $idShop, $idLang);
                if (empty($d['changes'])) { $no++; continue; }
                $will++;
                if (!empty($d['has_flags'])) $withFlags++;
                foreach ($d['changes'] as $c) {
                    $fid = (string) $c['field'];
                    $byFieldChanges[$fid] = ($byFieldChanges[$fid] ?? 0) + 1;
                    foreach ((array) ($c['flags'] ?? []) as $f) {
                        if (isset($flagCounts[$f])) $flagCounts[$f]++;
                    }
                }
            }
        }

        if ($estimated && $counted > 0) {
            $factor    = $total / $counted;
            $will      = (int) round($will * $factor);
            $no        = $total - $will;
            $withFlags = (int) round($withFlags * $factor);
            foreach ($flagCounts as $k => $v)     { $flagCounts[$k] = (int) round($v * $factor); }
            foreach ($byFieldChanges as $k => $v) { $byFieldChanges[$k] = (int) round($v * $factor); }
        }

        return [
            'will_change'      => $will,
            'no_change'        => $no,
            'with_flags'       => $withFlags,
            'flag_counts'      => $flagCounts,
            'by_field_changes' => $byFieldChanges,
            'counted'          => $counted,
            'estimated'        => $estimated,
        ];
    }

    /**
     * From a selection, return only the product ids that the given actions would ACTUALLY
     * change — using the same batched diff logic as the preview. Lets createBatch snapshot
     * just the products that need work, so the batch (and its progress bar) reflect real
     * changes instead of grinding through the whole selection.
     *
     * @param int[] $productIds
     * @param array<int,array<string,mixed>> $actions
     * @return int[]
     */
    private function changingProductIds(array $productIds, int $idShop, int $idLang, array $actions): array
    {
        $changing = [];
        foreach (array_chunk(array_values($productIds), 2000) as $chunk) {
            $loaded = $this->loadProductFieldsBatch($chunk, $idShop, $idLang);
            foreach ($chunk as $pid) {
                $pid     = (int) $pid;
                $product = $loaded[$pid] ?? null;
                if ($product === null) {
                    continue;
                }
                $d = $this->diffProductFromData($pid, $product, $actions, $idShop, $idLang);
                if (!empty($d['changes'])) {
                    $changing[] = $pid;
                }
            }
        }
        return $changing;
    }

    /**
     * Batch version of loadProductFields — loads all given products in two queries
     * (fields + stock) instead of two per product.
     *
     * @param int[] $ids
     * @return array<int,array<string,mixed>>  keyed by id_product
     */
    private function loadProductFieldsBatch(array $ids, int $idShop, int $idLang): array
    {
        if (!$ids) return [];
        $prefix = _DB_PREFIX_;
        $idList = implode(',', array_map('intval', $ids));

        $rows = Db::getInstance()->executeS(
            "SELECT p.id_product, p.reference, p.ean13, p.upc, p.mpn, p.isbn,
                    p.weight, p.width, p.height, p.depth, p.additional_shipping_cost,
                    p.condition, p.ecotax, p.low_stock_threshold, p.low_stock_alert,
                    p.unit_price_ratio, p.online_only, p.available_date,
                    p.id_manufacturer, p.id_supplier, p.id_category_default,
                    p.id_tax_rules_group,
                    ps.active, ps.price, ps.wholesale_price, ps.available_for_order,
                    ps.show_price, ps.visibility, ps.minimal_quantity, ps.on_sale,
                    pl.name, pl.description, pl.description_short, pl.meta_title,
                    pl.meta_description, pl.link_rewrite, pl.available_now, pl.available_later,
                    pl.delivery_in_stock, pl.delivery_out_stock
             FROM `{$prefix}product` p
             LEFT JOIN `{$prefix}product_shop` ps
                  ON ps.id_product = p.id_product AND ps.id_shop = {$idShop}
             LEFT JOIN `{$prefix}product_lang` pl
                  ON pl.id_product = p.id_product AND pl.id_shop = {$idShop} AND pl.id_lang = {$idLang}
             WHERE p.id_product IN ({$idList})"
        ) ?: [];

        $stockRows = Db::getInstance()->executeS(
            "SELECT id_product, quantity FROM `{$prefix}stock_available`
             WHERE id_product IN ({$idList}) AND id_product_attribute = 0 AND id_shop = {$idShop}"
        ) ?: [];
        $stock = [];
        foreach ($stockRows as $s) { $stock[(int) $s['id_product']] = (int) $s['quantity']; }

        $out = [];
        foreach ($rows as $r) {
            $r['quantity'] = $stock[(int) $r['id_product']] ?? 0;
            $out[(int) $r['id_product']] = $r;
        }
        return $out;
    }

    /**
     * @param array<int,array<string,mixed>> $diffs
     * @param array<int,array<string,mixed>> $actions
     * @return array<string,mixed>
     */
    private function buildSummary(array $diffs, array $actions, int $totalScope): array
    {
        $willChange = 0;
        $noChange   = 0;
        $withFlags  = 0;
        $flagCounts = [
            'truncated'         => 0,
            'pattern_mismatch'  => 0,
            'below_min_clamped' => 0,
            'empty_old'         => 0,
            'conflict_gt'       => 0,
            'fallback_to_name'  => 0,
            'empty_source'      => 0,
            'same_value_batch'  => 0,
        ];

        /** @var array<string,array<string,mixed>> $byField keyed by field id */
        $byField = [];
        foreach ($actions as $action) {
            $fid = (string) ($action['field'] ?? '');
            if ($fid === '') continue;
            $op = (string) ($action['operator'] ?? 'skip');
            if ($op === 'skip') continue;
            $def = FieldDefinitions::find($fid);
            if ($def === null) continue;
            $byField[$fid] = [
                'field'       => $fid,
                'field_label' => (string) $def['label'],
                'type'        => (string) $def['type'],
                'changes'     => 0,
                'old_len_sum' => 0,
                'new_len_sum' => 0,
                'over_limit'  => 0,
                'clamped'     => 0,
                'mismatches'  => 0,
                'char_limit'  => isset($def['char_limit']) ? (int) $def['char_limit'] : null,
            ];
        }

        // Same-value detection: only for text fields with warn_same_value_on_batch,
        // and for 'set' operator (the only one where a constant collides).
        $setValuesPerField = []; // fid => [newValue => [idProduct...]]

        foreach ($diffs as $d) {
            if (empty($d['changes'])) { $noChange++; continue; }
            $willChange++;
            if (!empty($d['has_flags'])) $withFlags++;

            foreach ($d['changes'] as $c) {
                $fid = (string) $c['field'];
                if (isset($byField[$fid])) {
                    $byField[$fid]['changes']++;
                    if ($c['old_len'] !== null) $byField[$fid]['old_len_sum'] += (int) $c['old_len'];
                    if ($c['new_len'] !== null) $byField[$fid]['new_len_sum'] += (int) $c['new_len'];
                    foreach ((array) ($c['flags'] ?? []) as $f) {
                        if ($f === 'truncated')         $byField[$fid]['over_limit']++;
                        if ($f === 'below_min_clamped') $byField[$fid]['clamped']++;
                        if ($f === 'pattern_mismatch')  $byField[$fid]['mismatches']++;
                        if (isset($flagCounts[$f]))     $flagCounts[$f]++;
                    }
                }

                // same-value tracking
                $def = FieldDefinitions::find($fid);
                if ($def !== null && !empty($def['warn_same_value_on_batch'])) {
                    $setValuesPerField[$fid][(string) $c['new']][] = (int) $d['id_product'];
                }
            }
        }

        // Finalize per-field (average lengths)
        $byFieldOut = [];
        foreach ($byField as $row) {
            $avgOld = $row['changes'] > 0 ? (int) round($row['old_len_sum'] / max(1, $row['changes'])) : null;
            $avgNew = $row['changes'] > 0 ? (int) round($row['new_len_sum'] / max(1, $row['changes'])) : null;
            $byFieldOut[] = [
                'field'       => $row['field'],
                'field_label' => $row['field_label'],
                'type'        => $row['type'],
                'char_limit'  => $row['char_limit'],
                'changes'     => $row['changes'],
                'avg_old_len' => $avgOld,
                'avg_new_len' => $avgNew,
                'over_limit'  => $row['over_limit'],
                'clamped'     => $row['clamped'],
                'mismatches'  => $row['mismatches'],
            ];
        }

        // Same-value collisions: {field, value, product_count, products:[...]}
        $collisions = [];
        $sameValueCount = 0;
        foreach ($setValuesPerField as $fid => $vmap) {
            foreach ($vmap as $newVal => $prodIds) {
                if (count($prodIds) >= 2) {
                    $def = FieldDefinitions::find($fid);
                    $collisions[] = [
                        'field'         => $fid,
                        'field_label'   => $def ? (string) $def['label'] : $fid,
                        'value'         => $newVal,
                        'product_count' => count($prodIds),
                        'products'      => array_slice($prodIds, 0, 20),
                    ];
                    $sameValueCount += count($prodIds);
                }
            }
        }
        $flagCounts['same_value_batch'] = $sameValueCount;

        return [
            'total_scope'  => $totalScope,
            'will_change'  => $willChange,
            'no_change'    => $noChange,
            'with_flags'   => $withFlags,
            'flag_counts'  => $flagCounts,
            'by_field'     => $byFieldOut,
            'collisions'   => $collisions,
        ];
    }

    /**
     * Create a bulk-edit batch. Product IDs, action spec and id_lang are snapshot-ed.
     *
     * @param int[] $productIds
     * @param array<int,array<string,mixed>> $actions
     * @param array<string,mixed>|null $filterSpec  for audit (can be null)
     * @return array<string,mixed> Batch status
     */
    public function createBatch(array $productIds, array $actions, ?int $idLang = null, ?array $filterSpec = null, string $mode = 'apply'): array
    {
        if (!$actions) {
            throw new InvalidArgumentException('No actions supplied');
        }
        $productIds = array_values(array_unique(array_map('intval', $productIds)));
        $productIds = array_values(array_filter($productIds, static fn ($n) => $n > 0));
        if (!$productIds) {
            throw new InvalidArgumentException('Empty product list');
        }
        if (!in_array($mode, ['apply', 'dry_run'], true)) {
            throw new InvalidArgumentException("mode must be 'apply' or 'dry_run', got '{$mode}'");
        }

        $ctx  = Context::getContext();
        $lang = $idLang ?? (int) $ctx->language->id;
        $shop = (int) $ctx->shop->id;

        // Narrow the batch to products the actions will ACTUALLY change, so the batch —
        // and its progress bar — reflect real work instead of grinding through the whole
        // selection (e.g. "add tag X" to 2500 products where 2450 already have it → a batch
        // of ~50, not 2500). Same change-detection as the preview. Only when the scope is
        // exactly analyzable (<= 100000, the preview's exact-count threshold); above that the
        // preview extrapolates and we can't know the exact set, so the full selection is kept
        // and processed with progress as before.
        $originalScope = count($productIds);
        if ($originalScope <= 100000) {
            $changing = $this->changingProductIds($productIds, $shop, $lang, $actions);
            if (!$changing) {
                throw new InvalidArgumentException('Nothing to change — every selected product already matches the requested actions.');
            }
            $productIds = $changing;
        }

        $idBatch = $this->masseditRepo->create([
            'id_shop'          => $shop,
            'segment_snapshot' => [
                'product_ids'    => $productIds,
                'filter_spec'    => $filterSpec,
                'id_lang'        => $lang,
                'original_scope' => $originalScope,
            ],
            'action_snapshot'  => [
                'kind'    => 'bulk_edit',
                'actions' => $actions,
                'mode'    => $mode,
            ],
            'mode'             => $mode,
            'status'           => 'pending',
            'products_matched' => count($productIds),
            'id_employee'      => $ctx->employee ? (int) $ctx->employee->id : null,
        ]);

        return $this->getStatus($idBatch);
    }

    /**
     * Process a chunk of the batch — apply actions to up to $limit products.
     * Persists the old→new diff to smartbulk_massedit_log for each field.
     */
    public function processNext(int $idBatch, int $limit): array
    {
        $batch = $this->masseditRepo->find($idBatch);
        if ($batch === null) {
            throw new InvalidArgumentException("Batch {$idBatch} not found");
        }

        // Don't resume a batch that was already finalised or cancelled (cancel() sets
        // partial/failed). Without this a stray/late process-next keeps writing after "Stop".
        if (in_array((string) $batch['status'], ['success', 'failed', 'partial', 'undone'], true)) {
            return $this->getStatus($idBatch);
        }
        if ($batch['status'] === 'pending') {
            $this->masseditRepo->updateStatus($idBatch, 'running');
        }

        $snapshot = $batch['segment_snapshot'] ? json_decode((string) $batch['segment_snapshot'], true) : null;
        $actionSnapshot = $batch['action_snapshot'] ? json_decode((string) $batch['action_snapshot'], true) : null;

        if (!is_array($snapshot) || empty($snapshot['product_ids']) || !is_array($actionSnapshot) || empty($actionSnapshot['actions'])) {
            $this->masseditRepo->updateStatus($idBatch, 'failed');
            throw new RuntimeException('Batch snapshot is missing products or actions');
        }

        $idLang  = (int) ($snapshot['id_lang'] ?? Context::getContext()->language->id);
        $idShop  = (int) $batch['id_shop'];
        $allIds  = array_map('intval', $snapshot['product_ids']);
        $actions = $actionSnapshot['actions'];

        // Cursor: advance an offset into the snapshot instead of diffing the log
        // (O(1) per chunk, and correctly advances past unchanged products too).
        $offset  = (int) ($batch['processed_offset'] ?? 0);
        $ceiling = 1000; // hard safety ceiling per single request
        $size    = max(1, min($ceiling, $limit));
        $chunk   = array_slice($allIds, $offset, $size);

        $changedThisTurn = 0;
        $failedThisTurn  = 0;
        $itemsThisTurn   = [];

        foreach ($chunk as $idProduct) {
            try {
                $itemResult = $this->applyToProduct((int) $idProduct, $actions, $idShop, $idLang, $idBatch);
                $itemsThisTurn[] = $itemResult;
                if ($itemResult['changed'] > 0) $changedThisTurn++;
            } catch (\Throwable $e) {
                $failedThisTurn++;
                $this->logError($idBatch, (int) $idProduct, $e->getMessage());
                $itemsThisTurn[] = [
                    'id_product' => (int) $idProduct,
                    'changed'    => 0,
                    'error'      => $e->getMessage(),
                ];
            }
        }

        $newOffset = $offset + count($chunk);
        $this->masseditRepo->incrementCounters($idBatch, $changedThisTurn, $failedThisTurn);
        $this->masseditRepo->setProcessedOffset($idBatch, $newOffset);

        if ($newOffset >= count($allIds)) {
            $b = $this->masseditRepo->find($idBatch);
            $totFailed  = (int) ($b['products_failed'] ?? 0);
            $totChanged = (int) ($b['products_changed'] ?? 0);
            $final = $totFailed > 0 ? ($totChanged > 0 ? 'partial' : 'failed') : 'success';
            $this->masseditRepo->updateStatus($idBatch, $final);
        }

        $status = $this->getStatus($idBatch);
        $status['items_this_turn'] = $itemsThisTurn;
        return $status;
    }

    /**
     * Undo all changes from a completed/partial batch.
     * Restores old_value for each log row, in reverse order.
     */
    public function undo(int $idBatch): array
    {
        $batch = $this->masseditRepo->find($idBatch);
        if ($batch === null) {
            throw new InvalidArgumentException("Batch {$idBatch} not found");
        }
        if ((string) $batch['status'] === 'undone') {
            throw new InvalidArgumentException('Batch is already undone');
        }
        if ((string) ($batch['mode'] ?? 'apply') === 'dry_run') {
            throw new InvalidArgumentException('Dry-run batches have nothing to undo');
        }

        $idShop = (int) $batch['id_shop'];
        $logRows = Db::getInstance()->executeS(
            'SELECT * FROM `' . _DB_PREFIX_ . 'smartbulk_massedit_log`
             WHERE id_massedit = ' . (int) $idBatch . '
               AND status = "changed"
             ORDER BY id_log DESC'
        ) ?: [];

        $restored = 0;
        $errors   = [];
        foreach ($logRows as $row) {
            try {
                if ((string) $row['field'] === '_feature') {
                    $this->undoFeature((int) $row['id_product'], (string) $row['new_value']);
                } elseif ((string) $row['field'] === '_alt_text') {
                    $this->undoAltText((int) $row['id_product'], $row['id_lang'] !== null ? (int) $row['id_lang'] : 0, (string) $row['old_value']);
                } elseif ((string) $row['field'] === '_tags') {
                    $this->undoTags((int) $row['id_product'], $row['id_lang'] !== null ? (int) $row['id_lang'] : 0, (string) $row['old_value']);
                } else {
                    $this->writeField(
                        (int) $row['id_product'],
                        (string) $row['field'],
                        $this->reverseUnwrap($row['old_value']),
                        $row['id_lang'] !== null ? (int) $row['id_lang'] : null,
                        $idShop
                    );
                }
                $restored++;
            } catch (\Throwable $e) {
                $errors[] = "Product {$row['id_product']} / {$row['field']}: {$e->getMessage()}";
            }
        }

        $this->masseditRepo->updateStatus($idBatch, 'undone');

        return [
            'id_batch'  => $idBatch,
            'restored'  => $restored,
            'errors'    => $errors,
            'status'    => 'undone',
        ];
    }

    // =====================================================================
    // Status / queries
    // =====================================================================

    /** @return array<string,mixed> */
    /**
     * Mark a running/pending batch as cancelled. Already-processed products
     * stay applied (and undoable); the remaining ones simply won't be picked
     * up by future processNext() calls. Failsafe — also called when the
     * client decides to abort an in-flight batch.
     */
    public function cancel(int $idBatch): array
    {
        $batch = $this->masseditRepo->find($idBatch);
        if ($batch === null) {
            throw new InvalidArgumentException("Batch {$idBatch} not found");
        }
        $status = (string) $batch['status'];
        if (!in_array($status, ['pending', 'running'], true)) {
            throw new InvalidArgumentException("Batch {$idBatch} cannot be cancelled (status: {$status})");
        }
        // Use 'partial' if any products were already changed, else 'failed'.
        $finalStatus = (int) $batch['products_changed'] > 0 ? 'partial' : 'failed';
        $this->masseditRepo->updateStatus($idBatch, $finalStatus);
        return $this->getStatus($idBatch);
    }

    /**
     * Resume a stopped/interrupted batch — flips it back to 'running' so the runner
     * (or processNext) continues from processed_offset. Only valid when work remains.
     */
    public function resume(int $idBatch): array
    {
        $batch = $this->masseditRepo->find($idBatch);
        if ($batch === null) {
            throw new InvalidArgumentException("Batch {$idBatch} not found");
        }
        if ((string) $batch['status'] === 'undone') {
            throw new InvalidArgumentException('An undone batch cannot be resumed');
        }
        $snapshot = $batch['segment_snapshot'] ? json_decode((string) $batch['segment_snapshot'], true) : [];
        $total = is_array($snapshot) ? count($snapshot['product_ids'] ?? []) : 0;
        $done  = (int) ($batch['processed_offset'] ?? 0);
        if ($done >= $total) {
            throw new InvalidArgumentException('Nothing left to resume');
        }
        $this->masseditRepo->updateStatus($idBatch, 'running');
        return $this->getStatus($idBatch);
    }

    public function getStatus(int $idBatch): array
    {
        $batch = $this->masseditRepo->find($idBatch);
        if ($batch === null) {
            throw new InvalidArgumentException("Batch {$idBatch} not found");
        }
        $snapshot = $batch['segment_snapshot'] ? json_decode((string) $batch['segment_snapshot'], true) : [];
        $action   = $batch['action_snapshot']  ? json_decode((string) $batch['action_snapshot'],  true) : [];

        $allIds = is_array($snapshot) ? array_map('intval', $snapshot['product_ids'] ?? []) : [];
        $total  = count($allIds);
        $done   = min($total, (int) ($batch['processed_offset'] ?? 0));

        return [
            'id_batch'         => (int) $batch['id_massedit'],
            'status'           => (string) $batch['status'],
            'mode'             => (string) $batch['mode'],
            'total'            => $total,
            'done'             => $done,
            'remaining'        => max(0, $total - $done),
            'products_changed' => (int) $batch['products_changed'],
            'products_failed'  => (int) $batch['products_failed'],
            'id_lang'          => (int) ($snapshot['id_lang'] ?? 0),
            'actions'          => is_array($action) ? ($action['actions'] ?? []) : [],
            'started_at'       => $batch['started_at'],
            'finished_at'      => $batch['finished_at'] ?? null,
        ];
    }

    /** @return array<int,array<string,mixed>> */
    public function listItems(int $idBatch): array
    {
        $rows = Db::getInstance()->executeS(
            'SELECT id_product,
                    COUNT(*) AS change_count,
                    SUM(CASE WHEN status = "changed" THEN 1 ELSE 0 END) AS changed,
                    SUM(CASE WHEN status = "failed"  THEN 1 ELSE 0 END) AS failed,
                    MAX(changed_at) AS last_change
             FROM `' . _DB_PREFIX_ . 'smartbulk_massedit_log`
             WHERE id_massedit = ' . (int) $idBatch . '
             GROUP BY id_product
             ORDER BY MAX(id_log) DESC
             LIMIT 100'
        ) ?: [];

        return array_map(static fn (array $r) => [
            'id_product'   => (int) $r['id_product'],
            'change_count' => (int) $r['change_count'],
            'changed'      => (int) $r['changed'],
            'failed'       => (int) $r['failed'],
            'last_change'  => $r['last_change'],
        ], $rows);
    }

    // =====================================================================
    // Internals
    // =====================================================================

    /**
     * @param array<int,array<string,mixed>> $actions
     * @return array<string,mixed> diff row for preview
     */
    private function diffProduct(int $idProduct, array $actions, int $idShop, int $idLang): array
    {
        return $this->diffProductFromData(
            $idProduct,
            $this->loadProductFields($idProduct, $idShop, $idLang),
            $actions,
            $idShop,
            $idLang
        );
    }

    /**
     * Compute a diff row from already-loaded product field data — lets the full-scope
     * counter analyse products in batches without a query per product.
     *
     * @param array<string,mixed> $product
     * @param array<int,array<string,mixed>> $actions
     * @return array<string,mixed>
     */
    private function diffProductFromData(int $idProduct, array $product, array $actions, int $idShop, int $idLang): array
    {
        $changes = [];

        foreach ($actions as $action) {
            $fieldId = (string) ($action['field'] ?? '');
            $def = FieldDefinitions::find($fieldId);
            if ($def === null) continue;
            $op = (string) ($action['operator'] ?? 'skip');
            if ($op === 'skip') continue;

            // Virtual fields: cechy produktu
            if ($fieldId === '_feature') {
                $featureChange = $this->diffFeature($idProduct, $action, $op, $idLang);
                if ($featureChange !== null) {
                    $changes[] = $featureChange;
                }
                continue;
            }

            // Virtual field: tagi produktu
            if ($fieldId === '_tags') {
                $tagChange = $this->diffTags($idProduct, $action, $op, $idLang);
                if ($tagChange !== null) {
                    $changes[] = $tagChange;
                }
                continue;
            }

            $oldValue = $product[$fieldId] ?? null;
            $computed = $this->computeNewValueWithFlags($fieldId, $op, $oldValue, $action, $def, $product);
            $newValue = $computed['value'];
            $flags    = $computed['flags'];

            if ($this->valueEqual($oldValue, $newValue, (string) $def['type'])) continue;

            // Contextual flags
            if ($this->isEmptyValue($oldValue) && in_array($op, ['append', 'prepend', 'replace'], true)) {
                $flags[] = 'empty_old';
            }
            if (isset($def['pattern']) && is_string($newValue) && $newValue !== '') {
                $pattern = '/' . str_replace('/', '\\/', (string) $def['pattern']) . '/u';
                if (@preg_match($pattern, $newValue) === 0) {
                    $flags[] = 'pattern_mismatch';
                }
            }
            if (isset($def['warn_if_greater_than']['field'])) {
                $otherField = (string) $def['warn_if_greater_than']['field'];
                if (isset($product[$otherField]) && is_numeric($product[$otherField]) && is_numeric($newValue)) {
                    if ((float) $newValue > (float) $product[$otherField]) {
                        $flags[] = 'conflict_gt';
                    }
                }
            }

            $isAiPlaceholder = in_array('ai_generate', $flags, true);
            $changes[] = [
                'field'       => $fieldId,
                'field_label' => (string) $def['label'],
                'old'         => $this->valueForDisplay($oldValue),
                'new'         => $this->valueForDisplay($newValue),
                'type'        => (string) $def['type'],
                'flags'       => array_values(array_unique($flags)),
                'old_len'     => (string) $def['type'] === 'text' ? mb_strlen($this->valueForDisplay($oldValue)) : null,
                // For ai_generate the "new" value is just a placeholder marker — the real
                // length is unknown until AI runs, so don't expose a misleading char count.
                'new_len'     => ((string) $def['type'] === 'text' && !$isAiPlaceholder)
                    ? mb_strlen($this->valueForDisplay($newValue))
                    : null,
            ];
        }

        return [
            'id_product' => $idProduct,
            'name'       => (string) ($product['name'] ?? ''),
            'reference'  => (string) ($product['reference'] ?? ''),
            'price'      => (float) ($product['price'] ?? 0),
            'changes'    => $changes,
            'has_flags'  => $this->changesHaveFlags($changes),
        ];
    }

    /** @param mixed $v */
    private function isEmptyValue($v): bool
    {
        if ($v === null) return true;
        if (is_string($v)) return trim($v) === '';
        return false;
    }

    /** @param array<int,array<string,mixed>> $changes */
    private function changesHaveFlags(array $changes): bool
    {
        foreach ($changes as $c) {
            if (!empty($c['flags'])) return true;
        }
        return false;
    }

    /**
     * Delegates to ActionApplier by default; handles special operators that need
     * context beyond the old value (currently: generate_from_name for link_rewrite).
     *
     * @param mixed $oldValue
     * @param array<string,mixed> $action
     * @param array<string,mixed> $def
     * @param array<string,mixed> $product
     * @return mixed
     */
    private function computeNewValue(
        string $fieldId,
        string $op,
        $oldValue,
        array $action,
        array $def,
        array $product
    ) {
        return $this->computeNewValueWithFlags($fieldId, $op, $oldValue, $action, $def, $product)['value'];
    }

    /**
     * @param mixed $oldValue
     * @param array<string,mixed> $action
     * @param array<string,mixed> $def
     * @param array<string,mixed> $product
     * @return array{value: mixed, flags: string[]}
     */
    private function computeNewValueWithFlags(
        string $fieldId,
        string $op,
        $oldValue,
        array $action,
        array $def,
        array $product
    ): array {
        if ($op === 'ai_generate') {
            $promptId = (int) ($action['prompt_id'] ?? 0);
            $prompt = $promptId > 0 ? $this->promptRepo->findPrompt($promptId) : null;
            $promptName = $prompt ? (string) $prompt['name'] : "#{$promptId}";
            return [
                'value' => '[✨ AI will generate using "' . $promptName . '"]',
                'flags' => ['ai_generate'],
            ];
        }
        if ($op === 'copy_from') {
            $sourceField = (string) ($action['source_field'] ?? '');
            if ($sourceField === '' || !array_key_exists($sourceField, $product)) {
                return ['value' => $oldValue, 'flags' => ['copy_from_invalid']];
            }
            $copied = $product[$sourceField];
            // Coerce to match target field type so downstream equal/write logic is consistent.
            $type = (string) $def['type'];
            if ($type === 'text') {
                $value = (string) ($copied ?? '');
            } elseif ($type === 'numeric') {
                $value = is_numeric($copied) ? (float) $copied : 0.0;
            } elseif ($type === 'int') {
                $value = is_numeric($copied) ? (int) $copied : 0;
            } else {
                $value = $copied;
            }
            $flags = $this->isEmptyValue($copied) ? ['empty_source'] : [];
            return ['value' => $value, 'flags' => $flags];
        }
        if ($op === 'generate_from_name' && $fieldId === 'link_rewrite') {
            $name = (string) ($product['name'] ?? '');
            $slug = \Tools::str2url($name);
            $value = $slug !== '' && $slug !== false ? $slug : (string) ($oldValue ?? '');
            return ['value' => $value, 'flags' => []];
        }
        if ($op === 'generate_from_name' && $fieldId === 'meta_title') {
            $name  = (string) ($product['name'] ?? '');
            $limit = (int) ($def['char_limit'] ?? 60);
            if ($name === '') return ['value' => (string) ($oldValue ?? ''), 'flags' => []];
            $new = $this->truncate($name, $limit);
            $flags = mb_strlen($name) > $limit ? ['truncated'] : [];
            return ['value' => $new, 'flags' => $flags];
        }
        if ($op === 'generate_from_short_desc' && $fieldId === 'meta_description') {
            $short = trim(strip_tags((string) ($product['description_short'] ?? '')));
            $short = preg_replace('/\s+/u', ' ', $short) ?? $short;
            $limit = (int) ($def['char_limit'] ?? 160);
            if ($short === '') {
                $name = (string) ($product['name'] ?? '');
                if ($name === '') return ['value' => (string) ($oldValue ?? ''), 'flags' => ['empty_source']];
                return ['value' => $this->truncate($name, $limit), 'flags' => ['fallback_to_name']];
            }
            $new = $this->truncate($short, $limit);
            $flags = mb_strlen($short) > $limit ? ['truncated'] : [];
            return ['value' => $new, 'flags' => $flags];
        }
        return $this->applier->applyWithFlags($oldValue, $action, (string) $def['type'], $def);
    }

    private function truncate(string $value, int $limit): string
    {
        if ($limit <= 0) return $value;
        if (function_exists('mb_strlen') && mb_strlen($value) <= $limit) return $value;
        if (!function_exists('mb_strlen') && strlen($value) <= $limit) return $value;
        $cut = function_exists('mb_substr') ? mb_substr($value, 0, $limit) : substr($value, 0, $limit);
        // Try to not cut mid-word.
        $lastSpace = function_exists('mb_strrpos') ? mb_strrpos($cut, ' ') : strrpos($cut, ' ');
        if ($lastSpace !== false && $lastSpace > $limit * 0.6) {
            $cut = function_exists('mb_substr') ? mb_substr($cut, 0, $lastSpace) : substr($cut, 0, $lastSpace);
        }
        return rtrim($cut);
    }

    /**
     * Apply all actions to a single product. Writes to DB + logs each diff.
     *
     * @param array<int,array<string,mixed>> $actions
     * @return array{id_product:int,changed:int,diffs:array<int,array<string,mixed>>}
     */
    private function applyToProduct(int $idProduct, array $actions, int $idShop, int $idLang, int $idBatch): array
    {
        $batch = $this->masseditRepo->find($idBatch);
        $isDryRun = $batch && (string) ($batch['mode'] ?? 'apply') === 'dry_run';

        $product = $this->loadProductFields($idProduct, $idShop, $idLang);
        $diffs = [];
        $changed = 0;

        foreach ($actions as $action) {
            $fieldId = (string) ($action['field'] ?? '');
            $def = FieldDefinitions::find($fieldId);
            if ($def === null) continue;
            $op = (string) ($action['operator'] ?? 'skip');
            if ($op === 'skip') continue;

            // Virtual fields: cechy produktu — własna logika zapisu
            if ($fieldId === '_feature') {
                $result = $this->applyFeature($idProduct, $action, $op, $idLang, $isDryRun);
                if ($result === null) continue;
                if (!$isDryRun) {
                    // logujemy z polem '_feature' i ze structured JSON value
                    $this->logChange($idBatch, $idProduct, '_feature', $result['old'], $result['new'], null);
                }
                $diffs[] = ['field' => '_feature', 'old' => (string) $result['old'], 'new' => (string) $result['new']];
                $changed++;
                continue;
            }

            // Virtual field: tagi — własna logika zapisu. old_value trzyma snapshot
            // starych tagów ({old_tags:[…]}) który undoTags() przywraca; new_value
            // to czytelne podsumowanie zmiany.
            if ($fieldId === '_tags') {
                $result = $this->applyTags($idProduct, $action, $op, $idLang, $isDryRun);
                if ($result === null) continue;
                if (!$isDryRun) {
                    $this->logChange($idBatch, $idProduct, '_tags', $result['old'], $result['new'], $idLang);
                }
                $diffs[] = ['field' => '_tags', 'old' => (string) ($result['old_display'] ?? ''), 'new' => (string) $result['new']];
                $changed++;
                continue;
            }

            $oldValue = $product[$fieldId] ?? null;

            if ($op === 'ai_generate') {
                if ($isDryRun) {
                    // Dry-run must not spend money — never call the provider here.
                    $newValue = '[AI would generate on apply]';
                } else {
                    $newValue = $this->runAiGenerate($fieldId, $action, $def, $idProduct, $idLang, $idBatch);
                }
            } else {
                $newValue = $this->computeNewValue($fieldId, $op, $oldValue, $action, $def, $product);
            }

            if ($this->valueEqual($oldValue, $newValue, (string) $def['type'])) continue;

            $targetLang = (bool) $def['lang'] ? $idLang : null;

            if (!$isDryRun) {
                // Persist (skipped in dry-run so we only log what would change).
                $this->writeField($idProduct, $fieldId, $newValue, $targetLang, $idShop);
            }

            // Log either way — dry-run rows are clearly marked via batch.mode
            // so undo doesn't try to revert a write that never happened.
            $this->logChange($idBatch, $idProduct, $fieldId, $oldValue, $newValue, $targetLang);
            $diffs[] = [
                'field' => $fieldId,
                'old'   => $this->valueForDisplay($oldValue),
                'new'   => $this->valueForDisplay($newValue),
            ];
            $changed++;
        }

        // Bump date_upd once per product (not per field) so "last modified" and any
        // downstream cache/indexing see the change. Skipped in dry-run.
        if (!$isDryRun && $changed > 0) {
            $p = _DB_PREFIX_;
            Db::getInstance()->execute("UPDATE `{$p}product` SET date_upd = NOW() WHERE id_product = " . (int) $idProduct);
            Db::getInstance()->execute("UPDATE `{$p}product_shop` SET date_upd = NOW() WHERE id_product = " . (int) $idProduct . " AND id_shop = " . (int) $idShop);
            // Invalidate the stale Content Health cache for this product (bulk bypasses the
            // actionProductUpdate hook that would normally refresh it) — forces a re-score.
            Db::getInstance()->execute("DELETE FROM `{$p}smartbulk_health_product` WHERE id_product = " . (int) $idProduct . " AND id_shop = " . (int) $idShop);
        }

        return [
            'id_product' => $idProduct,
            'changed'    => $changed,
            'diffs'      => $diffs,
        ];
    }

    /**
     * Run AI generation for a single product+field; return generated string.
     * @param array<string,mixed> $action
     * @param array<string,mixed> $def
     */
    private function runAiGenerate(string $fieldId, array $action, array $def, int $idProduct, int $idLang, int $idBatch): string
    {
        $promptId = (int) ($action['prompt_id'] ?? 0);
        if ($promptId <= 0) {
            throw new RuntimeException("ai_generate on field '{$fieldId}' requires prompt_id");
        }
        $versionId = isset($action['prompt_version_id']) && $action['prompt_version_id'] !== null
            ? (int) $action['prompt_version_id']
            : null;

        $result = $this->aiService->generate($promptId, $idProduct, $idLang, $versionId, $idBatch);
        $output = (string) ($result['output'] ?? '');

        // Honor char_limit if the field has one (e.g. meta_title 60, meta_description 160).
        if (isset($def['char_limit'])) {
            $output = $this->truncate($output, (int) $def['char_limit']);
        }

        return $output;
    }

    /**
     * Load the subset of product fields we care about, for current lang/shop.
     * @return array<string,mixed>
     */
    private function loadProductFields(int $idProduct, int $idShop, int $idLang): array
    {
        $prefix = _DB_PREFIX_;

        $row = Db::getInstance()->getRow(
            "SELECT p.id_product, p.reference, p.ean13, p.upc, p.mpn, p.isbn,
                    p.weight, p.width, p.height, p.depth, p.additional_shipping_cost,
                    p.condition, p.ecotax, p.low_stock_threshold, p.low_stock_alert,
                    p.unit_price_ratio, p.online_only, p.available_date,
                    p.id_manufacturer, p.id_supplier, p.id_category_default,
                    p.id_tax_rules_group,
                    ps.active, ps.price, ps.wholesale_price, ps.available_for_order,
                    ps.show_price, ps.visibility, ps.minimal_quantity, ps.on_sale,
                    pl.name, pl.description, pl.description_short, pl.meta_title,
                    pl.meta_description, pl.link_rewrite, pl.available_now, pl.available_later,
                    pl.delivery_in_stock, pl.delivery_out_stock
             FROM `{$prefix}product` p
             LEFT JOIN `{$prefix}product_shop` ps
                  ON ps.id_product = p.id_product AND ps.id_shop = {$idShop}
             LEFT JOIN `{$prefix}product_lang` pl
                  ON pl.id_product = p.id_product AND pl.id_shop = {$idShop} AND pl.id_lang = {$idLang}
             WHERE p.id_product = " . (int) $idProduct
        );
        if (!$row) return [];

        // Stock — separate table
        $stock = Db::getInstance()->getValue(
            "SELECT quantity FROM `{$prefix}stock_available`
             WHERE id_product = " . (int) $idProduct . "
               AND id_product_attribute = 0
               AND id_shop = {$idShop}"
        );
        $row['quantity'] = $stock !== false ? (int) $stock : 0;

        return $row;
    }

    /**
     * Write a field value to the right storage table. Handles multi-lang fields.
     *
     * @param mixed $value
     */
    private function writeField(int $idProduct, string $field, $value, ?int $idLang, int $idShop): void
    {
        $prefix = _DB_PREFIX_;
        $storage = FieldDefinitions::storageTable($field);
        // Keep friendly URLs unique per language — bulk bypasses the ObjectModel uniqueness
        // check, so mass-generating link_rewrite could otherwise create duplicate URLs.
        if ($field === 'link_rewrite' && $idLang !== null && trim((string) $value) !== '') {
            $value = $this->uniqueLinkRewrite((string) $value, $idProduct, (int) $idLang, $idShop);
        }
        // A cleared DATE column must be NULL, not '' — '' is an invalid DATE and is
        // rejected under STRICT_TRANS_TABLES (MariaDB default), causing a phantom "change".
        $escValue = ($field === 'available_date' && trim((string) $value) === '')
            ? 'NULL'
            : $this->escapeValue($value);

        switch ($storage) {
            case 'product_lang': {
                if ($idLang === null) {
                    // Apply to all languages
                    $langs = Language::getLanguages(false);
                    foreach ($langs as $lang) {
                        Db::getInstance()->execute(
                            "UPDATE `{$prefix}product_lang`
                             SET `{$field}` = {$escValue}
                             WHERE id_product = " . (int) $idProduct . "
                               AND id_shop = {$idShop}
                               AND id_lang = " . (int) $lang['id_lang']
                        );
                    }
                } else {
                    Db::getInstance()->execute(
                        "UPDATE `{$prefix}product_lang`
                         SET `{$field}` = {$escValue}
                         WHERE id_product = " . (int) $idProduct . "
                           AND id_shop = {$idShop}
                           AND id_lang = " . (int) $idLang
                    );
                }
                break;
            }
            case 'product_shop': {
                Db::getInstance()->execute(
                    "UPDATE `{$prefix}product_shop`
                     SET `{$field}` = {$escValue}
                     WHERE id_product = " . (int) $idProduct . "
                       AND id_shop = {$idShop}"
                );
                // Keep `product` table in sync for single-shop setups that read from there
                if (in_array($field, ['active', 'price', 'wholesale_price', 'visibility', 'minimal_quantity', 'on_sale', 'available_for_order', 'show_price', 'online_only'], true)) {
                    Db::getInstance()->execute(
                        "UPDATE `{$prefix}product`
                         SET `{$field}` = {$escValue}
                         WHERE id_product = " . (int) $idProduct
                    );
                }
                break;
            }
            case 'stock_available': {
                Db::getInstance()->execute(
                    "UPDATE `{$prefix}stock_available`
                     SET `quantity` = {$escValue}
                     WHERE id_product = " . (int) $idProduct . "
                       AND id_product_attribute = 0
                       AND id_shop = {$idShop}"
                );
                break;
            }
            case 'product':
            default: {
                Db::getInstance()->execute(
                    "UPDATE `{$prefix}product`
                     SET `{$field}` = {$escValue}
                     WHERE id_product = " . (int) $idProduct
                );
                break;
            }
        }
    }

    /** @param mixed $oldValue @param mixed $newValue */
    private function logChange(int $idBatch, int $idProduct, string $field, $oldValue, $newValue, ?int $idLang): void
    {
        Db::getInstance()->insert('smartbulk_massedit_log', [
            'id_massedit' => (int) $idBatch,
            'id_product'  => (int) $idProduct,
            'id_lang'     => $idLang,
            'field'       => pSQL($field),
            'old_value'   => $oldValue === null ? null : pSQL($this->reverseWrap($oldValue), true),
            'new_value'   => pSQL($this->reverseWrap($newValue), true),
            'status'      => 'changed',
            'changed_at'  => date('Y-m-d H:i:s'),
        ]);
    }

    private function logError(int $idBatch, int $idProduct, string $error): void
    {
        Db::getInstance()->insert('smartbulk_massedit_log', [
            'id_massedit' => (int) $idBatch,
            'id_product'  => (int) $idProduct,
            'field'       => '_apply',
            'old_value'   => null,
            'new_value'   => null,
            'status'      => 'failed',
            'error'       => pSQL($error, true),
            'changed_at'  => date('Y-m-d H:i:s'),
        ]);
    }

    /** @return int[] */
    private function logProcessedIds(int $idBatch): array
    {
        $rows = Db::getInstance()->executeS(
            'SELECT DISTINCT id_product FROM `' . _DB_PREFIX_ . 'smartbulk_massedit_log`
             WHERE id_massedit = ' . (int) $idBatch
        );
        return $rows ? array_map(static fn ($r) => (int) $r['id_product'], $rows) : [];
    }

    /** @param mixed $a @param mixed $b */
    private function valueEqual($a, $b, string $fieldType): bool
    {
        if ($fieldType === 'numeric') return abs(((float) $a) - ((float) $b)) < 1e-6;
        if ($fieldType === 'int')     return (int) $a === (int) $b;
        if ($fieldType === 'bool')    return ((int) (bool) $a) === ((int) (bool) $b);
        return (string) ($a ?? '') === (string) ($b ?? '');
    }

    /** @param mixed $value */
    private function valueForDisplay($value): string
    {
        if (is_bool($value)) return $value ? '1' : '0';
        if (is_null($value)) return '';
        if (is_array($value)) return json_encode($value) ?: '';
        return (string) $value;
    }

    /** Wrap mixed → string for log storage. Unwrap on undo. */
    private function reverseWrap($value): string
    {
        if (is_null($value)) return '';
        if (is_array($value)) return json_encode($value) ?: '';
        return (string) $value;
    }

    /** @return mixed  Preserves NULL (a column that was NULL restores to NULL, not ''). */
    private function reverseUnwrap(?string $raw)
    {
        return $raw;
    }

    // =====================================================================
    // Virtual field: _feature (product features → feature_product table)
    // =====================================================================

    /**
     * @param array<string,mixed> $action
     * @return array<string,mixed>|null  Preview diff row (or null if no-op)
     */
    private function diffFeature(int $idProduct, array $action, string $op, int $idLang): ?array
    {
        $idFeature = (int) ($action['id_feature'] ?? 0);
        if ($idFeature <= 0) return null;

        $featureName = $this->getFeatureName($idFeature, $idLang);

        if ($op === 'clear') {
            $count = $this->countFeatureValuesOnProduct($idProduct, $idFeature);
            if ($count === 0) return null;
            return [
                'field'       => '_feature',
                'field_label' => 'Feature',
                'old'         => $featureName . ' (' . $count . ')',
                'new'         => '(cleared)',
                'type'        => 'feature',
                'flags'       => [],
                'old_len'     => null,
                'new_len'     => null,
            ];
        }

        // operator = 'add'
        $idValue     = (int) ($action['id_feature_value'] ?? 0);
        $customValue = trim((string) ($action['custom_value'] ?? ''));

        if ($idValue <= 0 && $customValue === '') return null;

        $valueLabel = $idValue > 0
            ? $this->getFeatureValueLabel($idValue, $idLang)
            : $customValue . ' (custom)';

        return [
            'field'       => '_feature',
            'field_label' => 'Feature',
            'old'         => '',
            'new'         => $featureName . ' = ' . $valueLabel,
            'type'        => 'feature',
            'flags'       => [],
            'old_len'     => null,
            'new_len'     => null,
        ];
    }

    /**
     * Apply a feature action to the product. For 'add' inserts into feature_product
     * (creating a custom feature_value if needed). For 'clear' deletes all rows
     * of (feature, product) from feature_product.
     *
     * @param array<string,mixed> $action
     * @return array{old:string,new:string}|null  null when no-op (already present / no values to clear)
     */
    private function applyFeature(int $idProduct, array $action, string $op, int $idLang, bool $isDryRun): ?array
    {
        $idFeature = (int) ($action['id_feature'] ?? 0);
        if ($idFeature <= 0) return null;

        $prefix = _DB_PREFIX_;

        if ($op === 'clear') {
            $count = $this->countFeatureValuesOnProduct($idProduct, $idFeature);
            if ($count === 0) return null;
            $featureName = $this->getFeatureName($idFeature, $idLang);
            $removed = [];
            if (!$isDryRun) {
                // Snapshot the values before deleting so undo can restore them.
                $rows = Db::getInstance()->executeS(
                    "SELECT id_feature_value FROM `{$prefix}feature_product`
                     WHERE id_product = " . (int) $idProduct . " AND id_feature = " . $idFeature
                ) ?: [];
                foreach ($rows as $r) $removed[] = (int) $r['id_feature_value'];
                Db::getInstance()->execute(
                    "DELETE FROM `{$prefix}feature_product`
                     WHERE id_product = " . (int) $idProduct . "
                       AND id_feature = " . $idFeature
                );
            }
            return [
                'old' => $featureName . ' (' . $count . ' values)',
                'new' => json_encode(['feature' => $idFeature, 'cleared' => true, 'values' => $removed]) ?: '',
            ];
        }

        // operator = 'add'
        $idValue     = (int) ($action['id_feature_value'] ?? 0);
        $customValue = trim((string) ($action['custom_value'] ?? ''));
        if ($idValue <= 0 && $customValue === '') return null;

        $isCustom = false;
        if ($idValue <= 0) {
            $isCustom = true;
            if (!$isDryRun) {
                $idValue = $this->createCustomFeatureValue($idFeature, $customValue);
            } else {
                $idValue = 0; // dry-run — nie tworzymy realnego wpisu
            }
        }

        if (!$isDryRun) {
            $exists = (int) Db::getInstance()->getValue(
                "SELECT COUNT(*) FROM `{$prefix}feature_product`
                 WHERE id_product = " . (int) $idProduct . "
                   AND id_feature = " . $idFeature . "
                   AND id_feature_value = " . (int) $idValue
            );
            if ($exists > 0) return null;

            Db::getInstance()->execute(
                "INSERT INTO `{$prefix}feature_product` (id_product, id_feature, id_feature_value)
                 VALUES (" . (int) $idProduct . ", " . $idFeature . ", " . (int) $idValue . ")"
            );
        }

        $featureName = $this->getFeatureName($idFeature, $idLang);
        $valueLabel  = $isCustom ? $customValue : $this->getFeatureValueLabel($idValue, $idLang);

        return [
            'old' => '',
            'new' => json_encode([
                'feature' => $idFeature,
                'value'   => $idValue,
                'custom'  => $isCustom ? $customValue : null,
                'label'   => $featureName . ' = ' . $valueLabel,
            ]) ?: '',
        ];
    }

    /**
     * Reverse a previously logged _feature change. Reads new_value JSON to learn
     * which (feature, value) row was added and deletes it. Custom feature_values
     * that become unreferenced are also pruned.
     */
    private function undoFeature(int $idProduct, string $newValueJson): void
    {
        $data = json_decode($newValueJson, true);
        if (!is_array($data) || !isset($data['feature'])) return;

        $prefix    = _DB_PREFIX_;
        $idFeature = (int) $data['feature'];

        if (!empty($data['cleared'])) {
            // Restore the feature values that 'clear' removed (snapshotted in new_value).
            foreach ((array) ($data['values'] ?? []) as $idValue) {
                $idValue = (int) $idValue;
                if ($idValue > 0) {
                    Db::getInstance()->execute(
                        "INSERT INTO `{$prefix}feature_product` (id_product, id_feature, id_feature_value)
                         VALUES (" . (int) $idProduct . ", " . $idFeature . ", " . $idValue . ")"
                    );
                }
            }
            return;
        }

        $idValue = (int) ($data['value'] ?? 0);
        if ($idValue <= 0) return;

        Db::getInstance()->execute(
            "DELETE FROM `{$prefix}feature_product`
             WHERE id_product = " . (int) $idProduct . "
               AND id_feature = " . $idFeature . "
               AND id_feature_value = " . $idValue
        );

        if (!empty($data['custom'])) {
            $stillUsed = (int) Db::getInstance()->getValue(
                "SELECT COUNT(*) FROM `{$prefix}feature_product` WHERE id_feature_value = " . $idValue
            );
            if ($stillUsed === 0) {
                Db::getInstance()->execute("DELETE FROM `{$prefix}feature_value_lang` WHERE id_feature_value = " . $idValue);
                Db::getInstance()->execute("DELETE FROM `{$prefix}feature_value` WHERE id_feature_value = " . $idValue . " AND custom = 1");
            }
        }
    }

    /** Restore the image_lang.legend snapshot captured before an alt_text accept. */
    private function undoAltText(int $idProduct, int $idLang, string $snapshotJson): void
    {
        $data = json_decode($snapshotJson, true);
        $map  = is_array($data) ? ($data['old_map'] ?? []) : [];
        $prefix = _DB_PREFIX_;
        foreach ($map as $idImage => $oldLegend) {
            $idImage = (int) $idImage;
            if ($oldLegend === null) {
                // No row existed before the accept — remove the one we created.
                Db::getInstance()->execute(
                    "DELETE FROM `{$prefix}image_lang` WHERE id_image = {$idImage} AND id_lang = " . (int) $idLang
                );
            } else {
                $esc = pSQL((string) $oldLegend, true);
                Db::getInstance()->execute(
                    "UPDATE `{$prefix}image_lang` SET legend = '{$esc}' WHERE id_image = {$idImage} AND id_lang = " . (int) $idLang
                );
            }
        }
    }

    // =====================================================================
    // Virtual field: _tags (product tags → product_tag table, per language)
    // =====================================================================

    /**
     * Normalize a free-text tag list (array of strings or a comma-separated string)
     * into a clean, case-insensitively de-duplicated array of tag names.
     *
     * @param mixed $raw
     * @return string[]
     */
    private function normalizeTagNames($raw): array
    {
        if (is_string($raw)) {
            $raw = explode(',', $raw);
        }
        if (!is_array($raw)) {
            return [];
        }
        $names = [];
        foreach ($raw as $n) {
            $n = trim((string) $n);
            if ($n === '') continue;
            $key = mb_strtolower($n);
            if (!isset($names[$key])) {
                $names[$key] = $n; // keep first-seen casing
            }
        }
        return array_values($names);
    }

    /**
     * Current tags of a product in one language.
     *
     * @return array<int,array{id_tag:int,name:string}>
     */
    private function currentProductTags(int $idProduct, int $idLang): array
    {
        $prefix = _DB_PREFIX_;
        $rows = Db::getInstance()->executeS(
            "SELECT pt.id_tag, t.name
             FROM `{$prefix}product_tag` pt
             JOIN `{$prefix}tag` t ON t.id_tag = pt.id_tag
             WHERE pt.id_product = " . (int) $idProduct . " AND pt.id_lang = " . (int) $idLang
        ) ?: [];
        return array_map(static fn (array $r) => [
            'id_tag' => (int) $r['id_tag'],
            'name'   => (string) $r['name'],
        ], $rows);
    }

    /** Find a tag id by name (per language), creating the tag if it doesn't exist yet. */
    private function resolveOrCreateTag(string $name, int $idLang): int
    {
        $prefix = _DB_PREFIX_;
        $name = trim($name);
        if ($name === '') return 0;
        // getValue() appends its own LIMIT 1 — do not add one here.
        $existing = (int) Db::getInstance()->getValue(
            "SELECT id_tag FROM `{$prefix}tag`
             WHERE id_lang = " . (int) $idLang . " AND name = '" . pSQL($name) . "'"
        );
        if ($existing > 0) return $existing;
        Db::getInstance()->execute(
            "INSERT INTO `{$prefix}tag` (id_lang, name) VALUES (" . (int) $idLang . ", '" . pSQL($name) . "')"
        );
        return (int) Db::getInstance()->Insert_ID();
    }

    /**
     * Preview a _tags action for one product (readable diff row). Mirrors diffFeature.
     *
     * @param array<string,mixed> $action
     * @return array<string,mixed>|null
     */
    private function diffTags(int $idProduct, array $action, string $op, int $idLang): ?array
    {
        $current   = $this->currentProductTags($idProduct, $idLang);
        $currentLc = array_map(static fn ($r) => mb_strtolower($r['name']), $current);

        if ($op === 'clear') {
            if (!$current) return null;
            return [
                'field' => '_tags', 'field_label' => 'Tags', 'type' => 'tags', 'flags' => [],
                'old' => implode(', ', array_map(static fn ($r) => $r['name'], $current)),
                'new' => '(cleared)', 'old_len' => null, 'new_len' => null,
            ];
        }

        $names = $this->normalizeTagNames($action['tags'] ?? null);
        if (!$names) return null;
        $namesLc = array_map('mb_strtolower', $names);

        if ($op === 'add') {
            $toAdd = [];
            foreach ($names as $i => $n) {
                if (!in_array($namesLc[$i], $currentLc, true)) $toAdd[] = $n;
            }
            if (!$toAdd) return null;
            return [
                'field' => '_tags', 'field_label' => 'Tags', 'type' => 'tags', 'flags' => [],
                'old' => '', 'new' => '+ ' . implode(', ', $toAdd), 'old_len' => null, 'new_len' => null,
            ];
        }

        // op === 'remove'
        $toRemove = [];
        foreach ($current as $r) {
            if (in_array(mb_strtolower($r['name']), $namesLc, true)) $toRemove[] = $r['name'];
        }
        if (!$toRemove) return null;
        return [
            'field' => '_tags', 'field_label' => 'Tags', 'type' => 'tags', 'flags' => [],
            'old' => implode(', ', $toRemove), 'new' => '(removed)', 'old_len' => null, 'new_len' => null,
        ];
    }

    /**
     * Apply a _tags action. Snapshots the product's current tag ids into `old`
     * ({old_tags:[…]}) so undoTags() can fully restore them; `new` is a readable summary.
     *
     * @param array<string,mixed> $action
     * @return array{old:string,new:string,old_display:string}|null  null when no-op
     */
    private function applyTags(int $idProduct, array $action, string $op, int $idLang, bool $isDryRun): ?array
    {
        $prefix     = _DB_PREFIX_;
        $current    = $this->currentProductTags($idProduct, $idLang);
        $currentIds = array_map(static fn ($r) => $r['id_tag'], $current);
        $snapshot   = json_encode(['old_tags' => $currentIds]) ?: '';

        if ($op === 'clear') {
            if (!$current) return null;
            if (!$isDryRun) {
                Db::getInstance()->execute(
                    "DELETE FROM `{$prefix}product_tag`
                     WHERE id_product = " . (int) $idProduct . " AND id_lang = " . (int) $idLang
                );
            }
            return [
                'old'         => $snapshot,
                'new'         => 'cleared ' . count($current) . ' tag(s)',
                'old_display' => implode(', ', array_map(static fn ($r) => $r['name'], $current)),
            ];
        }

        $names = $this->normalizeTagNames($action['tags'] ?? null);
        if (!$names) return null;
        $currentLc = array_map(static fn ($r) => mb_strtolower($r['name']), $current);

        if ($op === 'add') {
            $added = [];
            foreach ($names as $n) {
                if (in_array(mb_strtolower($n), $currentLc, true)) continue; // already present
                if (!$isDryRun) {
                    $idTag = $this->resolveOrCreateTag($n, $idLang);
                    if ($idTag > 0) {
                        Db::getInstance()->execute(
                            "INSERT INTO `{$prefix}product_tag` (id_product, id_tag, id_lang)
                             VALUES (" . (int) $idProduct . ", " . (int) $idTag . ", " . (int) $idLang . ")"
                        );
                    }
                }
                $added[] = $n;
            }
            if (!$added) return null;
            return ['old' => $snapshot, 'new' => '+ ' . implode(', ', $added), 'old_display' => ''];
        }

        // op === 'remove'
        $namesLc     = array_map('mb_strtolower', $names);
        $removeIds   = [];
        $removedName = [];
        foreach ($current as $r) {
            if (in_array(mb_strtolower($r['name']), $namesLc, true)) {
                $removeIds[]   = $r['id_tag'];
                $removedName[] = $r['name'];
            }
        }
        if (!$removeIds) return null;
        if (!$isDryRun) {
            Db::getInstance()->execute(
                "DELETE FROM `{$prefix}product_tag`
                 WHERE id_product = " . (int) $idProduct . "
                   AND id_lang = " . (int) $idLang . "
                   AND id_tag IN (" . implode(',', array_map('intval', $removeIds)) . ")"
            );
        }
        return ['old' => $snapshot, 'new' => '- ' . implode(', ', $removedName), 'old_display' => implode(', ', $removedName)];
    }

    /** Restore the product_tag snapshot captured before a tagging accept. */
    private function undoTags(int $idProduct, int $idLang, string $snapshotJson): void
    {
        $data = json_decode($snapshotJson, true);
        $old  = is_array($data) ? ($data['old_tags'] ?? []) : [];
        $prefix = _DB_PREFIX_;
        Db::getInstance()->execute(
            "DELETE FROM `{$prefix}product_tag` WHERE id_product = " . (int) $idProduct . " AND id_lang = " . (int) $idLang
        );
        foreach ($old as $idTag) {
            $idTag = (int) $idTag;
            if ($idTag > 0) {
                Db::getInstance()->execute(
                    "INSERT INTO `{$prefix}product_tag` (id_product, id_tag, id_lang)
                     VALUES (" . (int) $idProduct . ", {$idTag}, " . (int) $idLang . ")"
                );
            }
        }
    }

    /** Ensure a link_rewrite is unique for (lang, shop); append -{id_product} on clash. */
    private function uniqueLinkRewrite(string $slug, int $idProduct, int $idLang, int $idShop): string
    {
        $candidate = $slug;
        $suffix = 0;
        while ($this->linkRewriteClashes($candidate, $idProduct, $idLang, $idShop)) {
            $suffix++;
            $candidate = $slug . '-' . $idProduct . ($suffix > 1 ? '-' . $suffix : '');
            if ($suffix > 20) break; // safety
        }
        return $candidate;
    }

    private function linkRewriteClashes(string $slug, int $idProduct, int $idLang, int $idShop): bool
    {
        $esc = pSQL($slug, true);
        return (int) Db::getInstance()->getValue(
            "SELECT COUNT(*) FROM `" . _DB_PREFIX_ . "product_lang`
             WHERE link_rewrite = '{$esc}' AND id_lang = " . (int) $idLang . "
               AND id_shop = " . (int) $idShop . " AND id_product <> " . (int) $idProduct
        ) > 0;
    }

    private function createCustomFeatureValue(int $idFeature, string $value): int
    {
        $prefix = _DB_PREFIX_;
        Db::getInstance()->execute(
            "INSERT INTO `{$prefix}feature_value` (id_feature, custom) VALUES ({$idFeature}, 1)"
        );
        $idValue = (int) Db::getInstance()->Insert_ID();
        $langs = Language::getLanguages(false);
        foreach ($langs as $lang) {
            Db::getInstance()->execute(
                "INSERT INTO `{$prefix}feature_value_lang` (id_feature_value, id_lang, value)
                 VALUES ({$idValue}, " . (int) $lang['id_lang'] . ", '" . pSQL($value, true) . "')"
            );
        }
        return $idValue;
    }

    private function getFeatureName(int $idFeature, int $idLang): string
    {
        $name = Db::getInstance()->getValue(
            "SELECT name FROM `" . _DB_PREFIX_ . "feature_lang`
             WHERE id_feature = " . (int) $idFeature . " AND id_lang = " . (int) $idLang
        );
        return $name !== false ? (string) $name : ('#' . $idFeature);
    }

    private function getFeatureValueLabel(int $idValue, int $idLang): string
    {
        $val = Db::getInstance()->getValue(
            "SELECT value FROM `" . _DB_PREFIX_ . "feature_value_lang`
             WHERE id_feature_value = " . (int) $idValue . " AND id_lang = " . (int) $idLang
        );
        return $val !== false ? (string) $val : ('#' . $idValue);
    }

    private function countFeatureValuesOnProduct(int $idProduct, int $idFeature): int
    {
        return (int) Db::getInstance()->getValue(
            "SELECT COUNT(*) FROM `" . _DB_PREFIX_ . "feature_product`
             WHERE id_product = " . (int) $idProduct . " AND id_feature = " . (int) $idFeature
        );
    }

    /** @param mixed $value */
    private function escapeValue($value): string
    {
        // NULL → SQL NULL (only reached when undo restores a column that was NULL before;
        // apply never passes null, so NOT NULL columns are never hit with this).
        if ($value === null) return 'NULL';
        if (is_bool($value)) return $value ? '1' : '0';
        if (is_int($value)) return (string) $value;
        if (is_float($value)) return (string) $value;
        return "'" . pSQL((string) $value, true) . "'";
    }
}
