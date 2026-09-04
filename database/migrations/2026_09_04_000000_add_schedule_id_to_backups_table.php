<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class AddScheduleIdToBackupsTable extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('backups', function (Blueprint $table) {
            // Records which schedule triggered this backup, if any, so the completion
            // callback from Wings can find the healthchecks.io check to report to. Set
            // null rather than cascaded on schedule deletion: removing a schedule must
            // never delete a server's backups.
            $table->unsignedInteger('schedule_id')->nullable()->after('server_id');

            $table->foreign('schedule_id')->references('id')->on('schedules')->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('backups', function (Blueprint $table) {
            $table->dropForeign(['schedule_id']);
            $table->dropColumn('schedule_id');
        });
    }
}
