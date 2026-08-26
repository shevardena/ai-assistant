<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('customer_identities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->string('type', 30);
            $table->string('value', 320);
            $table->string('normalized_value', 320);
            $table->string('provider', 50)->nullable();
            $table->string('provider_external_id', 255)->nullable();
            $table->boolean('is_primary')->default(false);
            $table->boolean('is_verified')->default(false);
            $table->timestamps();
            $table->index(['customer_id', 'type']);
            $table->index(['team_id', 'normalized_value']);
        });
        DB::statement("CREATE UNIQUE INDEX customer_identity_email_phone_unique ON customer_identities (team_id, type, normalized_value) WHERE type IN ('email', 'phone')");
        DB::statement("CREATE UNIQUE INDEX customer_identity_channel_unique ON customer_identities (team_id, type, provider, normalized_value) WHERE type = 'channel_user'");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS customer_identity_email_phone_unique');
        DB::statement('DROP INDEX IF EXISTS customer_identity_channel_unique');
        Schema::dropIfExists('customer_identities');
    }
};
