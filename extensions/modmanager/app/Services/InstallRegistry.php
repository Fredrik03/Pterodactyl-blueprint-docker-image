<?php

namespace Pterodactyl\BlueprintFramework\Extensions\modmanager\Services;

use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Pterodactyl\Models\Server;

/**
 * Tracks which mod files this extension put on a server so the installed
 * list can show project names, versions and update availability. Files that
 * users upload themselves still show up (from the directory listing) but as
 * "unmanaged" entries.
 */
class InstallRegistry
{
    public const TABLE = 'modmanager_installs';

    private const COLUMNS = [
        'provider', 'project_id', 'project_slug', 'project_name', 'version_id', 'version_name',
        'version_number', 'filename', 'loader', 'game_version', 'icon_url', 'sha1', 'size', 'installed_by',
        'created_at', 'updated_at',
    ];

    private static bool $tableChecked = false;

    /**
     * Blueprint copies the migration but does not run it when the panel runs in
     * Docker (it leaves that to the next container start). Rather than 500 until
     * then, create the table on first use. The migration itself checks for the
     * table, so running it later is harmless.
     */
    private function ensureTable(): void
    {
        if (self::$tableChecked) {
            return;
        }
        self::$tableChecked = true;

        if (Schema::hasTable(self::TABLE)) {
            return;
        }

        Schema::create(self::TABLE, function (Blueprint $table) {
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

    /**
     * @return array<string, array> records keyed by filename
     */
    public function forServer(Server $server): array
    {
        $this->ensureTable();

        $rows = DB::table(self::TABLE)->where('server_id', $server->id)->orderBy('project_name')->get();

        $records = [];
        foreach ($rows as $row) {
            $records[$row->filename] = $this->present((array) $row);
        }

        return $records;
    }

    public function record(Server $server, array $data, ?int $userId): array
    {
        $this->ensureTable();

        $now = CarbonImmutable::now();

        $values = [
            'provider' => $data['provider'],
            'project_id' => (string) $data['project_id'],
            'project_slug' => $data['project_slug'] ?? null,
            'project_name' => mb_substr((string) ($data['project_name'] ?? $data['project_id']), 0, 191),
            'version_id' => (string) $data['version_id'],
            'version_name' => isset($data['version_name']) ? mb_substr((string) $data['version_name'], 0, 191) : null,
            'version_number' => isset($data['version_number']) ? mb_substr((string) $data['version_number'], 0, 191) : null,
            'loader' => $data['loader'],
            'game_version' => $data['game_version'] ?? null,
            'icon_url' => $data['icon_url'] ?? null,
            'sha1' => $data['sha1'] ?? null,
            'size' => $data['size'] ?? null,
            'installed_by' => $userId,
            'updated_at' => $now,
        ];

        $exists = DB::table(self::TABLE)
            ->where('server_id', $server->id)
            ->where('filename', $data['filename'])
            ->exists();

        if ($exists) {
            DB::table(self::TABLE)
                ->where('server_id', $server->id)
                ->where('filename', $data['filename'])
                ->update($values);
        } else {
            DB::table(self::TABLE)->insert($values + [
                'server_id' => $server->id,
                'filename' => $data['filename'],
                'created_at' => $now,
            ]);
        }

        $row = DB::table(self::TABLE)
            ->where('server_id', $server->id)
            ->where('filename', $data['filename'])
            ->first();

        return $this->present((array) $row);
    }

    public function forget(Server $server, string $filename): void
    {
        $this->ensureTable();

        DB::table(self::TABLE)->where('server_id', $server->id)->where('filename', $filename)->delete();
    }

    public function rename(Server $server, string $from, string $to): void
    {
        $this->ensureTable();

        DB::table(self::TABLE)
            ->where('server_id', $server->id)
            ->where('filename', $from)
            ->update(['filename' => $to, 'updated_at' => CarbonImmutable::now()]);
    }

    /**
     * Drop records whose file disappeared (removed through SFTP or the file
     * manager). Fresh records are kept because a background download may not
     * have created the file yet.
     */
    public function purgeMissing(Server $server, array $presentFilenames): void
    {
        $this->ensureTable();

        $cutoff = CarbonImmutable::now()->subMinutes(3);

        $rows = DB::table(self::TABLE)
            ->where('server_id', $server->id)
            ->where('updated_at', '<', $cutoff)
            ->get(['id', 'filename']);

        $present = array_flip($presentFilenames);
        $stale = [];
        foreach ($rows as $row) {
            if (!isset($present[$row->filename])) {
                $stale[] = $row->id;
            }
        }

        if ($stale !== []) {
            DB::table(self::TABLE)->whereIn('id', $stale)->delete();
        }
    }

    private function present(array $row): array
    {
        $record = [];
        foreach (self::COLUMNS as $column) {
            $record[$column] = $row[$column] ?? null;
        }
        $record['size'] = $record['size'] !== null ? (int) $record['size'] : null;
        $record['installed_by'] = $record['installed_by'] !== null ? (int) $record['installed_by'] : null;

        return $record;
    }
}
