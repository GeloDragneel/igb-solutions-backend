<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Purchase Order → Force Close log: one row per PO line at the moment the PO was force closed
 *   ordered_qty (PO qty) · received_qty (Receive Inventory) · closed_qty (what will not be received)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('t_porder_close_log', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('po_master_id')->index();
            $t->string('po_number', 50)->index();
            $t->unsignedBigInteger('po_detail_id')->nullable();
            $t->unsignedBigInteger('item_id')->nullable();
            $t->string('item_code', 100)->nullable();
            $t->string('item_description', 255)->nullable();
            $t->decimal('ordered_qty', 18, 4)->default(0);
            $t->decimal('received_qty', 18, 4)->default(0);
            $t->decimal('closed_qty', 18, 4)->default(0);
            $t->unsignedInteger('old_status_id')->nullable();
            $t->unsignedInteger('new_status_id')->nullable();
            $t->unsignedBigInteger('closed_by')->nullable();
            $t->string('closed_by_name', 150)->nullable();
            $t->unsignedBigInteger('added_by')->nullable()->index();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('t_porder_close_log');
    }
};