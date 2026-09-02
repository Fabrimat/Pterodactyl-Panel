<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class AddBorgRepositoryToBackupsTable extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('backups', function (Blueprint $table) {
            // Path of this backup's borg repository, relative to backups.disks.borg.repository.
            // Recorded once, at creation, so a later change to the repository mode does not
            // move where an already existing backup resolves to. NULL means the legacy
            // per-server layout, either because the row predates this column or because it
            // was written while the mode was incremental - both cases resolve the same way,
            // straight to the server UUID underneath the configured base.
            $table->string('borg_repository')->nullable()->after('disk');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('backups', function (Blueprint $table) {
            $table->dropColumn('borg_repository');
        });
    }
}
