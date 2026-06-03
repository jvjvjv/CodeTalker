<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('ai_conversations', function (Blueprint $table) {
            $table->unsignedInteger('usage_input_tokens')->nullable()->after('context');
            $table->unsignedInteger('usage_output_tokens')->nullable()->after('usage_input_tokens');
            $table->unsignedInteger('usage_total_tokens')->nullable()->after('usage_output_tokens');
            $table->decimal('usage_cost_usd', 12, 6)->nullable()->after('usage_total_tokens');
            $table->timestamp('usage_synced_at')->nullable()->after('usage_cost_usd');

            $table->index('usage_synced_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ai_conversations', function (Blueprint $table) {
            $table->dropIndex(['usage_synced_at']);
            $table->dropColumn([
                'usage_input_tokens',
                'usage_output_tokens',
                'usage_total_tokens',
                'usage_cost_usd',
                'usage_synced_at',
            ]);
        });
    }
};
