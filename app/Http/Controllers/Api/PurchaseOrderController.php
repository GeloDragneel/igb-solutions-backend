<?php

namespace App\Http\Controllers\Api;

use App\Models\ChartsOfAccount;
use App\Models\RequestTicket;
use App\Models\TicketData;
use App\Models\VendorCustomer;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Filesystem\FilesystemAdapter;

/**
 * Purchases → Purchase Order (old Purchase_Order.php + function/Purchase_Order.php)
 * Tables: t_porder_master / t_porder_detail, statuses in m_pos_status.
 *
 * Answers with the same field names as the invoice pages (invoice_no, transaction_date,
 * customer_id, status_name …) so PurchaseOrderDetail.jsx can share the invoice layout.
 * Lists (vendors, items, accounts, ATC) come from GET /purchases/lookups.
 */
class PurchaseOrderController extends PurchaseController
{
    protected const PM = 't_porder_master';
    protected const PD = 't_porder_detail';
    protected string $apFolder = 'Vendor_Puchase_Order'; // same (misspelled) folder as the old system
    protected string $apModule = 'Vendors / Purchase Order';

    private function poFind(int $addedBy, string $col, $value): ?object
    {
        return DB::table(self::PM)->where('added_by', $addedBy)->where($col, $value)->first();
    }

    private function poStatusName(?int $id): string
    {
        return $id ? (string) DB::table('m_pos_status')->where('id', $id)->value('name') : '';
    }

    private function poOpenStatus(): int
    {
        return (int) (DB::table('m_pos_status')->whereRaw("UPPER(name) LIKE 'OPEN%'")->value('id') ?: 1);
    }

    /* ---------- received so far on a PO (Receive Inventory: t_grn_detail, not VOID) ---------- */
    private function poReceipt(object $po): array
    {
        $ordered = (float) DB::table(self::PD)->where('po_number', $po->po_number)->sum('qty');
        $received = 0.0;
        if (Schema::hasTable('t_grn_detail')) {
            $cols = Schema::getColumnListing('t_grn_detail');
            $qty  = in_array('qty', $cols, true) ? 'qty' : (in_array('quantity', $cols, true) ? 'quantity' : null);
            $q = DB::table('t_grn_detail');
            if (in_array('po_number', $cols, true)) $q->where('po_number', $po->po_number);
            elseif (in_array('po_id', $cols, true)) $q->whereIn('po_id', DB::table(self::PD)->where('po_number', $po->po_number)->pluck('id')->push($po->id));
            else $q = null;
            if ($q && $qty) {
                if (in_array('grn_status_id', $cols, true)) $q->where(fn ($w) => $w->whereNull('grn_status_id')->orWhere('grn_status_id', '<>', 5));
                $received = (float) $q->sum($qty);
            }
        }
        return ['ordered' => round($ordered, 4), 'received' => round($received, 4), 'left' => round(max(0, $ordered - $received), 4)];
    }

    /** received qty per item on this PO: [item_id => qty] (Receive Inventory, not VOID) */
    private function poReceivedByItem(object $po): array
    {
        if (!Schema::hasTable('t_grn_detail')) return [];
        $cols = Schema::getColumnListing('t_grn_detail');
        $qty  = in_array('qty', $cols, true) ? 'qty' : (in_array('quantity', $cols, true) ? 'quantity' : null);
        if (!$qty || !in_array('item_id', $cols, true)) return [];
        $q = DB::table('t_grn_detail');
        if (in_array('po_number', $cols, true)) $q->where('po_number', $po->po_number);
        elseif (in_array('po_id', $cols, true)) $q->whereIn('po_id', DB::table(self::PD)->where('po_number', $po->po_number)->pluck('id')->push($po->id));
        else return [];
        if (in_array('grn_status_id', $cols, true)) $q->where(fn ($w) => $w->whereNull('grn_status_id')->orWhere('grn_status_id', '<>', 5));
        return $q->groupBy('item_id')->selectRaw("item_id, SUM({$qty}) AS n")->pluck('n', 'item_id')->map(fn ($v) => (float) $v)->all();
    }

    /* ---------- Shipping Terms · Payment Terms · Ship To · Delivery Method (one list per client) ---------- */
    private const PO_TERMS = [
        'shipping_terms'   => ['table' => 'm_shipping_terms',   'col' => 'shipping_term_id',   'label' => 'Shipping Terms'],
        'payment_terms'    => ['table' => 'm_payment_terms',    'col' => 'payment_term_id',    'label' => 'Payment Terms'],
        'ship_to'          => ['table' => 'm_ship_to',          'col' => 'ship_to_id',         'label' => 'Ship To'],
        'delivery_methods' => ['table' => 'm_delivery_methods', 'col' => 'delivery_method_id', 'label' => 'Delivery Method'],
    ];

    /** the 4 picked values from the request (validated here: apRules() doesn't know them) */
    private function poTermIds(array $in, int $addedBy): array
    {
        $out = [];
        foreach (self::PO_TERMS as $t) {
            $id = (int) ($in[$t['col']] ?? 0);
            // only an id of this client's own list
            $out[$t['col']] = $id > 0 && Schema::hasTable($t['table'])
                && DB::table($t['table'])->where('id', $id)->where('client_id', $addedBy)->exists() ? $id : null;
        }
        return $out;
    }

    /* GET /api/purchase-orders/terms   the 4 lists of this client */
    public function poTerms(Request $request)
    {
        $addedBy = $this->addedByFor($request);
        $out = [];
        foreach (self::PO_TERMS as $kind => $t) {
            $out[$kind] = $addedBy && Schema::hasTable($t['table'])
                ? DB::table($t['table'])->where('client_id', $addedBy)->orderBy('name')->get(['id', 'name'])
                : [];
        }
        return response()->json($out);
    }

    /* POST /api/purchase-orders/terms/{kind}   { name }   + Add New (same name again → the existing one) */
    public function poTermAdd(Request $request, string $kind)
    {
        abort_unless(isset(self::PO_TERMS[$kind]), 404, 'Unknown list.');
        $t = self::PO_TERMS[$kind];
        $d = $request->validate(['name' => ['required', 'string', 'max:150']], ['name.required' => "{$t['label']} is required."]);
        $addedBy = $this->addedByFor($request);
        if (!$addedBy) return response()->json(['message' => 'No client account selected.'], 422);

        $name = trim($d['name']);
        $row = DB::table($t['table'])->where('client_id', $addedBy)->where('name', $name)->first(['id', 'name']);
        if (!$row) {
            $id = DB::table($t['table'])->insertGetId(['client_id' => $addedBy, 'name' => $name, 'created_at' => now(), 'updated_at' => now()]);
            $row = (object) ['id' => $id, 'name' => $name];
        }
        return response()->json(['id' => (int) $row->id, 'name' => $row->name]);
    }

    /** who is doing it (staff / client login) */
    private function actorName(Request $request): string
    {
        $u = $request->user();
        foreach (['name', 'full_name', 'username', 'user_name', 'email'] as $c) {
            if (!empty($u->{$c})) return (string) $u->{$c};
        }
        return (string) ($u->id ?? '');
    }

    /* GET /api/purchase-orders/{poNumber}/logs   force-close log: PO qty vs received qty per item */
    public function poLogs(Request $request, string $poNumber)
    {
        $po = $this->poFind($this->addedByFor($request), 'po_number', $poNumber);
        if (!$po) return response()->json(['message' => 'Purchase order not found.'], 404);
        if (!Schema::hasTable('t_porder_close_log')) return response()->json(['rows' => []]);

        $rows = DB::table('t_porder_close_log')->where('po_number', $po->po_number)->orderByDesc('id')->get()
            ->map(fn ($r) => [
                'id'          => $r->id,
                'date'        => (string) $r->created_at,
                'item'        => trim(($r->item_code ? $r->item_code . ' – ' : '') . ($r->item_description ?? '')) ?: '—',
                'ordered'     => (float) $r->ordered_qty,
                'received'    => (float) $r->received_qty,
                'closed'      => (float) $r->closed_qty,
                'closed_by'   => $r->closed_by_name,
                'old_status'  => $this->poStatusName($r->old_status_id),
                'new_status'  => $this->poStatusName($r->new_status_id),
            ]);
        return response()->json(['rows' => $rows]);
    }

    /** the existing "Closed" status of m_pos_status (id 3) */
    private function poClosedStatus(): int
    {
        return (int) (DB::table('m_pos_status')->whereRaw("UPPER(TRIM(name)) = 'CLOSED'")->value('id') ?: 3);
    }

    /* POST /api/purchase-orders/{id}/force-close
       partly received PO → Closed (m_pos_status id 3): what is left can't be received any more (the received part stays) */
    public function poForceClose(Request $request, int $id)
    {
        $po = $this->poFind($this->addedByFor($request), 'id', $id);
        if (!$po) return response()->json(['message' => 'Purchase order not found.'], 404);
        if ((int) $po->po_status_id === 5) return response()->json(['message' => 'This purchase order is void.'], 422);
        $closedId = $this->poClosedStatus();
        if ((int) $po->po_status_id === $closedId) return response()->json(['message' => 'This purchase order is already closed.'], 422);
        if (RequestTicket::where('reference_no', $po->po_number)->where('is_status', 0)->exists()) {
            return response()->json(['message' => 'A ticket is pending for this purchase order.'], 422);
        }

        // partly received: the PO status says PARTIAL (set by Receive Inventory), or the received qty is between 0 and the ordered qty
        $r = $this->poReceipt($po);
        $partial = stripos($this->poStatusName($po->po_status_id), 'PARTIAL') !== false
            || ($r['received'] > 0 && $r['left'] > 0);
        if (!$partial) {
            return response()->json(['message' => $r['received'] <= 0
                ? 'Nothing has been received on this PO yet. Void it instead.'
                : 'This PO is fully received already.'], 422);
        }

        DB::transaction(function () use ($po, $closedId, $request) {
            // log: every PO line with its PO qty, received qty and the qty that is closed
            if (Schema::hasTable('t_porder_close_log')) {
                $got = $this->poReceivedByItem($po);
                $lines = DB::table(self::PD . ' as L')->leftJoin('m_items as I', 'I.id', '=', 'L.item_id')
                    ->where('L.po_number', $po->po_number)->orderBy('L.id')
                    ->get(['L.id', 'L.item_id', 'L.qty', 'I.item_code', 'I.description']);
                $left = $got;                                    // received qty is used up line by line (same item on 2 lines)
                $now = now();
                $by = $this->actorName($request);
                foreach ($lines as $l) {
                    $ordered = (float) $l->qty;
                    $rec = min($ordered, (float) ($left[$l->item_id] ?? 0));
                    if (isset($left[$l->item_id])) $left[$l->item_id] -= $rec;
                    DB::table('t_porder_close_log')->insert([
                        'po_master_id' => $po->id, 'po_number' => $po->po_number, 'po_detail_id' => $l->id,
                        'item_id' => $l->item_id, 'item_code' => $l->item_code, 'item_description' => $l->description,
                        'ordered_qty' => $ordered, 'received_qty' => $rec, 'closed_qty' => max(0, $ordered - $rec),
                        'old_status_id' => $po->po_status_id, 'new_status_id' => $closedId,
                        'closed_by' => $request->user()->id ?? null, 'closed_by_name' => mb_substr($by, 0, 150),
                        'added_by' => $po->added_by, 'created_at' => $now, 'updated_at' => $now,
                    ]);
                }
            }

            DB::table(self::PM)->where('id', $po->id)->update($this->apOnly(self::PM, ['po_status_id' => $closedId, 'updated_at' => now()]));
            DB::table(self::PD)->where('po_number', $po->po_number)->update($this->apOnly(self::PD, ['po_status_id' => $closedId, 'updated_at' => now()]));
        });

        return response()->json([
            'message' => $r['received'] > 0
                ? "{$po->po_number} closed · {$r['received']} of {$r['ordered']} received, {$r['left']} will not be received"
                : "{$po->po_number} closed",
            'status'  => 'Closed',
        ]);
    }

    /* GET /api/purchase-orders?from=2026-01&to=2026-09   (old qry2_purchase_master.php) */
    public function poIndex(Request $request)
    {
        $d = $request->validate(['from' => ['required', 'date_format:Y-m'], 'to' => ['required', 'date_format:Y-m']]);
        $addedBy = $this->addedByFor($request);
        if (!$addedBy) return response()->json([]);

        $start = Carbon::createFromFormat('Y-m-d', $d['from'] . '-01')->startOfMonth()->toDateString();
        $end   = Carbon::createFromFormat('Y-m-d', $d['to'] . '-01')->endOfMonth()->toDateString();

        $list = DB::table(self::PM)
            ->where('added_by', $addedBy)
            ->whereBetween('po_date', [$start, $end])
            ->orderByDesc('id')
            ->get(['id', 'po_number', 'document_no', 'po_date', 'total', 'grand_total', 'vendor_id', 'po_status_id']);

        $vendors  = VendorCustomer::whereIn('id', $list->pluck('vendor_id')->filter()->unique())->pluck('name', 'id');
        $statuses = DB::table('m_pos_status')->pluck('name', 'id');
        $tickets  = RequestTicket::whereIn('reference_no', $list->pluck('po_number'))->where('is_status', 0)->pluck('ticket_no', 'reference_no');

        return response()->json($list->map(fn ($r) => [
            'id'               => $r->id,
            'customer_name'    => trim((string) ($vendors[$r->vendor_id] ?? '')) ?: '—',
            'invoice_no'       => $r->po_number,
            'document_no'      => $r->document_no,
            'transaction_date' => $r->po_date ? substr((string) $r->po_date, 0, 10) : null,
            'total'            => (float) $r->total,
            'status'           => $statuses[$r->po_status_id] ?? '',
            'ticket_no'        => $tickets[$r->po_number] ?? null,
        ])->values());
    }

    /* GET /api/purchase-orders/{poNumber} */
    public function poShow(Request $request, string $poNumber)
    {
        $po = $this->poFind($this->addedByFor($request), 'po_number', $poNumber);
        if (!$po) return response()->json(['message' => 'Purchase order not found.'], 404);

        $lines = DB::table(self::PD . ' as L')
            ->leftJoin('m_items as I', 'I.id', '=', 'L.item_id')
            ->leftJoin('m_unit_type as U', 'U.id', '=', 'L.unit_type_id')
            ->where('L.po_number', $po->po_number)
            ->orderBy('L.id')
            ->get(['L.*', DB::raw("COALESCE(I.item_code, '') as item_code"), DB::raw("COALESCE(I.description, '') as item_description"), DB::raw("COALESCE(U.name, '') as unit")])
            ->map(fn ($l) => (array) $l + ['quantity' => $l->qty, 'unit_price' => $l->price, 'amount' => $l->total]);

        $invoice = (array) $po + [
            'invoice_no'        => $po->po_number,
            'transaction_date'  => $po->po_date,
            'customer_id'       => $po->vendor_id,
            'trans_type'        => 'posted',
            'declared_month_ym' => $po->declared_month ?: ($po->po_date ? substr((string) $po->po_date, 0, 7) : null),
            'vt_month_ym'       => $po->{'2307_vt_declared_month'} ?? null,
            'it_month_ym'       => $po->{'2307_it_declared_month'} ?? null,
            'status_name'       => (int) $po->po_status_id === 5 ? 'VOID' : $this->poStatusName($po->po_status_id),
            'audit_status_name' => '',
        ];

        $ticket = RequestTicket::where('reference_no', $po->po_number)->where('is_status', 0)
            ->latest('id')->first(['ticket_no', 'remarks', 'action', 'created_at']);

        return response()->json([
            'invoice'  => $invoice,
            'lines'    => $lines,
            'customer' => $po->vendor_id ? VendorCustomer::find($po->vendor_id, $this->apVendorCols()) : null,
            'ticket'   => $ticket,
            'scan'     => null,
            'receipt'  => $this->poReceipt($po),            // { ordered, received, left } → Force Close button
        ]);
    }

    /* GET /api/purchase-orders/ticket/{ticketNo} */
    public function poTicketShow(Request $request, string $ticketNo)
    {
        $res  = $this->apTicketShow($request, $ticketNo);
        $data = $res->getData(true);
        if (isset($data['master'])) {
            $data['master']['transaction_date'] ??= $data['master']['po_date'] ?? null;
            $data['master']['invoice_no'] ??= $data['master']['po_number'] ?? null;
        }
        return response()->json($data, $res->getStatusCode());
    }

    /* GET /api/purchase-orders/{poNumber}/journal   (old doGetJournalList) */
    public function poJournal(Request $request, string $poNumber)
    {
        $addedBy = $this->addedByFor($request);
        $po = $this->poFind($addedBy, 'po_number', $poNumber);
        if (!$po || (int) $po->po_status_id === 5) return response()->json(['journal' => [], 'collection' => [], 'collection_label' => 'PAYMENT']);
 
        $vatCol = $this->apFirstCol('m_items', ['input_vat_account']);
        $lines = DB::table(self::PD . ' as L')
            ->leftJoin('m_items as I', 'I.id', '=', 'L.item_id')
            ->where('L.po_number', $po->po_number)
            ->get(array_filter(['L.account_code', 'L.input_vat', 'L.net_of_vat', 'L.vat_exempt', 'L.non_vat', 'L.zero_rated',
                $vatCol ? "I.{$vatCol} as vat_account" : null]));
 
        // payments made on this PO (Pay Bill saves ap_invoice_no = po_number)
        $pays = DB::table('t_pvoucher_detail as D')->join('t_pvoucher_master as M', 'M.pvoucher_no', '=', 'D.pvoucher_no')
            ->where('D.ap_invoice_no', $po->po_number)->orderBy('M.pv_date')->orderBy('M.id')
            ->get(['M.pvoucher_no', 'M.pv_date', 'M.bank', 'D.acc_code', 'D.amount']);
 
        $codes = collect([$po->account_code])->merge($lines->pluck('account_code'))->merge($lines->pluck('vat_account'))
            ->merge($pays->pluck('bank'))->merge($pays->pluck('acc_code'))->filter()->unique();
        $names = ChartsOfAccount::where('added_by', $addedBy)->whereIn('account_code', $codes)->pluck('account_name', 'account_code');
 
        $rows = [];
        $rows[] = ['account_code' => (string) $po->account_code, 'account_name' => (string) ($names[$po->account_code] ?? ''), 'debit' => 0, 'credit' => round((float) $po->grand_total, 2)];
        if ((float) $po->{'2307_it'} > 0) $rows[] = ['account_code' => '21910', 'account_name' => 'Withholding Tax', 'debit' => 0, 'credit' => round((float) $po->{'2307_it'}, 2)];
        if ((float) $po->{'2307_vt'} > 0) $rows[] = ['account_code' => '21910', 'account_name' => 'Withholding Tax', 'debit' => 0, 'credit' => round((float) $po->{'2307_vt'}, 2)];
        foreach ($lines as $l) {
            if ((float) $l->input_vat > 0) {
                $code = ($l->vat_account ?? null) ?: '0';
                $rows[] = ['account_code' => (string) $code, 'account_name' => (string) ($names[$code] ?? 'Tax'), 'debit' => round((float) $l->input_vat, 2), 'credit' => 0];
            }
            $code = $l->account_code ?: '0';
            $rows[] = ['account_code' => (string) $code, 'account_name' => (string) ($names[$code] ?? ''),
                'debit' => round((float) $l->net_of_vat + (float) $l->vat_exempt + (float) $l->non_vat + (float) $l->zero_rated, 2), 'credit' => 0];
        }
 
        // PAYMENT: Debit the payable account(s), Credit the cash / bank account(s)
        $payment = [];
        foreach ($pays->groupBy(fn ($p) => $p->acc_code ?: $po->account_code) as $code => $g) {
            $payment[] = ['account_code' => (string) $code, 'account_name' => (string) ($names[$code] ?? ''), 'debit' => round((float) $g->sum('amount'), 2), 'credit' => 0];
        }
        foreach ($pays->groupBy(fn ($p) => $p->bank ?: '0') as $code => $g) {
            $payment[] = ['account_code' => (string) $code, 'account_name' => (string) ($names[$code] ?? ''), 'debit' => 0, 'credit' => round((float) $g->sum('amount'), 2)];
        }
 
        return response()->json(['journal' => $rows, 'collection' => $payment, 'collection_label' => 'PAYMENT']);
    }

    /* POST /api/purchase-orders   new PO (old save_transaction_po.php) */
    public function poStore(Request $request)
    {
        $d = $request->validate($this->apRules());
        $addedBy = $this->addedByFor($request);
        if (!$addedBy) return response()->json(['message' => 'No client account selected.'], 422);
        if (empty($d['customer_id'])) return response()->json(['message' => 'Please select vendor'], 422);

        if (!empty($d['document_no']) && DB::table(self::PM)->where('added_by', $addedBy)
                ->where('document_no', $d['document_no'])->where('po_status_id', '<>', 5)->exists()) {
            return response()->json(['message' => 'Document number already in use'], 422);
        }

        $lineRows = $this->apLineRows($d['lines']);
        $statusId = $this->poOpenStatus();
        $vt = ($d['atc_vt_id'] ?? null) ? ($d['vt_month'] ?? null) : null;
        $it = ($d['atc_it_id'] ?? null) ? ($d['it_month'] ?? null) : null;

        return DB::transaction(function () use ($d, $addedBy, $lineRows, $statusId, $vt, $it, $request) {
            do {
                $poNumber = 'PO-' . date('ymd-his') . random_int(10000, 99999);
            } while (DB::table(self::PM)->where('po_number', $poNumber)->exists());

            $master = [
                'po_number' => $poNumber, 'document_no' => $d['document_no'] ?? null, 'po_date' => $d['transaction_date'],
                'vendor_id' => (int) $d['customer_id'], 'total' => $this->r4($d['total']), 'sub_total' => $this->r4($d['sub_total']),
                'grand_total' => $this->r4($d['grand_total']), 'po_status_id' => $statusId, 'added_by' => $addedBy,
                'document_type_id' => (int) ($d['document_type_id'] ?? 0), 'remarks' => $d['remarks'] ?? null,
                'declared_month' => $d['declared_month'], 'trans_type' => 'Purchases',
                'currency' => $d['currency'] ?? 'PHP', 'ex_rate' => $d['ex_rate'] ?? 1,
                'atc_vt_id' => (int) ($d['atc_vt_id'] ?? 0), '2307_vt' => $this->r4($d['2307_vt'] ?? 0),
                'atc_it_id' => (int) ($d['atc_it_id'] ?? 0), '2307_it' => $this->r4($d['2307_it'] ?? 0),
                'discount_type_id' => (int) ($d['discount_type_id'] ?? 0), 'discount_amount' => $this->r4($d['discount_amount'] ?? 0),
                'account_code' => $d['account_code'] ?? null,
                '2307_vt_declared_month' => $vt, '2307_it_declared_month' => $it,
                'created_at' => now(), 'updated_at' => now(),
            ] + $this->poTermIds($request->all(), $addedBy);
            $id = DB::table(self::PM)->insertGetId($this->apOnly(self::PM, $master));

            foreach ($lineRows as $l) {
                DB::table(self::PD)->insert($this->apOnly(self::PD, [
                    'po_master_id' => $id, 'po_number' => $poNumber, 'item_id' => $l['item_id'],
                    'qty' => $l['quantity'], 'price' => $l['unit_price'], 'total' => $l['amount'],
                    'unit_type_id' => (int) ($l['unit_type_id'] ?? 0), 'account_code' => $l['account_code'],
                    'currency' => $master['currency'], 'ex_rate' => $master['ex_rate'], 'po_status_id' => $statusId,
                    'input_vat' => $l['input_vat'], 'net_of_vat' => $l['net_of_vat'], 'vat_exempt' => $l['vat_exempt'],
                    'non_vat' => $l['non_vat'], 'zero_rated' => $l['zero_rated'], 'discount_amount' => $l['discount_amount'],
                    '2307_vt_declared_month' => $vt, '2307_it_declared_month' => $it,
                    'po_date' => $master['po_date'], 'document_no' => $master['document_no'], 'vendor_id' => $master['vendor_id'],
                    'trans_type' => 'Purchases', 'created_at' => now(), 'updated_at' => now(),
                ]));
            }

            return response()->json(['id' => $id, 'invoice_no' => $poNumber]);
        });
    }

    /* POST /api/purchase-orders/{id}/ticket   EDIT / VOID for admin approval (old save_transaction_po_ticket.php) */
    public function poTicket(Request $request, int $id)
    {
        $payload = $request->filled('data') ? (json_decode($request->input('data'), true) ?? []) : $request->except('attachment');
        $d = Validator::make($payload,
            ['ticket_remarks' => ['required', 'string', 'max:1000'], 'action' => ['nullable', 'in:EDIT,VOID']] + $this->apRules(),
            ['ticket_remarks.required' => 'Remarks / Reason is required.']
        )->validate();

        $addedBy = $this->addedByFor($request);
        $po = $this->poFind($addedBy, 'id', $id);
        if (!$po) return response()->json(['message' => 'Purchase order not found.'], 404);
        if (RequestTicket::where('reference_no', $po->po_number)->where('is_status', 0)->exists()) {
            return response()->json(['message' => 'Ticket is pending for this purchase order. Please wait for approval.'], 422);
        }

        $attachment = $po->attachment;
        if ($request->hasFile('attachment')) {
            $file  = $request->file('attachment');
            $clean = preg_replace('/[^A-Za-z0-9_\.-]/', '_', $file->getClientOriginalName());
            Storage::disk('s3')->putFileAs($this->apFolder, $file, $po->id . '-' . $clean);
            $attachment = $clean;
        }

        $action = $d['action'] ?? 'EDIT';
        $status = $action === 'VOID' ? 5 : (int) $po->po_status_id;
        $vt = ($d['atc_vt_id'] ?? null) ? ($d['vt_month'] ?? null) : null;
        $it = ($d['atc_it_id'] ?? null) ? ($d['it_month'] ?? null) : null;

        $dataMaster = [
            'table_id' => $po->id, 'po_number' => $po->po_number, 'po_date' => $d['transaction_date'],
            'vendor_id' => $d['customer_id'] ?: null, 'document_no' => $d['document_no'] ?? null,
            'sub_total' => $this->r4($d['sub_total']), 'total' => $this->r4($d['total']), 'grand_total' => $this->r4($d['grand_total']),
            'po_status_id' => $status, 'remarks' => $d['remarks'] ?? null, 'account_code' => $d['account_code'] ?? null,
            'currency' => $d['currency'] ?? 'PHP', 'ex_rate' => $d['ex_rate'] ?? 1,
            'atc_vt_id' => $d['atc_vt_id'] ?: null, 'atc_it_id' => $d['atc_it_id'] ?: null,
            '2307_vt' => $this->r4($d['2307_vt'] ?? 0), '2307_it' => $this->r4($d['2307_it'] ?? 0),
            'discount_type_id' => $d['discount_type_id'] ?? null, 'discount_amount' => $this->r4($d['discount_amount'] ?? 0),
            'declared_month' => $d['declared_month'], 'attachment' => $attachment,
            '2307_vt_declared_month' => $vt, '2307_it_declared_month' => $it, 'added_by' => $addedBy, 'trans_type' => 'Purchases',
        ] + $this->poTermIds($payload, $addedBy);
        $dataDetail = array_map(fn ($l) => [
            'po_master_id' => $po->id, 'po_number' => $po->po_number, 'item_id' => $l['item_id'],
            'qty' => $l['quantity'], 'quantity' => $l['quantity'], 'price' => $l['unit_price'], 'unit_price' => $l['unit_price'],
            'total' => $l['amount'], 'amount' => $l['amount'], 'unit_type_id' => $l['unit_type_id'], 'account_code' => $l['account_code'],
            'input_vat' => $l['input_vat'], 'net_of_vat' => $l['net_of_vat'], 'vat_exempt' => $l['vat_exempt'],
            'non_vat' => $l['non_vat'], 'zero_rated' => $l['zero_rated'], 'discount_amount' => $l['discount_amount'],
            'po_status_id' => $status,
        ], $this->apLineRows($d['lines']));

        $ticketNo = 'TCKT' . date('ymd-his') . random_int(10000, 99999);
        DB::transaction(function () use ($po, $d, $addedBy, $ticketNo, $action, $dataMaster, $dataDetail) {
            RequestTicket::create([
                'ticket_no' => $ticketNo, 'reference_no' => $po->po_number, 'remarks' => $d['ticket_remarks'],
                'module' => $this->apModule, 'action' => $action, 'request_by' => $addedBy, 'is_status' => 0,
            ]);
            TicketData::create(['ticket_no' => $ticketNo, 'data_master' => $dataMaster, 'data_detail' => $dataDetail]);
        });

        return response()->json(['message' => 'Ticket successfully saved', 'ticket_no' => $ticketNo]);
    }

    /* POST /api/purchase-orders/{id}/attachment */
    public function poUploadAttachment(Request $request, int $id)
    {
        $request->validate(['file' => ['required', 'file', 'max:10240', 'mimes:pdf,doc,docx,xls,xlsx,jpg,jpeg,png,gif']]);
        $po = $this->poFind($this->addedByFor($request), 'id', $id);
        if (!$po) return response()->json(['message' => 'Purchase order not found.'], 404);

        $file  = $request->file('file');
        $clean = preg_replace('/[^A-Za-z0-9_\.-]/', '_', $file->getClientOriginalName());
        Storage::disk('s3')->putFileAs($this->apFolder, $file, $po->id . '-' . $clean);
        DB::table(self::PM)->where('id', $po->id)->update(['attachment' => $clean, 'updated_at' => now()]);
        return response()->json(['attachment' => $clean]);
    }

    /* GET /api/purchase-orders/{id}/attachment */

    public function poAttachment(Request $request, int $id)
    {
        $po = $this->poFind($this->addedByFor($request), 'id', $id);
        if (!$po) return response()->json(['message' => 'No attachment.'], 404);

        $file = trim((string) ($po->attachment ?? ''));
        if ($file === '') return response()->json(['message' => 'No attachment.'], 404);
        if (preg_match('/^https?:\/\//i', $file)) return response()->json(['url' => $file]);

        /** @var FilesystemAdapter $disk */
        $disk = Storage::disk('s3');

        foreach (["{$this->apFolder}/{$po->id}-{$file}", "{$this->apFolder}/{$file}"] as $key) {
            if ($disk->exists($key)) return $disk->response($key, $file);
        }
        return response()->json(['message' => 'File not found on storage.'], 404);
    }
    
}