<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('data_source_credentials', function (Blueprint $table) {
            $table->id();

            $table->foreignId('data_source_id')
                ->constrained()
                ->cascadeOnDelete();

            // api_key, bearer_token, username, password...
            $table->string('key');

            // Store encrypted value here.
            $table->longText('encrypted_value');

            $table->timestamps();

            $table->unique([
                'data_source_id',
                'key',
            ]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('data_source_credentials');
    }
};
