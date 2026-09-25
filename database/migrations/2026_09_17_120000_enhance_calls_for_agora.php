<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('calls')) {
            return;
        }

        Schema::table('calls', function (Blueprint $table) {
            if (! Schema::hasColumn('calls', 'type')) {
                $table->string('type', 16)->default('audio')->after('channel_name');
            }
            if (! Schema::hasColumn('calls', 'duration')) {
                $table->unsignedInteger('duration')->nullable()->after('ended_at');
            }
            if (! Schema::hasColumn('calls', 'answered_at')) {
                $table->timestamp('answered_at')->nullable()->after('started_at');
            }
        });

        // Normalize legacy statuses toward the production vocabulary.
        DB::table('calls')->where('status', 'pending')->update(['status' => 'ringing']);
        DB::table('calls')->where('status', 'ongoing')->update([
            'status' => 'accepted',
            'answered_at' => DB::raw('COALESCE(answered_at, started_at)'),
        ]);

        Schema::table('calls', function (Blueprint $table) {
            $table->index(['caller_id', 'status']);
            $table->index(['callee_id', 'status']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('calls')) {
            return;
        }

        DB::table('calls')->where('status', 'ringing')->update(['status' => 'pending']);
        DB::table('calls')->whereIn('status', ['accepted', 'rejected', 'cancelled'])->update(['status' => 'ended']);

        Schema::table('calls', function (Blueprint $table) {
            if (Schema::hasColumn('calls', 'type')) {
                $table->dropColumn('type');
            }
            if (Schema::hasColumn('calls', 'duration')) {
                $table->dropColumn('duration');
            }
            if (Schema::hasColumn('calls', 'answered_at')) {
                $table->dropColumn('answered_at');
            }
        });
    }
};
