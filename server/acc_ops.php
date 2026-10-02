<?php
/**
 * Accounting module - business operations and their double-entry postings.
 *
 * Postings (D = debit, C = credit; person = the person's control account):
 *   sale             D person total | C 4101 net | C 2103 VAT ; D 5101 cost | C 1103 cost
 *   purchase         D 1103 net+costs | D 1105 VAT | C person total
 *   sale return      D 4102 net | D 2103 VAT | C person total ; D 1103 cost | C 5101 cost
 *   purchase return  D person total | C 1103 net+costs | C 1105 VAT
 *   receipt          D 1101 (cash/bank) | C person (or 4103 other income)
 *   payment          D person (or 5102 expense) | C 1101
 *   transfer         D 1101 destination | C 1101 source
 *   cheque received  D 1104 | C person ; collected D 1101 | C 1104 ; bounced D person | C 1104 ;
 *                    spent (given to someone) D that person | C 1104
 *   cheque issued    D person | C 2102 ; paid D 2102 | C 1101 ; returned D 2102 | C person
 *   stock count / adjustments: shortage D 5103 | C 1103 ; overage D 1103 | C 4104
 *   warehouse receipt D 1103 | C 4104 ; issue D 5103 | C 1103 (at average cost)
 */

const ACC_INVOICE_KINDS = ['sale', 'purchase', 'sale_return', 'purchase_return', 'sale_proforma', 'purchase_proforma', 'sale_order', 'purchase_order'];
const ACC_INVOICE_PREFIX = ['sale' => 'SF', 'purchase' => 'PF', 'sale_return' => 'SR', 'purchase_return' => 'PR',
    'sale_proforma' => 'SQ', 'purchase_proforma' => 'PQ', 'sale_order' => 'SO', 'purchase_order' => 'PO'];

function acc_is_draft_kind($kind)
{
    return substr($kind, -8) === 'proforma' || substr($kind, -5) === 'order';
}

/** Normalised invoice lines with product rows and base quantities. */
function acc_invoice_lines(array $items)
{
    $out = [];
    foreach ($items as $it) {
        $p = acc_product((int)($it['product_id'] ?? 0));
        if (!$p) {
            throw new AccError('کالا یافت نشد');
        }
        $qty = acc_num($it['qty'] ?? 1);
        $price = acc_num($it['price'] ?? 0);
        if ($qty <= 0) {
            throw new AccError('تعداد «' . $p['name'] . '» باید بزرگتر از صفر باشد');
        }
        if ($price < 0) {
            throw new AccError('قیمت منفی مجاز نیست');
        }
        $factor = 1.0;
        $unit = $p['unit'] ?: 'عدد';
        if (($it['unit'] ?? 'primary') === 'secondary' && (float)$p['unit2_factor'] > 0) {
            $factor = (float)$p['unit2_factor'];
            $unit = $p['unit2'] ?: $unit;
        }
        $out[] = ['product' => $p, 'qty' => $qty, 'price' => $price, 'factor' => $factor, 'unit' => $unit, 'base_qty' => $qty * $factor];
    }
    if (!$out) {
        throw new AccError('حداقل یک ردیف کالا لازم است');
    }
    return $out;
}

/** subtotal, discount, net, VAT, extra costs and total of an invoice. */
function acc_invoice_totals($kind, array $lines, array $b)
{
    $sub = 0;
    foreach ($lines as $l) {
        $sub += $l['qty'] * $l['price'];
    }
    $pct = acc_num($b['discount_percent'] ?? 0);
    $disc = acc_num($b['discount'] ?? 0) + ($pct ? round($sub * $pct / 100) : 0);
    if ($disc < 0 || $disc > $sub) {
        throw new AccError('تخفیف نمی‌تواند منفی یا بیشتر از جمع فاکتور باشد');
    }
    $net = $sub - $disc;
    $extra = in_array($kind, ['purchase', 'purchase_return'], true)
        ? acc_num($b['freight'] ?? 0) + acc_num($b['customs'] ?? 0) + acc_num($b['other_cost'] ?? 0) : 0;
    $tax = acc_is_draft_kind($kind) || !empty($b['no_vat']) ? 0 : round($net * (float)acc_company()['vat_rate'] / 100);
    return ['subtotal' => $sub, 'discount' => $disc, 'discount_percent' => $pct, 'net' => $net, 'extra' => $extra, 'tax' => $tax, 'total' => $net + $tax + $extra];
}

/** Creates ($id null) or rewrites an invoice; final kinds are posted. */
function acc_invoice_save($id, array $b)
{
    acc_require_open();
    $kind = (string)($b['kind'] ?? '');
    if (!in_array($kind, ACC_INVOICE_KINDS, true)) {
        throw new AccError('نوع سند نامعتبر است');
    }
    $person = acc_row('SELECT * FROM acc_persons WHERE id = ?', [(int)($b['person_id'] ?? 0)]);
    if (!$person) {
        throw new AccError('طرف حساب یافت نشد');
    }
    $lines = acc_invoice_lines((array)($b['items'] ?? []));
    $t = acc_invoice_totals($kind, $lines, $b);
    if (!acc_is_draft_kind($kind)) {
        acc_check_stock($lines, $kind, $id);
    }
    return acc_tx(function () use ($id, $b, $kind, $person, $lines, $t) {
        $fields = ['kind' => $kind, 'date' => acc_date($b['date'] ?? ''), 'person_id' => (int)$person['id'],
            'subtotal' => $t['subtotal'], 'discount' => $t['discount'], 'discount_percent' => $t['discount_percent'], 'tax' => $t['tax'],
            'total' => $t['total'], 'status' => acc_is_draft_kind($kind) ? 'draft' : 'final',
            'freight' => acc_num($b['freight'] ?? 0), 'customs' => acc_num($b['customs'] ?? 0), 'other_cost' => acc_num($b['other_cost'] ?? 0),
            'branch_id' => !empty($b['branch_id']) ? (int)$b['branch_id'] : null,
            'due_date' => trim((string)($b['due_date'] ?? '')), 'note' => trim((string)($b['note'] ?? '')),
            'marketer_id' => !empty($b['marketer_id']) ? (int)$b['marketer_id'] : null,
            'department_id' => !empty($b['department_id']) ? (int)$b['department_id'] : null, 'no_vat' => !empty($b['no_vat']) ? 1 : 0];
        if ($id) {
            $old = acc_row('SELECT * FROM acc_invoices WHERE id = ?', [$id]);
            acc_invoice_unpost($old, 'اصلاح فاکتور');
            acc_q('DELETE FROM acc_invoice_items WHERE invoice_id = ?', [$id]);
            acc_q('DELETE FROM acc_tax_invoices WHERE invoice_id = ?', [$id]);
            if ($old['kind'] !== $kind) {
                $fields['number'] = acc_invoice_number($kind);
            }
            acc_update('acc_invoices', $id, $fields);
        } else {
            $fields['number'] = acc_invoice_number($kind);
            $fields['atf'] = (string)acc_next('atf');
            $fields['created_at'] = acc_now();
            $id = acc_insert('acc_invoices', $fields);
        }
        foreach ($lines as $l) {
            acc_insert('acc_invoice_items', ['invoice_id' => $id, 'product_id' => (int)$l['product']['id'], 'qty' => $l['qty'],
                'price' => $l['price'], 'unit' => $l['unit'], 'unit_factor' => $l['factor']]);
        }
        $inv = acc_row('SELECT * FROM acc_invoices WHERE id = ?', [$id]);
        if ($inv['status'] === 'final') {
            acc_invoice_post($inv);
        }
        acc_invoice_refresh_settled($id);
        return acc_row('SELECT * FROM acc_invoices WHERE id = ?', [$id]);
    });
}

function acc_invoice_number($kind)
{
    $c = acc_company();
    $prefix = ['sale' => $c['invoice_prefix_sale'] ?: 'SF', 'purchase' => $c['invoice_prefix_buy'] ?: 'PF'][$kind] ?? ACC_INVOICE_PREFIX[$kind];
    return sprintf('%s-%04d', $prefix, acc_next('inv:' . $kind));
}

/** Stock, average cost and journal entries of a final invoice. */
function acc_invoice_post(array $inv)
{
    $kind = $inv['kind'];
    $items = acc_all('SELECT * FROM acc_invoice_items WHERE invoice_id = ?', [$inv['id']]);
    $person = acc_row('SELECT * FROM acc_persons WHERE id = ?', [$inv['person_id']]);
    $net = (float)$inv['subtotal'] - (float)$inv['discount'];
    $net = max($net, 0);
    $extra = (float)$inv['freight'] + (float)$inv['customs'] + (float)$inv['other_cost'];
    if (!in_array($kind, ['purchase', 'purchase_return'], true)) {
        $extra = 0;
    }
    $tax = (float)$inv['tax'];
    $total = (float)$inv['total'];
    $doc = ['type' => 'invoice', 'id' => (int)$inv['id'], 'number' => $inv['number'], 'date' => $inv['date'], 'kind' => $kind];
    $wh = acc_default_warehouse();
    $cost = 0;
    // discounts and purchase costs are spread over the lines by value
    $ratio = (float)$inv['subtotal'] > 0 ? ($net + $extra) / (float)$inv['subtotal'] : 0;
    foreach ($items as $it) {
        $p = acc_product($it['product_id']);
        $base = (float)$it['qty'] * (float)$it['unit_factor'];
        if (($p['kind'] ?? 'goods') === 'service') {
            continue;   // services have no stock and no cost of goods
        }
        if ($kind === 'purchase') {
            $unit_cost = $base > 0 ? (float)$it['qty'] * (float)$it['price'] * $ratio / $base : 0;
            $old_qty = max((float)$p['stock'], 0);
            $old_cost = acc_unit_cost($p);
            $avg = ($old_qty + $base) > 0 ? ($old_qty * $old_cost + $base * $unit_cost) / ($old_qty + $base) : $unit_cost;
            acc_update('acc_products', $p['id'], ['avg_cost' => $avg, 'last_cost' => $unit_cost,
                'buy_price' => (float)$it['unit_factor'] ? (float)$it['price'] / (float)$it['unit_factor'] : (float)$it['price']]);
            acc_change_stock($wh, $p['id'], $base, $doc + ['cost' => $unit_cost]);
        } elseif ($kind === 'sale') {
            $unit_cost = acc_unit_cost($p);
            $cost += $unit_cost * $base;
            acc_change_stock($wh, $p['id'], -$base, $doc + ['cost' => $unit_cost]);
        } elseif ($kind === 'sale_return') {
            $unit_cost = acc_unit_cost($p);
            $cost += $unit_cost * $base;
            acc_change_stock($wh, $p['id'], $base, $doc + ['cost' => $unit_cost]);
        } else {   // purchase_return: leaves at the value it was bought for (as written on the return)
            $unit_cost = $base > 0 ? (float)$it['qty'] * (float)$it['price'] * $ratio / $base : 0;
            $left = (float)$p['stock'] - $base;
            if ($left > 1e-9) {
                $avg = max(((float)$p['stock'] * acc_unit_cost($p) - $base * $unit_cost) / $left, 0);
                acc_update('acc_products', $p['id'], ['avg_cost' => $avg]);
            }
            acc_change_stock($wh, $p['id'], -$base, $doc + ['cost' => $unit_cost]);
        }
        acc_q('UPDATE acc_invoice_items SET cost = ? WHERE id = ?', [$unit_cost, $it['id']]);
    }
    $who = $person['name'];
    switch ($kind) {
        case 'sale':
            $lines = [acc_person_line($person['id'], $total, 'فاکتور ' . $inv['number']), acc_line('4101', 0, $net, 'فروش'),
                acc_line('2103', 0, $tax, 'مالیات فروش'), acc_line('5101', $cost, 0, 'بهای تمام‌شده'), acc_line('1103', 0, $cost, 'خروج کالا')];
            $desc = 'فروش به ' . $who;
            break;
        case 'purchase':
            $svc = acc_invoice_service_share($items, $net + $extra, (float)$inv['subtotal']);
            $lines = [acc_line('1103', $net + $extra - $svc, 0, 'ورود کالا'), acc_line('5102', $svc, 0, 'خدمات خریداری‌شده'), acc_line('1105', $tax, 0, 'مالیات خرید'),
                acc_person_line($person['id'], -$total, 'فاکتور ' . $inv['number'])];
            $desc = 'خرید از ' . $who;
            break;
        case 'sale_return':
            $lines = [acc_line('4102', $net, 0, 'برگشت از فروش'), acc_line('2103', $tax, 0, 'مالیات برگشتی'),
                acc_person_line($person['id'], -$total, 'برگشت ' . $inv['number']), acc_line('1103', $cost, 0, 'برگشت کالا'), acc_line('5101', 0, $cost, 'برگشت بهای تمام‌شده')];
            $desc = 'برگشت از فروش ' . $who;
            break;
        default:
            $svc = acc_invoice_service_share($items, $net + $extra, (float)$inv['subtotal']);
            $lines = [acc_person_line($person['id'], $total, 'برگشت ' . $inv['number']), acc_line('1103', 0, $net + $extra - $svc, 'خروج کالا'), acc_line('5102', 0, $svc, 'برگشت خدمات'),
                acc_line('1105', 0, $tax, 'مالیات برگشتی')];
            $desc = 'برگشت از خرید ' . $who;
    }
    acc_post($inv['date'], $desc . ' - ' . $inv['number'], $lines, 'auto', 'invoice', (int)$inv['id']);
    if (in_array($kind, ['sale', 'purchase'], true)) {
        acc_tax_invoice_create($inv, $person, $items);
    }
}

/** Part of a purchase's net value that is services (expense, not inventory). */
function acc_invoice_service_share(array $items, $net_total, $subtotal)
{
    if ($subtotal <= 0) {
        return 0;
    }
    $svc = 0;
    foreach ($items as $it) {
        if ((acc_product($it['product_id'])['kind'] ?? 'goods') === 'service') {
            $svc += (float)$it['qty'] * (float)$it['price'];
        }
    }
    return $svc * $net_total / $subtotal;
}

/** Undoes a posted invoice: stock moves back, entries reversed, average cost recomputed. */
function acc_invoice_unpost(array $inv, $why)
{
    $touched = [];
    foreach (acc_all("SELECT * FROM acc_stock_moves WHERE doc_type = 'invoice' AND doc_id = ? AND kind NOT IN ('reversal', 'reversed')", [$inv['id']]) as $m) {
        acc_change_stock($m['warehouse_id'], $m['product_id'], -(float)$m['qty'], ['type' => 'invoice', 'id' => (int)$inv['id'],
            'number' => $inv['number'], 'date' => $inv['date'], 'kind' => 'reversal', 'cost' => $m['unit_cost']]);
        acc_q("UPDATE acc_stock_moves SET kind = 'reversed' WHERE id = ?", [$m['id']]);
        $touched[(int)$m['product_id']] = true;
    }
    acc_void_source('invoice', $inv['id'], $why);
    if (in_array($inv['kind'], ['purchase', 'purchase_return'], true)) {
        foreach (array_keys($touched) as $pid) {
            acc_recalc_avg_cost($pid);
        }
    }
}

/**
 * Moving weighted average rebuilt from the live stock moves, in order: every
 * purchase / opening / production takes part at its cost, a purchase return
 * leaves at its own value. Used after a purchase is edited or deleted.
 */
function acc_recalc_avg_cost($product_id)
{
    $q = 0.0;
    $avg = null;
    foreach (acc_all("SELECT * FROM acc_stock_moves WHERE product_id = ? AND kind NOT IN ('reversal', 'reversed') ORDER BY id", [(int)$product_id]) as $m) {
        $qty = (float)$m['qty'];
        $cost = (float)$m['unit_cost'];
        if ($qty > 0 && in_array($m['kind'], ['purchase', 'opening', 'production'], true)) {
            $base = max($q, 0);
            $avg = ($base + $qty) > 0 ? ($base * (float)$avg + $qty * $cost) / ($base + $qty) : $cost;
        } elseif ($qty < 0 && $m['kind'] === 'purchase_return' && $q + $qty > 1e-9 && $avg !== null) {
            $avg = max(($q * $avg + $qty * $cost) / ($q + $qty), 0);
        }
        $q += $qty;
    }
    if ($avg !== null) {
        acc_update('acc_products', $product_id, ['avg_cost' => $avg]);
    }
}

/** Refuses to send more out than the warehouse has (unless allowed in the settings). */
function acc_check_stock(array $lines, $kind, $old_id = null)
{
    if (!in_array($kind, ['sale', 'purchase_return'], true) || (int)(acc_company()['allow_negative_stock'] ?? 0)) {
        return;
    }
    $need = [];
    foreach ($lines as $l) {
        if (($l['product']['kind'] ?? 'goods') !== 'service') {
            $need[$l['product']['id']] = ($need[$l['product']['id']] ?? 0) + $l['base_qty'];
        }
    }
    foreach ($need as $pid => $q) {
        // invoices take from the default warehouse
        $wh = acc_default_warehouse();
        $have = (float)acc_val('SELECT COALESCE((SELECT qty FROM acc_stock WHERE warehouse_id = ? AND product_id = ?), 0)', [$wh, $pid]);
        if ($old_id) {   // what the invoice being edited already took out comes back first
            $have -= (float)acc_val("SELECT COALESCE(SUM(qty), 0) FROM acc_stock_moves WHERE doc_type = 'invoice' AND doc_id = ? AND product_id = ? AND warehouse_id = ? AND kind NOT IN ('reversal', 'reversed')", [$old_id, $pid, $wh]);
        }
        if ($q > $have + 1e-9) {
            throw new AccError('موجودی «' . acc_val('SELECT name FROM acc_products WHERE id = ?', [$pid]) . '» کافی نیست (موجودی: ' . round($have, 3) . '، درخواست: ' . round($q, 3) . ')');
        }
    }
}

/** Pro-forma / order -> final sale or purchase. */
function acc_invoice_finalize(array $inv)
{
    acc_require_open();
    $map = ['sale_proforma' => 'sale', 'sale_order' => 'sale', 'purchase_proforma' => 'purchase', 'purchase_order' => 'purchase'];
    if (!isset($map[$inv['kind']])) {
        throw new AccError('فقط پیش‌فاکتور یا سفارش قابل تبدیل است');
    }
    $b = acc_invoice_detail_body($inv);
    $b['kind'] = $map[$inv['kind']];
    return acc_invoice_save((int)$inv['id'], $b);
}

function acc_invoice_detail_body(array $inv)
{
    return ['kind' => $inv['kind'], 'person_id' => $inv['person_id'], 'date' => $inv['date'], 'discount' => (float)$inv['discount'] - round((float)$inv['subtotal'] * (float)$inv['discount_percent'] / 100),
        'discount_percent' => $inv['discount_percent'], 'freight' => $inv['freight'], 'customs' => $inv['customs'], 'other_cost' => $inv['other_cost'],
        'branch_id' => $inv['branch_id'], 'no_vat' => (int)$inv['no_vat'], 'due_date' => $inv['due_date'], 'note' => $inv['note'],
        'marketer_id' => $inv['marketer_id'], 'department_id' => $inv['department_id'],
        'items' => array_map(fn($it) => ['product_id' => $it['product_id'], 'qty' => $it['qty'], 'price' => $it['price'],
            'unit' => (float)$it['unit_factor'] != 1.0 ? 'secondary' : 'primary'], acc_all('SELECT * FROM acc_invoice_items WHERE invoice_id = ?', [$inv['id']]))];
}

function acc_tax_invoice_create(array $inv, array $person, array $items)
{
    $c = acc_company();
    $taxid = '';   // given when it is sent (acc_moadian_taxid)
    $vat = (float)$c['vat_rate'];
    $body = [];
    foreach ($items as $it) {
        $p = acc_product($it['product_id']);
        $am = (float)$it['qty'] * (float)$it['price'];
        $body[] = ['sstid' => ($p['tax_code'] ?? '') !== '' ? $p['tax_code'] : $p['code'], 'sstt' => $p['name'], 'am' => (float)$it['qty'], 'fee' => (float)$it['price'],
            'prdis' => $am, 'vra' => $vat, 'vam' => round($am * $vat / 100)];
    }
    $payload = ['header' => ['taxid' => $taxid, 'indatim' => $inv['date'], 'inty' => 1, 'inp' => 1, 'ins' => 1, 'tins' => $c['national_id'],
        'tinb' => $person['national_id'], 'tprdis' => (float)$inv['subtotal'], 'tdis' => (float)$inv['discount'],
        'tadis' => (float)$inv['subtotal'] - (float)$inv['discount'], 'tvam' => (float)$inv['tax'], 'tbill' => (float)$inv['total'], 'setm' => 1],
        'body' => $body, 'payments' => []];
    acc_insert('acc_tax_invoices', ['invoice_id' => $inv['id'], 'taxid' => $taxid, 'kind' => $inv['kind'], 'seller_id' => (string)$c['national_id'],
        'buyer_id' => (string)$person['national_id'], 'buyer_name' => $person['name'], 'date' => $inv['date'],
        'pre_tax' => (float)$inv['subtotal'] - (float)$inv['discount'], 'vat' => (float)$inv['tax'], 'total' => (float)$inv['total'],
        'status' => 'ready', 'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE), 'created_at' => acc_now()]);
}

/* ------------------------------------------------------------------ */
/* stock adjustments, warehouse documents, stock counts                 */
/* ------------------------------------------------------------------ */

/**
 * Quantity change of a product in a warehouse that is not a purchase or a
 * sale (opening stock, correction, count difference) with its entry.
 */
function acc_stock_adjust($product_id, $qty, $kind, $desc, $wh = null, $date = null, array $doc = [])
{
    $p = acc_product($product_id);
    $cost = acc_unit_cost($p);
    $value = abs($qty) * $cost;
    acc_change_stock($wh, $product_id, $qty, $doc + ['date' => $date, 'kind' => $kind, 'cost' => $cost, 'type' => $doc['type'] ?? $kind]);
    if ($kind === 'opening') {
        $lines = [acc_line('1103', $qty > 0 ? $value : 0, $qty < 0 ? $value : 0, 'موجودی اول'), acc_line('3101', $qty < 0 ? $value : 0, $qty > 0 ? $value : 0, 'افتتاحیه')];
        $jkind = 'opening';
    } else {
        $lines = $qty > 0 ? [acc_line('1103', $value, 0, 'اضافه'), acc_line('4104', 0, $value, 'اضافات انبار')]
            : [acc_line('5103', $value, 0, 'کسری / مصرف'), acc_line('1103', 0, $value, 'کسری')];
        $jkind = 'auto';
    }
    acc_post($date ?: acc_today(), $desc, $lines, $jkind, $doc['type'] ?? 'product', $doc['id'] ?? (int)$product_id);
}

function acc_wh_doc_record(array $b)
{
    acc_require_open();
    $kind = (string)($b['kind'] ?? '');
    if (!in_array($kind, ['receipt', 'issue', 'transfer'], true)) {
        throw new AccError('نوع سند انبار نامعتبر است');
    }
    $items = (array)($b['items'] ?? []);
    if (!$items) {
        throw new AccError('حداقل یک کالا لازم است');
    }
    $wh = (int)($b['warehouse_id'] ?? 0);
    $to = (int)($b['to_warehouse_id'] ?? 0);
    if (!acc_val('SELECT 1 FROM acc_warehouses WHERE id = ?', [$wh])) {
        throw new AccError('انبار را انتخاب کنید');
    }
    if ($kind === 'transfer' && (!$to || $to === $wh || !acc_val('SELECT 1 FROM acc_warehouses WHERE id = ?', [$to]))) {
        throw new AccError('انبار مقصد را انتخاب کنید');
    }
    return acc_tx(function () use ($kind, $items, $wh, $to, $b) {
        $prefix = ['receipt' => 'WR', 'issue' => 'WI', 'transfer' => 'WT'][$kind];
        $date = acc_date($b['date'] ?? '');
        $number = sprintf('%s-%04d', $prefix, acc_next('wh:' . $kind));
        $id = acc_insert('acc_wh_docs', ['number' => $number, 'date' => $date, 'kind' => $kind, 'warehouse_id' => $wh,
            'to_warehouse_id' => $kind === 'transfer' ? $to : null, 'description' => (string)($b['description'] ?? ''), 'created_at' => acc_now()]);
        $doc = ['type' => 'wh_doc', 'id' => $id, 'number' => $number, 'date' => $date];
        $value = 0;
        foreach ($items as $it) {
            $qty = acc_num($it['qty'] ?? 0);
            $p = acc_product((int)($it['product_id'] ?? 0));
            if (!$p || $qty <= 0) {
                throw new AccError('کالا و تعداد بزرگتر از صفر لازم است');
            }
            if ($kind !== 'receipt' && !(int)(acc_company()['allow_negative_stock'] ?? 0)) {
                $have = (float)acc_val('SELECT COALESCE((SELECT qty FROM acc_stock WHERE warehouse_id = ? AND product_id = ?), 0)', [$wh, $p['id']]);
                if ($qty > $have + 1e-9) {
                    throw new AccError('موجودی «' . $p['name'] . '» در این انبار کافی نیست (موجودی: ' . round($have, 3) . ')');
                }
            }
            acc_insert('acc_wh_doc_items', ['doc_id' => $id, 'product_id' => $p['id'], 'qty' => $qty]);
            $cost = acc_unit_cost($p);
            if ($kind === 'receipt') {
                acc_change_stock($wh, $p['id'], $qty, $doc + ['kind' => 'receipt', 'cost' => $cost]);
                $value += $qty * $cost;
            } elseif ($kind === 'issue') {
                acc_change_stock($wh, $p['id'], -$qty, $doc + ['kind' => 'issue', 'cost' => $cost]);
                $value -= $qty * $cost;
            } else {
                acc_change_stock($wh, $p['id'], -$qty, $doc + ['kind' => 'transfer', 'cost' => $cost]);
                acc_change_stock($to, $p['id'], $qty, $doc + ['kind' => 'transfer', 'cost' => $cost]);
            }
        }
        if ($value > 0) {
            acc_post($date, 'رسید انبار ' . $number, [acc_line('1103', $value, 0, 'ورود کالا'), acc_line('4104', 0, $value, 'رسید بدون فاکتور')], 'auto', 'wh_doc', $id);
        } elseif ($value < 0) {
            acc_post($date, 'حواله انبار ' . $number, [acc_line('5103', -$value, 0, 'مصرف / خروج'), acc_line('1103', 0, -$value, 'خروج کالا')], 'auto', 'wh_doc', $id);
        }
        return ['id' => $id, 'number' => $number];
    });
}

function acc_stock_count_record(array $b)
{
    acc_require_open();
    $wh = (int)($b['warehouse_id'] ?? 0);
    if (!acc_val('SELECT 1 FROM acc_warehouses WHERE id = ?', [$wh])) {
        throw new AccError('انبار را انتخاب کنید');
    }
    return acc_tx(function () use ($wh, $b) {
        $date = acc_date($b['date'] ?? '');
        $number = sprintf('SC-%04d', acc_next('count'));
        $id = acc_insert('acc_stock_counts', ['number' => $number, 'date' => $date, 'warehouse_id' => $wh, 'status' => 'posted',
            'note' => (string)($b['note'] ?? ''), 'created_at' => acc_now()]);
        foreach ((array)($b['items'] ?? []) as $it) {
            $pid = (int)($it['product_id'] ?? 0);
            if (!acc_product($pid)) {
                continue;
            }
            $system = (float)(acc_val('SELECT qty FROM acc_stock WHERE warehouse_id = ? AND product_id = ?', [$wh, $pid]) ?: 0);
            $counted = acc_num($it['counted_qty'] ?? 0);
            $diff = $counted - $system;
            acc_insert('acc_stock_count_items', ['count_id' => $id, 'product_id' => $pid, 'system_qty' => $system, 'counted_qty' => $counted, 'diff' => $diff]);
            if (abs($diff) > 1e-9) {
                acc_stock_adjust($pid, $diff, 'count', 'انبارگردانی ' . $number, $wh, $date, ['type' => 'stock_count', 'id' => $id, 'number' => $number]);
            }
        }
        return ['id' => $id, 'number' => $number];
    });
}

/* ------------------------------------------------------------------ */
/* treasury and cheques                                                 */
/* ------------------------------------------------------------------ */

function acc_treasury_record(array $b)
{
    acc_require_open();
    $kind = (string)($b['kind'] ?? '');
    $amount = acc_num($b['amount'] ?? 0);
    $acc = acc_row('SELECT * FROM acc_cash_accounts WHERE id = ?', [(int)($b['account_id'] ?? 0)]);
    if (!$acc) {
        throw new AccError('حساب یافت نشد');
    }
    if ($amount <= 0) {
        throw new AccError('مبلغ باید بزرگتر از صفر باشد');
    }
    $pid = !empty($b['person_id']) ? (int)$b['person_id'] : null;
    if ($pid && !acc_val('SELECT 1 FROM acc_persons WHERE id = ?', [$pid])) {
        throw new AccError('طرف حساب یافت نشد');
    }
    $inv_id = !empty($b['invoice_id']) ? (int)$b['invoice_id'] : null;
    if ($inv_id) {
        $inv = acc_row('SELECT * FROM acc_invoices WHERE id = ?', [$inv_id]);
        if (!$inv) {
            throw new AccError('فاکتور یافت نشد');
        }
        // money for an invoice goes to that invoice's person, never to income/expense
        if ($pid && $pid !== (int)$inv['person_id']) {
            throw new AccError('طرف حساب با طرف فاکتور یکی نیست');
        }
        $pid = (int)$inv['person_id'];
    }
    $to = null;
    if ($kind === 'transfer') {
        $to = acc_row('SELECT * FROM acc_cash_accounts WHERE id = ?', [(int)($b['to_account_id'] ?? 0)]);
        if (!$to || (int)$to['id'] === (int)$acc['id']) {
            throw new AccError('حساب مقصد یافت نشد');
        }
    } elseif (!in_array($kind, ['receive', 'pay'], true)) {
        throw new AccError('نوع عملیات نامعتبر است');
    }
    $date = acc_date($b['date'] ?? '');
    $desc = trim((string)($b['description'] ?? ''));
    return acc_tx(function () use ($kind, $amount, $acc, $to, $pid, $inv_id, $date, $desc, $b) {
        $number = sprintf('TR-%04d', acc_next('treasury'));
        $id = acc_insert('acc_treasury', ['number' => $number, 'date' => $date, 'kind' => $kind, 'account_id' => $acc['id'],
            'to_account_id' => $to['id'] ?? null, 'person_id' => $pid, 'invoice_id' => $inv_id, 'amount' => $amount,
            'description' => $desc, 'created_at' => acc_now()]);
        // the other side: a chosen account (expense/income type, capital, loan...) or the person
        $counter_id = !empty($b['counter_account_id']) ? (int)$b['counter_account_id'] : null;
        if (!$counter_id && isset($b['counter_code']) && preg_match('/^\d+$/', (string)$b['counter_code'])) {
            $counter_id = acc_account_id((string)$b['counter_code']);
        }
        if ($counter_id) {
            $ca = acc_row('SELECT * FROM acc_coa WHERE id = ?', [$counter_id]);
            if (!$ca || $ca['level'] === 'kol') {
                throw new AccError('حساب طرف مقابل باید یک حساب معین باشد');
            }
            if (in_array($ca['code'], ['1101'], true)) {
                throw new AccError('برای جابه‌جایی بین صندوق و بانک از «انتقال» استفاده کن');
            }
            acc_q('UPDATE acc_treasury SET counter_account_id = ? WHERE id = ?', [$counter_id, $id]);
        }
        // the person is kept on the line only for accounts that are someone's balance
        // (capital of an investor, loans, advances) - not for expense/income types
        $person_accounts = ['3101', '1106', '1107', '2104', '2105', '1102', '2101'];
        $tag = $counter_id && in_array(acc_val('SELECT code FROM acc_coa WHERE id = ?', [$counter_id]), $person_accounts, true) ? $pid : null;
        $other = function ($amt) use ($tag, $counter_id, $desc) {
            if ($counter_id) {
                return ['code' => null, 'account_id' => $counter_id, 'debit' => max($amt, 0), 'credit' => max(-$amt, 0), 'desc' => $desc, 'person' => $tag, 'cash' => null];
            }
            return null;
        };
        if ($kind === 'receive') {
            $lines = [acc_cash_line($acc['id'], $amount, $desc), $other(-$amount) ?: ($pid ? acc_person_line($pid, -$amount, $desc) : acc_line('4103', 0, $amount, $desc))];
            $title = 'دریافت';
        } elseif ($kind === 'pay') {
            $lines = [$other($amount) ?: ($pid ? acc_person_line($pid, $amount, $desc) : acc_line('5102', $amount, 0, $desc)), acc_cash_line($acc['id'], -$amount, $desc)];
            $title = 'پرداخت';
        } else {
            $lines = [acc_cash_line($to['id'], $amount, 'به ' . $to['name']), acc_cash_line($acc['id'], -$amount, 'از ' . $acc['name'])];
            $title = 'انتقال';
        }
        acc_post($date, $title . ' ' . $number . ($desc !== '' ? ' - ' . $desc : ''), $lines, 'auto', 'treasury', $id);
        if ($inv_id) {
            acc_invoice_refresh_settled($inv_id);
        }
        return ['id' => $id, 'number' => $number, 'kind' => $kind, 'amount' => $amount];
    });
}

/** What has been paid on an invoice: receipts/payments and cheques (bounced ones not) for it. */
function acc_invoice_paid($inv_id)
{
    $inv = acc_row('SELECT * FROM acc_invoices WHERE id = ?', [(int)$inv_id]);
    if (!$inv || !in_array($inv['kind'], ['sale', 'purchase'], true)) {
        return 0.0;
    }
    $sale = $inv['kind'] === 'sale';
    return (float)acc_val('SELECT COALESCE(SUM(amount), 0) FROM acc_treasury WHERE invoice_id = ? AND kind = ?', [$inv_id, $sale ? 'receive' : 'pay'])
        + (float)acc_val("SELECT COALESCE(SUM(amount), 0) FROM acc_cheques WHERE invoice_id = ? AND direction = ? AND status != 'returned'", [$inv_id, $sale ? 'received' : 'payable']);
}

function acc_invoice_refresh_settled($inv_id)
{
    $inv = acc_row('SELECT * FROM acc_invoices WHERE id = ?', [(int)$inv_id]);
    if ($inv) {
        acc_q('UPDATE acc_invoices SET settled = ? WHERE id = ?', [acc_invoice_paid($inv_id) >= (float)$inv['total'] - 0.5 ? 1 : 0, $inv_id]);
    }
}

function acc_cheque_record(array $b)
{
    acc_require_open();
    $dir = ($b['direction'] ?? '') === 'payable' ? 'payable' : 'received';
    $person = acc_row('SELECT * FROM acc_persons WHERE id = ?', [(int)($b['person_id'] ?? 0)]);
    if (!$person) {
        throw new AccError('طرف حساب یافت نشد');
    }
    $amount = acc_num($b['amount'] ?? 0);
    $number = trim((string)($b['number'] ?? ''));
    if ($amount <= 0 || $number === '') {
        throw new AccError('شماره و مبلغ چک لازم است');
    }
    $account = !empty($b['account_id']) ? (int)$b['account_id'] : null;
    $inv_id = !empty($b['invoice_id']) ? (int)$b['invoice_id'] : null;
    if ($inv_id && (int)acc_val('SELECT person_id FROM acc_invoices WHERE id = ?', [$inv_id]) !== (int)$person['id']) {
        throw new AccError('این فاکتور مال این طرف حساب نیست');
    }
    $date = acc_date($b['date'] ?? '');
    return acc_tx(function () use ($dir, $person, $amount, $number, $account, $b, $inv_id, $date) {
        $id = acc_insert('acc_cheques', ['number' => $number, 'direction' => $dir, 'person_id' => $person['id'], 'account_id' => $account,
            'amount' => $amount, 'due_date' => (string)($b['due_date'] ?? ''), 'bank_name' => (string)($b['bank_name'] ?? ''),
            'status' => $dir === 'received' ? 'in_hand' : 'issued', 'description' => (string)($b['description'] ?? ''), 'created_at' => acc_now(),
            'invoice_id' => $inv_id, 'date' => $date]);
        if ($dir === 'received') {
            acc_post($date, 'دریافت چک ' . $number . ' از ' . $person['name'], [acc_line('1104', $amount, 0, 'چک ' . $number),
                acc_person_line($person['id'], -$amount, 'چک ' . $number)], 'auto', 'cheque', $id);
        } else {
            acc_post($date, 'صدور چک ' . $number . ' برای ' . $person['name'], [acc_person_line($person['id'], $amount, 'چک ' . $number),
                acc_line('2102', 0, $amount, 'چک ' . $number)], 'auto', 'cheque', $id);
        }
        if ($inv_id) {
            acc_invoice_refresh_settled($inv_id);
        }
        return acc_row('SELECT * FROM acc_cheques WHERE id = ?', [$id]);
    });
}

function acc_cheque_act($id, $action, array $b)
{
    acc_require_open();
    $c = acc_row('SELECT * FROM acc_cheques WHERE id = ?', [(int)$id]);
    if (!$c) {
        throw new AccError('چک یافت نشد', 404);
    }
    $acc_id = !empty($b['account_id']) ? (int)$b['account_id'] : ($c['account_id'] ? (int)$c['account_id'] : null);
    if ($acc_id && !acc_val('SELECT 1 FROM acc_cash_accounts WHERE id = ?', [$acc_id])) {
        throw new AccError('حساب یافت نشد');
    }
    $amt = (float)$c['amount'];
    $label = 'چک ' . $c['number'];
    $date = acc_date($b['date'] ?? '');
    $need_account = function () use ($acc_id) {
        if (!$acc_id) {
            throw new AccError('حساب بانک/صندوق را انتخاب کن');
        }
    };
    $allowed = $c['direction'] === 'received'
        ? ['deposit' => ['in_hand'], 'collect' => ['in_hand', 'deposited'], 'return' => ['in_hand', 'deposited'], 'spend' => ['in_hand']]
        : ['pay' => ['issued'], 'return' => ['issued']];
    if (!isset($allowed[$action])) {
        throw new AccError('عملیات نامعتبر');
    }
    if (!in_array($c['status'], $allowed[$action], true)) {
        throw new AccError('این عملیات برای چک «' . (ACC_CHEQUE_STATUS[$c['status']] ?? $c['status']) . '» ممکن نیست');
    }
    return acc_tx(function () use ($c, $action, $acc_id, $amt, $label, $date, $b, $need_account) {
        $set = [];
        if ($c['direction'] === 'received') {
            switch ($action) {
                case 'deposit':
                    $need_account();
                    $set = ['status' => 'deposited', 'account_id' => $acc_id];
                    break;
                case 'collect':
                    $need_account();
                    acc_post($date, 'وصول ' . $label, [acc_cash_line($acc_id, $amt, $label), acc_line('1104', 0, $amt, $label)], 'auto', 'cheque', (int)$c['id']);
                    $set = ['status' => 'collected', 'account_id' => $acc_id];
                    break;
                case 'return':
                    acc_post($date, 'برگشت ' . $label, [acc_person_line($c['person_id'], $amt, 'برگشت ' . $label), acc_line('1104', 0, $amt, $label)], 'auto', 'cheque', (int)$c['id']);
                    $set = ['status' => 'returned'];
                    break;
                case 'spend':
                    $to = (int)($b['person_id'] ?? 0);
                    if (!acc_val('SELECT 1 FROM acc_persons WHERE id = ?', [$to])) {
                        throw new AccError('چک را به چه کسی دادی؟ طرف حساب را انتخاب کن');
                    }
                    acc_post($date, 'خرج ' . $label, [acc_person_line($to, $amt, 'دریافت ' . $label), acc_line('1104', 0, $amt, $label)], 'auto', 'cheque', (int)$c['id']);
                    $set = ['status' => 'spent', 'spent_to' => $to];
                    break;
            }
        } else {
            if ($action === 'pay') {
                $need_account();
                acc_post($date, 'پاس شدن ' . $label, [acc_line('2102', $amt, 0, $label), acc_cash_line($acc_id, -$amt, $label)], 'auto', 'cheque', (int)$c['id']);
                $set = ['status' => 'paid', 'account_id' => $acc_id];
            } else {
                acc_post($date, 'برگشت ' . $label, [acc_line('2102', $amt, 0, $label), acc_person_line($c['person_id'], -$amt, 'برگشت ' . $label)], 'auto', 'cheque', (int)$c['id']);
                $set = ['status' => 'returned'];
            }
        }
        acc_update('acc_cheques', $c['id'], $set);
        if ($c['invoice_id']) {
            acc_invoice_refresh_settled($c['invoice_id']);
        }
        return acc_row('SELECT * FROM acc_cheques WHERE id = ?', [$c['id']]);
    });
}
