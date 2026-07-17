<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * UUID yang mobile generate sekali per submission — dipakai buat ngenalin
 * retry (sinyal putus pas nunggu respons, padahal request-nya udah sampe)
 * biar `POST /calibrations` nggak bikin sesi dobel. Lihat
 * CalibrationController::store().
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('calibration_sessions', function (Blueprint $table) {
            $table->uuid('client_request_id')->nullable()->after('teknisi_id');
            $table->unique(['organization_id', 'client_request_id']);
        });
    }

    public function down(): void
    {
        Schema::table('calibration_sessions', function (Blueprint $table) {
            $table->dropUnique(['organization_id', 'client_request_id']);
            $table->dropColumn('client_request_id');
        });
    }
};
