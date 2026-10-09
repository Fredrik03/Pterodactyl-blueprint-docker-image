<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The extension creates this table on first use if the migration has not
        // run yet (Blueprint skips migrations inside Docker), so guard against it.
        if (Schema::hasTable('modmanager_installs')) {
            return;
        }

        Schema::create('modmanager_installs', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('server_id');
            $table->string('provider', 16);
            $table->string('project_id', 64);
            $table->string('project_slug', 191)->nullable();
            $table->string('project_name', 191);
            $table->string('version_id', 64);
            $table->string('version_name', 191)->nullable();
            $table->string('version_number', 191)->nullable();
            $table->string('filename', 191);
            $table->string('loader', 16);
            $table->string('game_version', 32)->nullable();
            $table->text('icon_url')->nullable();
            $table->string('sha1', 64)->nullable();
            $table->unsignedBigInteger('size')->nullable();
            $table->unsignedInteger('installed_by')->nullable();
            $table->timestamps();

            $table->unique(['server_id', 'filename']);
            $table->index(['server_id', 'provider', 'project_id']);
            $table->foreign('server_id')->references('id')->on('servers')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('modmanager_installs');
    }
};
