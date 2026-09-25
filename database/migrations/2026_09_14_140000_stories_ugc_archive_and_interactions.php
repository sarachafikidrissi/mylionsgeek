<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stories', function (Blueprint $table) {
            if (!Schema::hasColumn('stories', 'is_hidden')) {
                $table->boolean('is_hidden')->default(false)->after('expires_at');
                $table->index('is_hidden');
            }
            if (!Schema::hasColumn('stories', 'bg_color')) {
                $table->string('bg_color', 16)->nullable()->after('audience');
            }
        });

        Schema::create('story_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('story_id')->constrained('stories')->cascadeOnDelete();
            $table->foreignId('reporter_id')->constrained('users')->cascadeOnDelete();
            $table->text('reason')->nullable();
            $table->string('status', 20)->default('pending');
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->unique(['story_id', 'reporter_id']);
            $table->index(['status', 'created_at']);
        });

        Schema::create('story_report_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('notified_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('story_report_id')->constrained('story_reports')->cascadeOnDelete();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->index(['notified_user_id', 'read_at']);
        });

        Schema::create('story_interactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('story_id')->constrained('stories')->cascadeOnDelete();
            $table->string('overlay_id', 64);
            $table->string('type', 24);
            $table->json('payload')->nullable();
            $table->timestamps();

            $table->unique(['story_id', 'overlay_id']);
            $table->index(['story_id', 'type']);
        });

        Schema::create('story_interaction_responses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('story_interaction_id')->constrained('story_interactions')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->json('payload')->nullable();
            $table->timestamps();

            $table->unique(['story_interaction_id', 'user_id'], 'story_interact_resp_unique');
        });

        Schema::create('story_hashtags', function (Blueprint $table) {
            $table->id();
            $table->foreignId('story_id')->constrained('stories')->cascadeOnDelete();
            $table->string('tag', 64);
            $table->timestamps();

            $table->unique(['story_id', 'tag']);
            $table->index('tag');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('story_hashtags');
        Schema::dropIfExists('story_interaction_responses');
        Schema::dropIfExists('story_interactions');
        Schema::dropIfExists('story_report_notifications');
        Schema::dropIfExists('story_reports');

        Schema::table('stories', function (Blueprint $table) {
            if (Schema::hasColumn('stories', 'is_hidden')) {
                $table->dropIndex(['is_hidden']);
                $table->dropColumn('is_hidden');
            }
            if (Schema::hasColumn('stories', 'bg_color')) {
                $table->dropColumn('bg_color');
            }
        });
    }
};
