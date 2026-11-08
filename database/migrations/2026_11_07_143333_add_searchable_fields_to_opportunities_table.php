<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('opportunities', function (Blueprint $table) {
            $table->text('requirements')->nullable();
            $table->string('industry', 120)->nullable();
            $table->unsignedTinyInteger('duration_months')->nullable();
            $table->unsignedInteger('available_slots')->nullable();
            $table->index(['is_active', 'deadline']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('opportunities', function (Blueprint $table) {
            $table->dropIndex(['is_active', 'deadline']);
            $table->dropColumn([
                'requirements',
                'industry',
                'duration_months',
                'available_slots',
            ]);
        });
    }
};
