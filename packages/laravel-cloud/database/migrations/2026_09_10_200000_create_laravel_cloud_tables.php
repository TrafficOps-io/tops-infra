<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('laravel_cloud_domains', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('laravel_cloud_id');
            $table->string('environment_id');
            $table->string('attachable_type')->nullable();
            $table->string('attachable_id')->nullable();
            $table->string('name', 255);
            $table->string('remote_type')->nullable();
            $table->boolean('wildcard_enabled')->default(false);
            $table->string('www_redirect')->nullable();
            $table->boolean('allow_downtime')->nullable();
            $table->string('cloudflare_strategy')->nullable();
            $table->string('verification_method')->nullable();
            $table->string('status')->default('pending');
            $table->string('hostname_status')->nullable();
            $table->string('ssl_status')->nullable();
            $table->string('origin_status')->nullable();
            $table->json('variant_statuses')->nullable();
            $table->string('action_required')->nullable();
            $table->json('remote_payload');
            $table->timestampTz('remote_created_at')->nullable();
            $table->timestampTz('last_verified_at')->nullable();
            $table->timestampTz('last_synced_at')->nullable();
            $table->timestampTz('last_checked_at')->nullable();
            $table->string('last_error_code')->nullable();
            $table->text('last_error_message')->nullable();
            $table->timestampsTz();
            $table->unique(['environment_id', 'laravel_cloud_id'], 'lc_domains_environment_remote_unique');
            $table->index(['attachable_type', 'attachable_id'], 'lc_domains_attachable_index');
            $table->index(['environment_id', 'status'], 'lc_domains_environment_status_index');
        });

        Schema::create('laravel_cloud_domain_records', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('domain_id')->constrained('laravel_cloud_domains')->cascadeOnDelete();
            $table->string('scope', 16);
            $table->string('purpose', 32);
            $table->unsignedInteger('position')->default(0);
            $table->string('type', 16)->nullable();
            $table->string('name', 255)->nullable();
            $table->text('value')->nullable();
            $table->json('payload')->nullable();
            $table->timestampsTz();
            $table->unique(['domain_id', 'scope', 'purpose', 'position'], 'lc_records_domain_scope_purpose_position_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('laravel_cloud_domain_records');
        Schema::dropIfExists('laravel_cloud_domains');
    }
};
