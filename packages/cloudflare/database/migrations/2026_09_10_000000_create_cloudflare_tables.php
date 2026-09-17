<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cloudflare_integrations', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('owner_type');
            $table->string('owner_id');
            $table->string('label')->nullable();
            $table->text('api_token');
            $table->char('token_fingerprint', 64);
            $table->string('token_id', 32)->nullable();
            $table->string('token_status')->default('unknown');
            $table->string('status')->default('pending');
            $table->timestampTz('token_expires_at')->nullable();
            $table->timestampTz('last_verified_at')->nullable();
            $table->timestampTz('last_synced_at')->nullable();
            $table->string('last_error_code')->nullable();
            $table->text('last_error_message')->nullable();
            $table->timestampsTz();
            $table->index(['owner_type', 'owner_id'], 'cf_integrations_owner_index');
            $table->unique(['owner_type', 'owner_id', 'token_fingerprint'], 'cf_integrations_owner_token_unique');
        });

        Schema::create('cloudflare_accounts', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('integration_id')->constrained('cloudflare_integrations')->cascadeOnDelete();
            $table->string('cloudflare_id', 32);
            $table->string('name');
            $table->string('status')->default('active');
            $table->timestampTz('last_synced_at')->nullable();
            $table->timestampsTz();
            $table->unique(['integration_id', 'cloudflare_id'], 'cf_accounts_integration_remote_unique');
        });

        Schema::create('cloudflare_zones', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('account_id')->constrained('cloudflare_accounts')->cascadeOnDelete();
            $table->string('cloudflare_id', 32);
            $table->string('name', 253);
            $table->string('status')->default('active');
            $table->string('type')->nullable();
            $table->boolean('paused')->default(false);
            $table->json('name_servers')->nullable();
            $table->timestampTz('last_synced_at')->nullable();
            $table->timestampsTz();
            $table->unique(['account_id', 'cloudflare_id'], 'cf_zones_account_remote_unique');
            $table->index('name');
        });

        Schema::create('cloudflare_domains', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('zone_id')->constrained('cloudflare_zones')->cascadeOnDelete();
            $table->string('hostname', 255)->unique();
            $table->string('kind');
            $table->string('status')->default('pending');
            $table->timestampTz('last_checked_at')->nullable();
            $table->string('last_error_code')->nullable();
            $table->text('last_error_message')->nullable();
            $table->timestampsTz();
        });

        Schema::create('cloudflare_domain_records', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('domain_id')->constrained('cloudflare_domains')->cascadeOnDelete();
            $table->string('cloudflare_record_id', 32)->nullable();
            $table->char('signature', 64);
            $table->string('type', 8);
            $table->string('name', 255);
            $table->text('content');
            $table->unsignedInteger('ttl')->default(1);
            $table->boolean('proxied')->nullable();
            $table->boolean('desired')->default(true);
            $table->string('ownership')->nullable();
            $table->string('control_status')->default('pending');
            $table->string('public_status')->default('pending');
            $table->timestampTz('last_checked_at')->nullable();
            $table->text('last_error_message')->nullable();
            $table->timestampsTz();
            $table->unique(['domain_id', 'signature'], 'cf_records_domain_signature_unique');
            $table->index(['type', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cloudflare_domain_records');
        Schema::dropIfExists('cloudflare_domains');
        Schema::dropIfExists('cloudflare_zones');
        Schema::dropIfExists('cloudflare_accounts');
        Schema::dropIfExists('cloudflare_integrations');
    }
};
