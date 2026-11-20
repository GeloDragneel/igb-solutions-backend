<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Purchase Order: Shipping Terms · Payment Terms · Ship To · Delivery Method
 * one list per client (client_id = m_user_client.id, same as t_porder_master.added_by)
 * + the picked id of each on t_porder_master
 */
return new class extends Migration
{
    private const TABLES = ['m_shipping_terms', 'm_payment_terms', 'm_ship_to', 'm_delivery_methods'];
    private const COLS = ['shipping_term_id', 'payment_term_id', 'ship_to_id', 'delivery_method_id'];

    public function up(): void
    {
        foreach (self::TABLES as $t) {
            if (Schema::hasTable($t)) continue;
            Schema::create($t, function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('client_id')->index();
                $table->string('name', 150);
                $table->timestamps();
                $table->unique(['client_id', 'name']);
            });
        }

        Schema::table('t_porder_master', function (Blueprint $table) {
            foreach (self::COLS as $c) {
                if (!Schema::hasColumn('t_porder_master', $c)) $table->unsignedBigInteger($c)->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('t_porder_master', function (Blueprint $table) {
            foreach (self::COLS as $c) {
                if (Schema::hasColumn('t_porder_master', $c)) $table->dropColumn($c);
            }
        });
        foreach (self::TABLES as $t) Schema::dropIfExists($t);
    }
};