<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('team_subscriptions', function (Blueprint $table): void {
            $table->string('provider', 30)->nullable()->after('status');
            $table->string('provider_customer_id', 100)->nullable()->unique()->after('provider');
            $table->string('provider_subscription_id', 100)->nullable()->unique()->after('provider_customer_id');
            $table->string('provider_price_id', 100)->nullable()->after('provider_subscription_id');
            $table->string('provider_subscription_item_id', 100)->nullable()->after('provider_price_id');
            $table->boolean('cancel_at_period_end')->default(false)->after('provider_subscription_item_id');
        });
    }

    public function down(): void
    {
        Schema::table('team_subscriptions', function (Blueprint $table): void {
            $table->dropUnique('team_subscriptions_provider_customer_id_unique');
            $table->dropUnique('team_subscriptions_provider_subscription_id_unique');
            $table->dropColumn([
                'provider',
                'provider_customer_id',
                'provider_subscription_id',
                'provider_price_id',
                'provider_subscription_item_id',
                'cancel_at_period_end',
            ]);
        });
    }
};
