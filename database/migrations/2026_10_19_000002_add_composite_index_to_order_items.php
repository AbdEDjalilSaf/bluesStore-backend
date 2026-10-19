<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The items relationship is frequently loaded with orders.
     * A composite (order_id, id) index optimizes the relationship query.
     */
    public function up(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->index(['order_id', 'id'], 'order_items_order_id_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropIndex('order_items_order_id_index');
        });
    }
};
