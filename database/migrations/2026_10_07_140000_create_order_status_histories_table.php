<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('order_status_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->string('status')->index();
            $table->timestamp('changed_at');
        });

        // Seed a starting entry for orders placed before the timeline existed.
        foreach (DB::table('orders')->orderBy('id')->get() as $order) {
            DB::table('order_status_histories')->insert([
                'order_id' => $order->id,
                'status' => $order->status,
                'changed_at' => $order->created_at,
            ]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('order_status_histories');
    }
};
