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
        Schema::table('ai_systems', function (Blueprint $table) {
            $table->json('credentials')->nullable()->after('config');
            $table->string('auth_type', 50)->nullable()->after('credentials');
            $table->string('endpoint_type', 50)->nullable()->after('auth_type');
            $table->string('stream_protocol', 50)->nullable()->after('endpoint_type');
            $table->string('system_prompt_mode', 50)->nullable()->after('stream_protocol');
            $table->boolean('supports_tools')->default(false)->after('system_prompt_mode');
            $table->boolean('supports_json_mode')->default(false)->after('supports_tools');
            $table->boolean('is_local_endpoint')->default(false)->after('supports_json_mode');
            $table->json('pricing_profile')->nullable()->after('is_local_endpoint');
        });

        Schema::table('ai_interaction_logs', function (Blueprint $table) {
            $table->decimal('input_token_price_snapshot', 12, 8)->nullable()->after('model');
            $table->decimal('output_token_price_snapshot', 12, 8)->nullable()->after('input_token_price_snapshot');
            $table->json('provider_metadata')->nullable()->after('output_token_price_snapshot');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ai_systems', function (Blueprint $table) {
            $table->dropColumn([
                'credentials',
                'auth_type',
                'endpoint_type',
                'stream_protocol',
                'system_prompt_mode',
                'supports_tools',
                'supports_json_mode',
                'is_local_endpoint',
                'pricing_profile',
            ]);
        });

        Schema::table('ai_interaction_logs', function (Blueprint $table) {
            $table->dropColumn([
                'input_token_price_snapshot',
                'output_token_price_snapshot',
                'provider_metadata',
            ]);
        });
    }
};
