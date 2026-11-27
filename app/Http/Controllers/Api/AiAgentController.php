<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Header → AI Assistant → agents
 *   Ava · Data Analyst  → pending scanned receipts (t_mobile_transaction) for Sales / Purchases / Expenses
 *   Max · Auditor       → entries still PENDING audit for Sales / Purchases / Expenses
 *
 * routes/api.php (inside the auth:sanctum group):
 *   use App\Http\Controllers\Api\AiAgentController;
 *   Route::get('/ai/agents/analyst', [AiAgentController::class, 'analyst']);
 *   Route::get('/ai/agents/auditor', [AiAgentController::class, 'auditor']);
 */
class AiAgentController extends SalesController
{
    const LIMIT = 100;   // rows per module (the count is always the full number)

    /* GET /api/ai/agents/analyst  → { sales: {count, rows}, purchases: {…}, expenses: {…} } */
    public function analyst(Request $request)
    {
        $addedBy = $this->addedByFor($request);
        $out = [];
        foreach (['sales', 'purchases', 'expenses'] as $type) {
            // same rule as the Scanned Receipts tabs: not yet confirmed / transferred / deleted
            $q = DB::table('t_mobile_transaction')
                ->where('added_by', $addedBy)
                ->whereRaw('LOWER(trans_type) = ?', [$type])
                ->whereNotIn('trans_status', [2, 3, 4, 5]);
            $out[$type] = [
                'count' => (clone $q)->count(),
                'rows'  => $q->orderByDesc('id')->limit(self::LIMIT)
                    ->get(['id', 'filename', 'reference_no', 'trans_status', 'created_at'])
                    ->map(fn ($r) => [
                        'id'        => $r->id,
                        'filename'  => $r->filename,
                        'reference' => $r->reference_no,
                        'date'      => $r->created_at ? substr((string) $r->created_at, 0, 16) : null,
                    ]),
            ];
        }
        return response()->json($out);
    }

    /* GET /api/ai/agents/auditor  → { sales: {count, rows}, purchases: {…}, expenses: {…} } */
    public function auditor(Request $request)
    {
        $addedBy = $this->addedByFor($request);
        $pending = Schema::hasTable('m_audit_status')
            ? DB::table('m_audit_status')->whereRaw("UPPER(name) LIKE 'PENDING%'")->value('id')
            : 1;
        $pending = (int) ($pending ?: 1);
        $isPending = fn ($q, $col) => $q->where(fn ($w) => $w->where($col, $pending)->orWhereNull($col));

        // Sales
        $s = DB::table('t_sales_invoice_master as M')
            ->leftJoin('m_vendor_customer as C', 'C.id', '=', 'M.customer_id')
            ->where('M.added_by', $addedBy)
            ->where(fn ($w) => $w->where('M.invoice_status_id', '<>', 5)->orWhereNull('M.invoice_status_id'));
        $isPending($s, 'M.audit_status_id');
        $out['sales'] = $this->pack($s, 'C.name');

        // Purchases / Expenses (same table, split by trans_type)
        foreach (['purchases' => 'Purchases', 'expenses' => 'Expenses'] as $key => $type) {
            $q = DB::table('t_purchase_expense_master as M')
                ->leftJoin('m_vendor_customer as C', 'C.id', '=', 'M.vendor_id')
                ->where('M.added_by', $addedBy)
                ->whereRaw('LOWER(M.trans_type) = ?', [strtolower($type)])
                ->where(fn ($w) => $w->where('M.invoice_status_id', '<>', 5)->orWhereNull('M.invoice_status_id'));
            if (Schema::hasColumn('t_purchase_expense_master', 'record_status')) {
                $q->where(fn ($w) => $w->whereNull('M.record_status')->orWhere('M.record_status', '<>', 'invalid'));
            }
            $isPending($q, 'M.audit_status_id');
            $out[$key] = $this->pack($q, 'C.name');
        }
        return response()->json($out);
    }

    private function pack($q, string $nameCol): array
    {
        return [
            'count' => (clone $q)->count(),
            'rows'  => $q->orderByDesc('M.transaction_date')->orderByDesc('M.id')->limit(self::LIMIT)
                ->get(['M.id', 'M.invoice_no', 'M.transaction_date', 'M.grand_total', "{$nameCol} as name"])
                ->map(fn ($r) => [
                    'id'         => $r->id,
                    'invoice_no' => $r->invoice_no,
                    'date'       => $r->transaction_date ? substr((string) $r->transaction_date, 0, 10) : null,
                    'name'       => $r->name,
                    'amount'     => round((float) $r->grand_total, 2),
                ]),
        ];
    }
}