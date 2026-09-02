<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateOrphanedBackupsTable extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('orphaned_backups', function (Blueprint $table) {
            $table->id();
            $table->char('backup_uuid', 36);
            $table->char('server_uuid', 36);
            $table->string('server_name');
            $table->unsignedInteger('node_id')->nullable();
            $table->string('disk');
            $table->string('name');
            $table->unsignedBigInteger('bytes')->default(0);
            $table->string('borg_repository')->nullable();
            $table->timestamp('backup_created_at');
            $table->timestamp('orphaned_at');

            $table->unique('backup_uuid');
            // The node itself can be deleted later; this row is the only remaining
            // record that the backup's stored data still exists, so losing the node
            // reference must never take the row down with it.
            $table->foreign('node_id')->references('id')->on('nodes')->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('orphaned_backups');
    }
}
