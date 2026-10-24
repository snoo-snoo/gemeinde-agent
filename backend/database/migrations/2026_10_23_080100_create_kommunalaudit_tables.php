<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('municipalities', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('name');
            $table->string('official_name');
            $table->string('bundesland', 64);
            $table->string('district')->nullable();
            $table->string('gemeindekennziffer', 16)->nullable();
            $table->string('postal_code', 16)->nullable();
            $table->string('city')->nullable();
            $table->string('website_url')->nullable();
            $table->text('notes')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('sources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('municipality_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->text('url');
            $table->string('source_type', 64);
            $table->json('allowed_hosts');
            $table->unsignedInteger('crawl_delay_seconds')->nullable();
            $table->boolean('respect_robots')->default(true);
            $table->unsignedInteger('max_documents')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamp('last_crawled_at')->nullable();
            $table->string('last_status')->nullable();
            $table->json('rules')->nullable();
            $table->timestamps();
        });

        Schema::create('ai_providers', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('driver', 64);
            $table->text('base_url')->nullable();
            $table->boolean('enabled')->default(false);
            $table->boolean('is_fallback')->default(false);
            $table->unsignedInteger('requests_per_minute')->nullable();
            $table->json('settings')->nullable();
            $table->timestamps();
        });

        Schema::create('ai_credentials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('provider_id')->constrained('ai_providers')->cascadeOnDelete();
            $table->string('label');
            $table->text('secret');
            $table->string('hint', 16);
            $table->string('status', 32)->default('active');
            $table->timestamp('last_tested_at')->nullable();
            $table->string('last_test_status')->nullable();
            $table->timestamp('rotated_at')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('ai_model_configs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('provider_id')->constrained('ai_providers')->cascadeOnDelete();
            $table->string('model_id');
            $table->string('display_name');
            $table->boolean('supports_json_mode')->default(false);
            $table->boolean('supports_tools')->default(false);
            $table->decimal('temperature', 4, 2)->nullable();
            $table->unsignedInteger('max_output_tokens')->nullable();
            $table->decimal('price_input_per_million', 12, 4)->nullable();
            $table->decimal('price_output_per_million', 12, 4)->nullable();
            $table->string('price_source', 32)->default('unknown');
            $table->boolean('enabled')->default(true);
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->unique(['provider_id', 'model_id']);
        });

        Schema::create('agent_configs', function (Blueprint $table) {
            $table->id();
            $table->string('agent_key')->unique();
            $table->string('name');
            $table->text('task');
            $table->foreignId('provider_id')->nullable()->constrained('ai_providers')->nullOnDelete();
            $table->foreignId('model_config_id')->nullable()->constrained('ai_model_configs')->nullOnDelete();
            $table->foreignId('credential_id')->nullable()->constrained('ai_credentials')->nullOnDelete();
            $table->foreignId('fallback_provider_id')->nullable()->constrained('ai_providers')->nullOnDelete();
            $table->text('system_prompt');
            $table->unsignedInteger('prompt_version')->default(1);
            $table->json('parameters')->nullable();
            $table->json('output_schema')->nullable();
            $table->unsignedInteger('timeout_seconds')->default(60);
            $table->unsignedInteger('max_retries')->default(1);
            $table->unsignedInteger('token_budget')->nullable();
            $table->unsignedInteger('cost_budget_cents')->nullable();
            $table->boolean('enabled')->default(true);
            $table->string('implementation', 32);
            $table->boolean('allows_llm')->default(false);
            $table->json('dependencies')->nullable();
            $table->json('quality_rules')->nullable();
            $table->timestamps();
        });

        Schema::create('analysis_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('municipality_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 32)->default('pending');
            $table->string('workflow_state', 64)->default('registered');
            $table->string('trigger', 32)->default('manual');
            $table->boolean('cancel_requested')->default(false);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->text('error')->nullable();
            $table->json('summary')->nullable();
            $table->unsignedInteger('token_budget')->nullable();
            $table->unsignedInteger('cost_budget_cents')->nullable();
            $table->string('idempotency_key')->nullable()->unique();
            $table->timestamps();
        });

        Schema::create('crawl_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('source_id')->constrained()->cascadeOnDelete();
            $table->foreignId('analysis_run_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 32);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->unsignedInteger('documents_seen')->default(0);
            $table->unsignedInteger('documents_downloaded')->default(0);
            $table->text('error')->nullable();
            $table->json('stats')->nullable();
            $table->timestamps();
        });

        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('municipality_id')->constrained()->cascadeOnDelete();
            $table->foreignId('source_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title');
            $table->string('document_type', 64);
            $table->unsignedSmallInteger('fiscal_year')->nullable();
            $table->unsignedTinyInteger('supplement_ordinal')->nullable();
            $table->string('status', 32)->default('discovered');
            $table->text('canonical_url');
            $table->string('external_id')->nullable();
            $table->timestamps();
            $table->unique(['municipality_id', 'canonical_url']);
        });

        Schema::create('document_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version_number');
            $table->char('sha256', 64);
            $table->string('mime', 128)->nullable();
            $table->unsignedBigInteger('byte_size');
            $table->string('storage_disk', 32)->default('documents');
            $table->string('storage_path');
            $table->text('source_url');
            $table->timestamp('fetched_at')->nullable();
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->string('text_path')->nullable();
            $table->unsignedInteger('page_count')->nullable();
            $table->timestamps();
            $table->unique(['document_id', 'sha256']);
        });

        Schema::create('extraction_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_version_id')->constrained()->cascadeOnDelete();
            $table->foreignId('analysis_run_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 32);
            $table->string('method')->nullable();
            $table->decimal('confidence', 8, 4)->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->text('error')->nullable();
            $table->json('stats')->nullable();
            $table->timestamps();
        });

        Schema::create('financial_periods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('municipality_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('fiscal_year');
            $table->string('label');
            $table->timestamps();
            $table->unique(['municipality_id', 'fiscal_year']);
        });

        Schema::create('financial_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('code', 32);
            $table->string('name');
            $table->string('statement', 32);
            $table->unsignedTinyInteger('level')->nullable();
            $table->string('vrv_version', 16)->default('2015');
            $table->timestamps();
            $table->unique(['code', 'statement', 'vrv_version']);
        });

        Schema::create('budget_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_version_id')->constrained()->cascadeOnDelete();
            $table->foreignId('extraction_run_id')->nullable()->constrained()->nullOnDelete();
            $table->string('code', 32)->nullable();
            $table->text('original_label');
            $table->string('statement', 32);
            $table->string('scope', 32);
            $table->unsignedSmallInteger('fiscal_year')->nullable();
            $table->text('raw_line')->nullable();
            $table->json('amounts')->nullable();
            $table->timestamps();
        });

        Schema::create('financial_facts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('municipality_id')->constrained()->cascadeOnDelete();
            $table->foreignId('financial_period_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('financial_account_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('document_version_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('extraction_run_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('budget_line_id')->nullable()->constrained()->nullOnDelete();
            $table->string('local_key');
            $table->string('code', 32)->nullable();
            $table->text('original_label');
            $table->string('statement', 32);
            $table->string('scope', 32);
            $table->string('value_kind', 32);
            $table->string('nature', 32);
            $table->string('amount_original', 64);
            $table->decimal('amount', 18, 2);
            $table->char('currency', 3)->default('EUR');
            $table->unsignedInteger('page')->nullable();
            $table->text('excerpt')->nullable();
            $table->string('extraction_method');
            $table->decimal('confidence', 8, 4)->default(0);
            $table->json('quality_flags')->nullable();
            $table->boolean('comparable')->default(true);
            $table->timestamps();
            $table->index(['municipality_id', 'code', 'value_kind']);
            $table->index('local_key');
        });

        Schema::create('accounting_validations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('analysis_run_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('document_version_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('municipality_id')->constrained()->cascadeOnDelete();
            $table->string('rule_code', 64);
            $table->string('status', 32);
            $table->text('formula');
            $table->json('inputs');
            $table->json('result')->nullable();
            $table->string('tolerance', 32)->nullable();
            $table->text('message');
            $table->unsignedInteger('page')->nullable();
            $table->timestamps();
        });

        Schema::create('findings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('analysis_run_id')->constrained()->cascadeOnDelete();
            $table->foreignId('municipality_id')->constrained()->cascadeOnDelete();
            $table->string('rule_code', 64);
            $table->string('title');
            $table->text('summary');
            $table->json('years')->nullable();
            $table->json('positions')->nullable();
            $table->json('inputs')->nullable();
            $table->json('comparison')->nullable();
            $table->decimal('absolute_deviation', 18, 2)->nullable();
            $table->decimal('relative_deviation', 12, 4)->nullable();
            $table->text('method')->nullable();
            $table->json('possible_explanations')->nullable();
            $table->json('counterarguments')->nullable();
            $table->string('relevance', 32)->nullable();
            $table->unsignedSmallInteger('priority')->default(3);
            $table->string('data_quality')->nullable();
            $table->string('confidence', 32)->nullable();
            $table->string('review_status', 32)->default('unreviewed');
            $table->string('approval_status', 32)->default('draft');
            $table->text('review_note')->nullable();
            $table->text('financial_relevance')->nullable();
            $table->timestamps();
        });

        Schema::create('finding_evidence', function (Blueprint $table) {
            $table->id();
            $table->foreignId('finding_id')->constrained()->cascadeOnDelete();
            $table->foreignId('document_version_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('financial_fact_id')->nullable()->constrained()->nullOnDelete();
            $table->text('source_url')->nullable();
            $table->unsignedInteger('page')->nullable();
            $table->text('excerpt')->nullable();
            $table->string('role', 32)->default('supports');
            $table->timestamps();
        });

        Schema::create('questions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('municipality_id')->constrained()->cascadeOnDelete();
            $table->foreignId('analysis_run_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('finding_id')->nullable()->constrained()->nullOnDelete();
            $table->string('audience', 64);
            $table->string('topic', 64);
            $table->text('body');
            $table->string('status', 32)->default('open');
            $table->text('answer')->nullable();
            $table->timestamp('answered_at')->nullable();
            $table->unsignedSmallInteger('priority')->default(3);
            $table->timestamps();
        });

        Schema::create('reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('municipality_id')->constrained()->cascadeOnDelete();
            $table->foreignId('analysis_run_id')->nullable()->constrained()->nullOnDelete();
            $table->string('report_type', 64);
            $table->string('title');
            $table->string('status', 32)->default('draft');
            $table->unsignedInteger('version_number')->default(1);
            $table->longText('body_markdown');
            $table->date('data_as_of')->nullable();
            $table->unsignedSmallInteger('period_start')->nullable();
            $table->unsignedSmallInteger('period_end')->nullable();
            $table->text('limitations')->nullable();
            $table->json('source_index')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->json('exports')->nullable();
            $table->timestamps();
        });

        Schema::create('agent_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('analysis_run_id')->constrained()->cascadeOnDelete();
            $table->foreignId('agent_config_id')->constrained()->cascadeOnDelete();
            $table->string('status', 32);
            $table->unsignedTinyInteger('attempt')->default(1);
            $table->text('input_excerpt')->nullable();
            $table->json('output')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->unsignedInteger('input_tokens')->nullable();
            $table->unsignedInteger('output_tokens')->nullable();
            $table->unsignedInteger('cost_cents')->nullable();
            $table->boolean('cost_estimated')->default(false);
            $table->string('provider_driver')->nullable();
            $table->string('model')->nullable();
            $table->unsignedInteger('latency_ms')->nullable();
            $table->timestamps();
        });

        Schema::create('usage_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agent_run_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('analysis_run_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('provider_id')->nullable()->constrained('ai_providers')->nullOnDelete();
            $table->string('model')->nullable();
            $table->unsignedInteger('input_tokens')->nullable();
            $table->unsignedInteger('output_tokens')->nullable();
            $table->unsignedInteger('cost_cents')->nullable();
            $table->boolean('cost_estimated')->default(false);
            $table->unsignedInteger('latency_ms')->nullable();
            $table->string('status', 32);
            $table->string('error_class')->nullable();
            $table->timestamps();
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action');
            $table->string('entity_type');
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->json('metadata')->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('approvals', function (Blueprint $table) {
            $table->id();
            $table->string('entity_type', 32);
            $table->unsignedBigInteger('entity_id');
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('decision', 32);
            $table->text('comment')->nullable();
            $table->timestamps();
        });

        Schema::create('scrape_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('municipality_id')->constrained()->cascadeOnDelete();
            $table->foreignId('source_id')->nullable()->constrained()->nullOnDelete();
            $table->string('cron', 64)->default('0 4 * * *');
            $table->boolean('enabled')->default(false);
            $table->timestamp('last_ran_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach ([
            'scrape_schedules', 'approvals', 'audit_logs', 'usage_records', 'agent_runs', 'reports',
            'questions', 'finding_evidence', 'findings', 'accounting_validations', 'financial_facts',
            'budget_lines', 'financial_accounts', 'financial_periods', 'extraction_runs',
            'document_versions', 'documents', 'crawl_runs', 'analysis_runs', 'agent_configs',
            'ai_model_configs', 'ai_credentials', 'ai_providers', 'sources', 'municipalities',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
