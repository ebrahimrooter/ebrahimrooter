<?php
/**
 * «کالا و انبار» of the phone apps: every product of the accounting books with
 * how much is in stock, in total and in each warehouse (read-only; changes are
 * made in the accounting panel with receipts, issues and invoices).
 *
 *   inv_list($company = 1) → ['warehouses' => [...], 'items' => [...], 'totals' => [...]]
 */

require_once __DIR__ . '/acc_api.php';

function inv_list($company = 1)
{
    acc_use_company((int)$company ?: 1);
    acc_db();
    $whs = acc_all('SELECT id, name, is_default FROM acc_warehouses ORDER BY id');
    $per = [];
    foreach (acc_all('SELECT warehouse_id, product_id, qty FROM acc_stock') as $s) {
        $per[(int)$s['product_id']][(int)$s['warehouse_id']] = (float)$s['qty'];
    }
    $whName = array_column($whs, 'name', 'id');
    $whTot = array_fill_keys(array_keys($whName), ['count' => 0, 'value' => 0]);
    $items = [];
    $tot = ['count' => 0, 'value' => 0, 'low' => 0, 'out' => 0, 'qty' => 0];
    foreach (acc_all("SELECT * FROM acc_products WHERE COALESCE(kind, 'goods') <> 'service' ORDER BY group_name, name") as $p) {
        $id = (int)$p['id'];
        $by = [];
        foreach ($per[$id] ?? [] as $wid => $q) {
            if (abs($q) > 0.0001) {
                $by[] = ['warehouse_id' => $wid, 'name' => $whName[$wid] ?? '-', 'qty' => $q];
            }
        }
        $qty = $by ? array_sum(array_column($by, 'qty')) : (float)$p['stock'];
        $cost = (float)$p['avg_cost'] ?: (float)$p['buy_price'];
        $reorder = (float)$p['reorder_point'];
        $status = $qty <= 0 ? 'out' : ($reorder > 0 && $qty <= $reorder ? 'low' : 'ok');
        foreach ($by as $b) {
            if (isset($whTot[$b['warehouse_id']])) {
                $whTot[$b['warehouse_id']]['count']++;
                $whTot[$b['warehouse_id']]['value'] += max(0, $b['qty']) * $cost;
            }
        }
        $items[] = ['id' => $id, 'code' => (string)$p['code'], 'name' => $p['name'], 'unit' => $p['unit'] ?: 'عدد',
            'group' => (string)$p['group_name'], 'barcode' => (string)$p['barcode'], 'qty' => $qty, 'reorder_point' => $reorder,
            'sale_price' => (float)$p['sale_price'], 'cost' => round($cost), 'value' => round(max(0, $qty) * $cost),
            'status' => $status, 'warehouses' => $by];
        $tot['count']++;
        $tot['qty'] += $qty;
        $tot['value'] += round(max(0, $qty) * $cost);
        $tot[$status === 'ok' ? 'qty_ok' : $status] = ($tot[$status === 'ok' ? 'qty_ok' : $status] ?? 0) + 1;
    }
    unset($tot['qty_ok']);
    return ['warehouses' => array_map(fn($w) => ['id' => (int)$w['id'], 'name' => $w['name'], 'is_default' => (bool)$w['is_default'],
        'count' => $whTot[$w['id']]['count'], 'value' => round($whTot[$w['id']]['value'])], $whs),
        'items' => $items, 'totals' => $tot, 'currency' => 'ریال'];
}
