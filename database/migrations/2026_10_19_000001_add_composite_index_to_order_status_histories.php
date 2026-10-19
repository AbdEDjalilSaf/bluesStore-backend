<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The statusHistories relationship is always ordered by changed_at and id.
     * A composite (order_id, changed_at, id) index lets the database satisfy
     * the relationship query from the index instead of sorting.
     */
    public function up(): void
    {
        Schema::table('order_status_histories', function (Blueprint $table) {
            $table->index(['order_id', 'changed_at', 'id'], 'order_status_histories_order_changed_id_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('order_status_histories', function (Blueprint $table) {
            $table->dropIndex('order_status_histories_order_changed_id_index');
        });
    }
};
