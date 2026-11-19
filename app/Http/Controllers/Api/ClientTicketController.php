<?php

namespace App\Http\Controllers\Api;

use App\Models\RequestTicket;
use App\Models\UserClient;
use App\Models\UserClientStaff;
use App\Models\UserStaff;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Admin → Ticket (FEMS side): the same Ticket features as Administration → Ticket,
 * but only the tickets of the CURRENT client account (the branch selected at the top).
 *
 *   GET  /client-tickets                          list (tabs, search, paging, counts)
 *   GET  /client-tickets/{ticketNo}               one ticket
 *   GET  /client-tickets/{ticketNo}/attachment
 *   POST /client-tickets/{ticketNo}/approve
 *   POST /client-tickets/{ticketNo}/decline
 *
 * It reuses AdministrationController as is; every RequestTicket query of this request is limited to
 * tickets requested by the logins of this client account (request_by = m_user_client.id of that caid).
 * A ticket of another client → 404.
 */
class ClientTicketController extends AdministrationController
{
    /** the client account picked at the top (token "caid:N" for staff · own caid for client logins) */
    private function currentCaid(Request $request): ?int
    {
        $u = $request->user();
        if ($u instanceof UserClient || $u instanceof UserClientStaff) return (int) $u->caid ?: null;
        if ($u instanceof UserStaff) {
            foreach ($u->currentAccessToken()->abilities ?? [] as $a) {
                if (str_starts_with($a, 'caid:')) return (int) substr($a, 5) ?: null;
            }
        }
        return null;
    }

    /** limit every ticket query of this request to the current client */
    private function onlyThisClient(Request $request): void
    {
        $caid = $this->currentCaid($request);
        abort_unless($caid, 422, 'Select a client account first.');

        $logins = DB::table('m_user_client')->where('caid', $caid)->pluck('id')->map(fn ($v) => (int) $v)->all();

        RequestTicket::addGlobalScope('current-client', function (Builder $q) use ($logins) {
            $q->whereIn($q->getModel()->getTable() . '.request_by', $logins ?: [0]);
        });
    }

    public function ticketList(Request $request)
    {
        $this->onlyThisClient($request);
        return parent::ticketList($request);
    }

    public function ticketShow(Request $request, string $ticketNo)
    {
        $this->onlyThisClient($request);
        return parent::ticketShow($request, $ticketNo);
    }

    public function ticketAttachment(Request $request, string $ticketNo)
    {
        $this->onlyThisClient($request);
        return parent::ticketAttachment($request, $ticketNo);
    }

    public function ticketApprove(Request $request, string $ticketNo)
    {
        $this->onlyThisClient($request);
        return parent::ticketApprove($request, $ticketNo);
    }

    public function ticketDecline(Request $request, string $ticketNo)
    {
        $this->onlyThisClient($request);
        return parent::ticketDecline($request, $ticketNo);
    }
}