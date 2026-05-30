<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('entities', function (Blueprint $table) {
            $table->string('normalized_key')->nullable()->after('canonical_label');
            $table->uuid('merged_into_id')->nullable()->after('normalized_key');

            $table->foreign('merged_into_id')->references('id')->on('entities')->nullOnDelete();
            $table->index(['user_id', 'type', 'normalized_key']);
            $table->index('merged_into_id');
        });

        Schema::create('entity_aliases', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('entity_id');
            $table->string('alias');
            $table->string('normalized_alias');
            $table->string('source')->default('extraction');
            $table->timestamps();

            $table->foreign('entity_id')->references('id')->on('entities')->cascadeOnDelete();
            $table->unique(['entity_id', 'normalized_alias']);
            $table->index('normalized_alias');
        });

        Schema::create('entity_merges', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->uuid('canonical_entity_id');
            $table->uuid('merged_entity_id');
            $table->string('reason');
            $table->decimal('confidence', 5, 4)->nullable();
            $table->string('method');
            $table->timestamps();

            $table->foreign('canonical_entity_id')->references('id')->on('entities')->cascadeOnDelete();
            $table->foreign('merged_entity_id')->references('id')->on('entities')->cascadeOnDelete();
            $table->index(['user_id', 'canonical_entity_id']);
        });

        Schema::create('entity_merge_candidates', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->uuid('entity_a_id');
            $table->uuid('entity_b_id');
            $table->decimal('similarity', 5, 4);
            $table->string('method');
            $table->string('status')->default('pending');
            $table->timestamps();

            $table->foreign('entity_a_id')->references('id')->on('entities')->cascadeOnDelete();
            $table->foreign('entity_b_id')->references('id')->on('entities')->cascadeOnDelete();
            $table->unique(['entity_a_id', 'entity_b_id']);
            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('entity_merge_candidates');
        Schema::dropIfExists('entity_merges');
        Schema::dropIfExists('entity_aliases');
        Schema::table('entities', function (Blueprint $table) {
            $table->dropForeign(['merged_into_id']);
            $table->dropIndex(['user_id', 'type', 'normalized_key']);
            $table->dropIndex(['merged_into_id']);
            $table->dropColumn(['normalized_key', 'merged_into_id']);
        });
    }
};
