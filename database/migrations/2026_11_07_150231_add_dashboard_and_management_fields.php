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
        Schema::table('students', function (Blueprint $table) {
            $table->foreignId('university_id')->nullable()->after('user_id')->constrained()->nullOnDelete();
            $table->foreignId('course_id')->nullable()->after('university_id')->constrained()->nullOnDelete();
            $table->string('registration_number', 64)->nullable()->unique();
            $table->unsignedTinyInteger('year_of_study')->nullable();
            $table->unsignedSmallInteger('graduation_year')->nullable();
        });

        Schema::table('organizations', function (Blueprint $table) {
            $table->string('email')->nullable();
            $table->string('phone', 32)->nullable();
            $table->string('website')->nullable();
            $table->text('description')->nullable();
            $table->string('address')->nullable();
            $table->string('city')->nullable();
            $table->string('region')->nullable();
        });

        Schema::table('opportunities', function (Blueprint $table) {
            $table->string('status', 20)->default('draft');
            $table->text('responsibilities')->nullable();
            $table->string('city')->nullable();
            $table->string('region')->nullable();
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->index(['organization_id', 'status']);
        });

        DB::table('opportunities')->where('is_active', true)->update(['status' => 'published']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->dropForeign(['university_id']);
            $table->dropForeign(['course_id']);
            $table->dropUnique(['registration_number']);
            $table->dropColumn([
                'university_id',
                'course_id',
                'registration_number',
                'year_of_study',
                'graduation_year',
            ]);
        });

        Schema::table('organizations', function (Blueprint $table) {
            $table->dropColumn(['email', 'phone', 'website', 'description', 'address', 'city', 'region']);
        });

        Schema::table('opportunities', function (Blueprint $table) {
            $table->dropIndex(['organization_id', 'status']);
            $table->dropColumn(['status', 'responsibilities', 'city', 'region', 'start_date', 'end_date']);
        });
    }
};
