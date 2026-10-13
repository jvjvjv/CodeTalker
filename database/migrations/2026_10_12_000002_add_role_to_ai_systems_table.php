<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A string rather than a database enum, like `provider`: the package runs
     * on several database drivers, and a future role would otherwise be a
     * schema change on every host. The default covers existing rows.
     */
    public function up(): void
    {
        Schema::table('ai_systems', function (Blueprint $table) {
            $table->string('role', 50)->default('chat')->after('provider')->index();
        });
    }

    public function down(): void
    {
        Schema::table('ai_systems', function (Blueprint $table) {
            $table->dropIndex(['role']);
            $table->dropColumn('role');
        });
    }
};
