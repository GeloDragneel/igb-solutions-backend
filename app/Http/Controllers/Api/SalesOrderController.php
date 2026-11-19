<?php

namespace App\Http\Controllers\Api;

use App\Models\ChartsOfAccount;
use App\Models\ClientAccount;
use App\Models\DeclaredMonthSettings;
use App\Models\InvoiceStatus;
use App\Models\UnitType;
use App\Models\VendorCustomer;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Task → Sales → Sales Order
 *   t_sales_order_master / t_sales_order_detail  (same columns as the sales invoice tables)
 *   so_number  = the order's own number (SO-…), detail lines are linked by so_number
 *   invoice_no = the sales invoice made from the order with "Create Invoice" (empty until then)
 *
 *   GET  /sales-orders?from=2026-01&to=2026-12      list
 *   GET  /sales-orders/order/{soNumber}              one order + lines (same shape as /sales/invoice/{no})
 *   POST /sales-orders                               new
 *   PUT  /sales-orders/{id}                          edit (not after it was invoiced)
 *   POST /sales-orders/{id}/void
 *   POST /sales-orders/{id}/create-invoice           → a normal sales invoice (same save as Sales), links invoice_no
 *
 * Extends SalesController only for its helpers (who is logged in / which account / r4) and its invoice save.
 */
class SalesOrderController extends SalesController
{
    const M = 't_sales_order_master';
    const D = 't_sales_order_detail';

    /** only the columns the table really has (the tables are copies, so this stays safe) */
    private function only(string $table, array $row): array
    {
        static $cols = [];
        $cols[$table] ??= array_flip(Schema::getColumnListing($table));
        return array_intersect_key($row, $cols[$table]);
    }

    private function statusIdNamed(string $name): ?int
    {
        $id = InvoiceStatus::whereRaw('UPPER(name) LIKE ?', [strtoupper($name) . '%'])->value('id');
        return $id ? (int) $id : null;
    }

    /** OPEN · INVOICED · VOID */
    private function orderStatus(object $o, ?int $voidId): string
    {
        if ($voidId && (int) $o->invoice_status_id === $voidId) return 'VOID';
        return $o->invoice_no ? 'INVOICED' : 'OPEN';
    }

    private function locked(?int $caid, array $months): bool
    {
        $months = array_values(array_unique(array_filter(array_map(fn ($m) => substr((string) $m, 0, 7), $months))));
        if (!$caid || !$months) return false;
        return DeclaredMonthSettings::where('ca_id', $caid)->whereIn(DB::raw('LEFT(declared_month, 7)'), $months)->exists();
    }

    private function monthName(?string $ym): string
    {
        if (!$ym || !preg_match('/^\d{4}-\d{2}$/', $ym)) return (string) $ym;
        return Carbon::createFromFormat('Y-m-d', $ym . '-01')->format('F Y');
    }

    /* ---------------- GET /sales-orders ---------------- */
    public function index(Request $request)
    {
        $d = $request->validate([
            'from' => ['required', 'date_format:Y-m'],
            'to'   => ['required', 'date_format:Y-m'],
        ]);
        $addedBy = $this->addedByFor($request);
        if (!$addedBy) return response()->json([]);

        $list = DB::table(self::M . ' as O')
            ->leftJoin('m_vendor_customer as C', 'C.id', '=', 'O.customer_id')
            ->where('O.added_by', $addedBy)
            ->whereBetween('O.declared_month', [$d['from'], $d['to']])
            ->orderByDesc('O.id')
            ->get(['O.id', 'O.so_number', 'O.invoice_no', 'O.document_no', 'O.transaction_date', 'O.declared_month',
                   'O.grand_total', 'O.account_code', 'O.invoice_status_id', 'C.name as customer_name']);

        $acct = ChartsOfAccount::where('added_by', $addedBy)
            ->whereIn('account_code', $list->pluck('account_code')->filter()->unique()->values())
            ->pluck('account_name', 'account_code');
        $voidId = $this->statusIdNamed('VOID');

        return response()->json($list->map(fn ($o) => [
            'id'               => $o->id,
            'so_number'        => $o->so_number,
            'invoice_no'       => $o->invoice_no,                       // the linked sales invoice
            'customer_name'    => trim((string) $o->customer_name) ?: 'Unknown',
            'document_no'      => $o->document_no,
            'transaction_date' => $o->transaction_date ? substr((string) $o->transaction_date, 0, 10) : null,
            'declared_month'   => $this->monthName($o->declared_month),
            'account'          => $o->account_code ? rtrim($o->account_code . ' - ' . ($acct[$o->account_code] ?? ''), ' -') : '',
            'total'            => (float) $o->grand_total,
            'status'           => $this->orderStatus($o, $voidId),
        ])->values());
    }

    /* ---------------- GET /sales-orders/order/{soNumber} ---------------- */
    public function showOrder(Request $request, string $soNumber)
    {
        $o = DB::table(self::M)->where('so_number', $soNumber)->where('added_by', $this->addedByFor($request))->first();
        if (!$o) return response()->json(['message' => 'Sales order not found.'], 404);

        $lines = DB::table(self::D . ' as L')
            ->leftJoin('m_items as I', 'I.id', '=', 'L.item_id')
            ->leftJoin((new UnitType)->getTable() . ' as U', 'U.id', '=', 'L.unit_type_id')
            ->where('L.so_number', $o->so_number)
            ->orderBy('L.id')
            ->get(['L.*', 'I.item_code', 'I.description as item_description', 'U.name as unit']);

        // same keys as /sales/invoice/{no}, so the invoice form can show it:
        //   invoice_no = the order number · linked_invoice_no = the sales invoice made from it
        $order = (array) $o;
        $order['linked_invoice_no'] = $o->invoice_no;
        $order['invoice_no']        = $o->so_number;
        $order['declared_month_ym'] = $o->declared_month;
        $order['vt_month_ym']       = $o->{'2307_vt_declared_month'} ?? null;
        $order['it_month_ym']       = $o->{'2307_it_declared_month'} ?? null;
        $order['status_name']       = $this->orderStatus($o, $this->statusIdNamed('VOID'));
        $order['audit_status_name'] = '';

        return response()->json([
            'invoice'  => $order,
            'lines'    => $lines,
            'customer' => $o->customer_id ? VendorCustomer::find($o->customer_id, ['id', 'name', 'tin', 'branch_code', 'address', 'city_country']) : null,
            'ticket'   => null,
            'scan'     => null,
        ]);
    }

    /* ---------------- POST /sales-orders · PUT /sales-orders/{id} ---------------- */
    public function storeOrder(Request $request)
    {
        return $this->saveOrder($request, null);
    }

    public function updateOrder(Request $request, int $id)
    {
        return $this->saveOrder($request, $id);
    }

    private function saveOrder(Request $request, ?int $id)
    {
        $d = $request->validate([
            'customer_id'      => ['nullable', 'integer'],
            'transaction_date' => ['required', 'date'],
            'declared_month'   => ['required', 'date_format:Y-m'],
            'document_no'      => ['nullable', 'string', 'max:100'],
            'document_type_id' => ['nullable', 'integer'],
            'account_code'     => ['nullable', 'string', 'max:15'],
            'currency'         => ['nullable', 'string', 'max:10'],
            'ex_rate'          => ['nullable', 'numeric', 'gt:0'],
            'remarks'          => ['nullable', 'string', 'max:255'],
            'atc_vt_id'        => ['nullable', 'integer'],
            'atc_it_id'        => ['nullable', 'integer'],
            'vt_month'         => ['nullable', 'date_format:Y-m'],
            'it_month'         => ['nullable', 'date_format:Y-m'],
            'discount_type_id' => ['nullable', 'integer'],
            'sub_total'        => ['required', 'numeric'],
            '2307_vt'          => ['nullable', 'numeric', 'min:0'],
            '2307_it'          => ['nullable', 'numeric', 'min:0'],
            'discount_amount'  => ['nullable', 'numeric', 'min:0'],
            'total'            => ['required', 'numeric'],
            'grand_total'      => ['required', 'numeric', 'min:0'],
            'lines'                => ['required', 'array', 'min:1'],
            'lines.*.item_id'      => ['required', 'integer'],
            'lines.*.unit_type_id' => ['nullable', 'integer'],
            'lines.*.qty'          => ['required', 'numeric', 'gt:0'],
            'lines.*.price'        => ['required', 'numeric', 'min:0'],
            'lines.*.discount'     => ['nullable', 'numeric', 'min:0'],
            'lines.*.vat_type'     => ['required', 'in:vatable,exempt,zero,nonvat'],
            'lines.*.account_code' => ['nullable', 'string', 'max:10'],
        ]);

        $addedBy = $this->addedByFor($request);
        $caid    = $this->caidFor($request);
        if (!$addedBy) return response()->json(['message' => 'No client account selected.'], 422);

        if ($this->locked($caid, [$d['declared_month'], $d['vt_month'] ?? null, $d['it_month'] ?? null])) {
            return response()->json(['message' => 'Declared Month is disabled'], 422);
        }

        $existing = null;
        if ($id) {
            $existing = DB::table(self::M)->where('id', $id)->where('added_by', $addedBy)->first();
            if (!$existing) return response()->json(['message' => 'Sales order not found.'], 404);
            if ($existing->invoice_no) return response()->json(['message' => "Already invoiced as {$existing->invoice_no}. It can't be edited."], 422);
            $voidId = $this->statusIdNamed('VOID');
            if ($voidId && (int) $existing->invoice_status_id === $voidId) return response()->json(['message' => 'This sales order is void.'], 422);
        }

        // line amounts (same as the sales invoice)
        $rows = [];
        $linesNet = 0;
        foreach ($d['lines'] as $l) {
            $gross  = $this->r4($l['qty'] * $l['price']);
            $disc   = $this->r4($l['discount'] ?? 0);
            $amount = $this->r4($gross - $disc);
            $vat = $net = $exempt = $non = $zero = 0;
            switch ($l['vat_type']) {
                case 'exempt': $exempt = $amount; break;
                case 'zero':   $zero   = $amount; break;
                case 'nonvat': $non    = $amount; break;
                default:       $vat = $this->r4($amount * 12 / 112); $net = $this->r4($amount - $vat);
            }
            $linesNet += $amount;
            $rows[] = [
                'item_id' => $l['item_id'], 'unit_type_id' => $l['unit_type_id'] ?? null,
                'quantity' => $l['qty'], 'unit_price' => $l['price'], 'amount' => $gross, 'discount_amount' => $disc,
                'input_vat' => $vat, 'net_of_vat' => $net, 'vat_exempt' => $exempt, 'non_vat' => $non, 'zero_rated' => $zero,
                'account_code' => $l['account_code'] ?? null,
            ];
        }

        $wtaxVt = $this->r4($d['2307_vt'] ?? 0);
        $wtaxIt = $this->r4($d['2307_it'] ?? 0);
        $grand  = $this->r4($d['grand_total']);
        $exRate = (float) ($d['ex_rate'] ?? 1) ?: 1;
        $expected = $this->r4($linesNet * $exRate - ($wtaxVt + $wtaxIt));
        if (abs($expected - $grand) > 0.01) {
            return response()->json(['message' => "Grand total doesn't match the items (expected {$expected}, got {$grand}). Please refresh and try again."], 422);
        }

        return DB::transaction(function () use ($d, $existing, $addedBy, $caid, $rows, $wtaxVt, $wtaxIt, $grand) {
            $now = now();
            $head = [
                'customer_id'            => $d['customer_id'] ?: null,
                'added_by'               => $addedBy,
                'transaction_date'       => $d['transaction_date'],
                'declared_month'         => $d['declared_month'],
                'account_code'           => $d['account_code'] ?? null,
                'document_no'            => $d['document_no'] ?? null,
                'document_type_id'       => $d['document_type_id'] ?? null,
                'currency'               => $d['currency'] ?? 'PHP',
                'ex_rate'                => $d['ex_rate'] ?? 1,
                'sub_total'              => $this->r4($d['sub_total']),
                'total'                  => $this->r4($d['total']),
                'grand_total'            => $grand,
                'discount_amount'        => $this->r4($d['discount_amount'] ?? 0),
                'discount_type_id'       => $d['discount_type_id'] ?? null,
                'atc_vt_id'              => $d['atc_vt_id'] ?: null,
                'atc_it_id'              => $d['atc_it_id'] ?: null,
                '2307_vt'                => $wtaxVt,
                '2307_it'                => $wtaxIt,
                '2307_vt_declared_month' => ($d['atc_vt_id'] ?? null) ? ($d['vt_month'] ?? null) : null,
                '2307_it_declared_month' => ($d['atc_it_id'] ?? null) ? ($d['it_month'] ?? null) : null,
                'remarks'                => $d['remarks'] ?? null,
                'trans_type'             => 'order',
                'updated_at'             => $now,
            ];

            if ($existing) {
                $id = (int) $existing->id;
                $soNumber = $existing->so_number;
                DB::table(self::M)->where('id', $id)->update($this->only(self::M, $head));
                $daid = $existing->assigned_daid ?? null;
                $auid = $existing->assigned_auid ?? null;
                $statusId = $existing->invoice_status_id;
            } else {
                do {
                    $soNumber = 'SO-' . date('ymd-his') . random_int(10000, 99999);
                } while (DB::table(self::M)->where('so_number', $soNumber)->exists());
                $account  = $caid ? ClientAccount::find($caid) : null;
                $daid     = ($account->assigned_daid ?? 0) ?: null;
                $auid     = ($account->assigned_auid ?? 0) ?: null;
                $statusId = $this->statusIdNamed('UNPAID');
                $seq = (int) DB::table(self::M)->where('added_by', $addedBy)->lockForUpdate()->max('seq_no') + 1;
                $id = (int) DB::table(self::M)->insertGetId($this->only(self::M, $head + [
                    'so_number'         => $soNumber,
                    'invoice_no'        => null,
                    'seq_no'            => $seq,
                    'assigned_daid'     => $daid,
                    'assigned_auid'     => $auid,
                    'invoice_status_id' => $statusId,
                    'created_at'        => $now,
                ]));
            }

            // replace the lines
            DB::table(self::D)->where('so_number', $soNumber)->delete();
            foreach ($rows as $r) {
                DB::table(self::D)->insert($this->only(self::D, $r + [
                    'so_number'              => $soNumber,
                    'invoice_no'             => null,
                    'currency'               => $head['currency'],
                    'ex_rate'                => $head['ex_rate'],
                    'added_by'               => $addedBy,
                    'declared_month'         => $head['declared_month'],
                    'invoice_status_id'      => $statusId,
                    'transaction_date'       => $head['transaction_date'],
                    'document_no'            => $head['document_no'],
                    'customer_id'            => $head['customer_id'],
                    'assigned_daid'          => $daid,
                    'assigned_auid'          => $auid,
                    '2307_vt_declared_month' => $head['2307_vt_declared_month'],
                    '2307_it_declared_month' => $head['2307_it_declared_month'],
                    'trans_type'             => 'order',
                    'created_at'             => $now,
                    'updated_at'             => $now,
                ]));
            }

            // invoice_no = the order number, so the form can open it like an invoice
            return response()->json(['id' => $id, 'invoice_no' => $soNumber, 'so_number' => $soNumber]);
        });
    }

    /* ---------------- POST /sales-orders/{id}/void ---------------- */
    public function voidOrder(Request $request, int $id)
    {
        $o = DB::table(self::M)->where('id', $id)->where('added_by', $this->addedByFor($request))->first();
        if (!$o) return response()->json(['message' => 'Sales order not found.'], 404);
        if ($o->invoice_no) return response()->json(['message' => "Already invoiced as {$o->invoice_no}. Void the invoice instead."], 422);

        $voidId = $this->statusIdNamed('VOID');
        DB::table(self::M)->where('id', $id)->update(['invoice_status_id' => $voidId, 'updated_at' => now()]);
        DB::table(self::D)->where('so_number', $o->so_number)->update(['invoice_status_id' => $voidId]);
        return response()->json(['message' => "{$o->so_number} is void"]);
    }

    /* ---------------- POST /sales-orders/{id}/create-invoice ---------------- */
    public function createInvoice(Request $request, int $id)
    {
        $addedBy = $this->addedByFor($request);
        $o = DB::table(self::M)->where('id', $id)->where('added_by', $addedBy)->first();
        if (!$o) return response()->json(['message' => 'Sales order not found.'], 404);
        if ($o->invoice_no) return response()->json(['message' => "Already invoiced as {$o->invoice_no}.", 'invoice_no' => $o->invoice_no], 422);
        $voidId = $this->statusIdNamed('VOID');
        if ($voidId && (int) $o->invoice_status_id === $voidId) return response()->json(['message' => 'This sales order is void.'], 422);

        $lines = DB::table(self::D)->where('so_number', $o->so_number)->orderBy('id')->get();
        if ($lines->isEmpty()) return response()->json(['message' => 'This sales order has no items.'], 422);

        $vatType = fn ($l) => (float) ($l->vat_exempt ?? 0) > 0 ? 'exempt'
            : ((float) ($l->zero_rated ?? 0) > 0 ? 'zero' : ((float) ($l->non_vat ?? 0) > 0 ? 'nonvat' : 'vatable'));

        // the order → the same body the invoice form sends to POST /sales
        $payload = [
            'customer_id'      => $o->customer_id,
            'transaction_date' => substr((string) $o->transaction_date, 0, 10),
            'declared_month'   => $o->declared_month,
            'document_no'      => $o->document_no,
            'document_type_id' => $o->document_type_id ?? null,
            'account_code'     => $o->account_code,
            'currency'         => $o->currency ?: 'PHP',
            'ex_rate'          => $o->ex_rate ?: 1,
            'remarks'          => trim(($o->remarks ? $o->remarks . ' · ' : '') . "From {$o->so_number}"),
            'atc_vt_id'        => $o->atc_vt_id,
            'atc_it_id'        => $o->atc_it_id,
            'vt_month'         => $o->{'2307_vt_declared_month'} ?? null,
            'it_month'         => $o->{'2307_it_declared_month'} ?? null,
            'discount_type_id' => $o->discount_type_id ?? null,
            'sub_total'        => (float) $o->sub_total,
            '2307_vt'          => (float) ($o->{'2307_vt'} ?? 0),
            '2307_it'          => (float) ($o->{'2307_it'} ?? 0),
            'discount_amount'  => (float) ($o->discount_amount ?? 0),
            'total'            => (float) $o->total,
            'grand_total'      => (float) $o->grand_total,
            'trans_type'       => 'posted',
            'lines'            => $lines->map(fn ($l) => [
                'item_id'      => $l->item_id,
                'unit_type_id' => $l->unit_type_id,
                'qty'          => (float) $l->quantity,
                'price'        => (float) $l->unit_price,
                'discount'     => (float) ($l->discount_amount ?? 0),
                'vat_type'     => $vatType($l),
                'account_code' => $l->account_code,
            ])->all(),
        ];
        $payload['remarks'] = mb_substr($payload['remarks'], 0, 255);

        // saved exactly like Task → Sales → Add Sale (same checks: locked month, document no., totals)
        $sub = Request::create('/api/sales', 'POST', $payload);
        $sub->setUserResolver($request->getUserResolver());
        $res = $this->store($sub);
        if ($res->getStatusCode() !== 200) return $res;

        $invoiceNo = $res->getData(true)['invoice_no'] ?? null;
        if (!$invoiceNo) return response()->json(['message' => "The invoice was saved but its number couldn't be read."], 500);

        DB::table(self::M)->where('id', $o->id)->update(['invoice_no' => $invoiceNo, 'updated_at' => now()]);
        DB::table(self::D)->where('so_number', $o->so_number)->update(['invoice_no' => $invoiceNo]);

        return response()->json(['message' => "{$invoiceNo} created from {$o->so_number}", 'invoice_no' => $invoiceNo]);
    }
}