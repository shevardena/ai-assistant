<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::table('customers')->orderBy('id')->chunkById(100, function ($customers): void {
            foreach ($customers as $customer) {
                $identities = [];
                $email = is_string($customer->email) ? strtolower(trim($customer->email)) : '';
                $phone = is_string($customer->phone) ? preg_replace('/\D+/', '', trim($customer->phone)) : '';

                if ($email !== '') {
                    $identities[] = [
                        'team_id' => $customer->team_id,
                        'customer_id' => $customer->id,
                        'type' => 'email',
                        'value' => $customer->email,
                        'normalized_value' => $email,
                        'is_primary' => true,
                        'is_verified' => false,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                }

                if ($phone !== '') {
                    $identities[] = [
                        'team_id' => $customer->team_id,
                        'customer_id' => $customer->id,
                        'type' => 'phone',
                        'value' => $customer->phone,
                        'normalized_value' => $phone,
                        'is_primary' => true,
                        'is_verified' => false,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                }

                if ($identities !== []) {
                    DB::table('customer_identities')->insertOrIgnore($identities);
                }
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('customer_identities')->whereIn('type', ['email', 'phone'])->delete();
    }
};
