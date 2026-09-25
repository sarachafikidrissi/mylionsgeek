<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('calls') || Schema::hasColumn('calls', 'voip_uuid')) {
            return;
        }

        Schema::table('calls', function (Blueprint $table) {
            $table->string('voip_uuid', 64)->nullable()->after('channel_name');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('calls') || ! Schema::hasColumn('calls', 'voip_uuid')) {
            return;
        }

        Schema::table('calls', function (Blueprint $table) {
            $table->dropColumn('voip_uuid');
        });
    }
};
