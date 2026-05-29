<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('interview_sessions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->string('session_type');
            $table->string('mode')->default('ai_interview');
            $table->string('status')->default('active');
            $table->text('summary')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'status']);
        });

        Schema::create('psychologist_sessions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('title')->default('Психолог ИИ');
            $table->string('status')->default('active');
            $table->text('summary')->nullable();
            $table->timestamps();
        });

        Schema::create('messages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->uuid('interview_session_id')->nullable();
            $table->uuid('psychologist_session_id')->nullable();
            $table->string('role');
            $table->longText('content');
            $table->json('reasoning_metadata')->nullable();
            $table->string('processing_status')->default('pending');
            $table->string('processing_key')->nullable()->unique();
            $table->timestamps();
            $table->index(['interview_session_id', 'created_at']);
            $table->index(['psychologist_session_id', 'created_at']);
            $table->foreign('interview_session_id')->references('id')->on('interview_sessions')->nullOnDelete();
            $table->foreign('psychologist_session_id')->references('id')->on('psychologist_sessions')->nullOnDelete();
        });

        Schema::create('entities', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type');
            $table->string('layer');
            $table->string('canonical_label');
            $table->timestamps();
            $table->index(['user_id', 'layer', 'type']);
        });

        Schema::create('entity_versions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('entity_id');
            $table->uuid('source_message_id')->nullable();
            $table->timestamp('valid_from')->nullable();
            $table->timestamp('valid_until')->nullable();
            $table->json('payload');
            $table->decimal('confidence', 5, 4)->default(0.5);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->foreign('entity_id')->references('id')->on('entities')->cascadeOnDelete();
            $table->foreign('source_message_id')->references('id')->on('messages')->nullOnDelete();
            $table->index(['entity_id', 'valid_from']);
        });

        Schema::create('relations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type');
            $table->uuid('source_entity_id');
            $table->uuid('target_entity_id');
            $table->timestamps();
            $table->foreign('source_entity_id')->references('id')->on('entities')->cascadeOnDelete();
            $table->foreign('target_entity_id')->references('id')->on('entities')->cascadeOnDelete();
            $table->index(['user_id', 'type']);
        });

        Schema::create('relation_versions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('relation_id');
            $table->uuid('source_message_id')->nullable();
            $table->timestamp('valid_from')->nullable();
            $table->timestamp('valid_until')->nullable();
            $table->json('payload')->nullable();
            $table->decimal('confidence', 5, 4)->default(0.5);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->foreign('relation_id')->references('id')->on('relations')->cascadeOnDelete();
            $table->foreign('source_message_id')->references('id')->on('messages')->nullOnDelete();
        });

        Schema::create('autobiographies', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->string('style');
            $table->string('scope');
            $table->json('scope_params')->nullable();
            $table->longText('content');
            $table->unsignedInteger('version')->default(1);
            $table->uuid('parent_id')->nullable();
            $table->string('status')->default('completed');
            $table->timestamps();
            $table->index(['user_id', 'version']);
        });

        Schema::create('embeddings_metadata', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('collection');
            $table->string('point_id');
            $table->string('source_type');
            $table->uuid('source_id');
            $table->string('model')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'collection', 'point_id']);
            $table->index(['source_type', 'source_id']);
        });

        Schema::create('ai_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('operation');
            $table->string('prompt_version')->nullable();
            $table->string('model')->nullable();
            $table->string('input_hash')->nullable();
            $table->longText('prompt')->nullable();
            $table->longText('response')->nullable();
            $table->unsignedInteger('tokens_in')->nullable();
            $table->unsignedInteger('tokens_out')->nullable();
            $table->decimal('cost_estimate', 10, 6)->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->string('status');
            $table->text('error')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'operation', 'created_at']);
        });

        Schema::create('jobs_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('job_class');
            $table->json('payload_summary')->nullable();
            $table->unsignedTinyInteger('attempt')->default(1);
            $table->string('status');
            $table->text('exception')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->timestamps();
            $table->index(['job_class', 'status', 'created_at']);
        });

        Schema::create('graph_projection_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('target');
            $table->string('operation');
            $table->uuid('entity_id')->nullable();
            $table->uuid('relation_id')->nullable();
            $table->string('status');
            $table->text('error')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('graph_projection_logs');
        Schema::dropIfExists('jobs_logs');
        Schema::dropIfExists('ai_logs');
        Schema::dropIfExists('embeddings_metadata');
        Schema::dropIfExists('autobiographies');
        Schema::dropIfExists('relation_versions');
        Schema::dropIfExists('relations');
        Schema::dropIfExists('entity_versions');
        Schema::dropIfExists('entities');
        Schema::dropIfExists('messages');
        Schema::dropIfExists('psychologist_sessions');
        Schema::dropIfExists('interview_sessions');
    }
};
