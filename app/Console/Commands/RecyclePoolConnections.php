<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

// Runs right after the migrations in the PRE_DEPLOY job (.do/app.yaml). The web service queries Postgres
// through DO's PgBouncer transaction pool, whose long-lived server connections keep prepared statements
// across clients and app restarts. After a migration changes a table's columns, a cached
// `select * from "users"` fails with SQLSTATE 0A000 "cached plan must not change result type" on every
// signed-in page until those server connections are replaced (seen 2026-10-10: an app redeploy did NOT
// help). Ending the IDLE backends of our own database user makes PgBouncer open fresh ones that plan
// against the new schema. Idle only: nothing mid-query or mid-transaction is touched; the app's direct
// cache/session connections that get closed reconnect on their next query (Laravel treats
// "terminating connection due to administrator command" as a lost connection). No-op off Postgres.
// ponytail: kills every idle backend of the app user, not just pooled ones; scope by client address
// if the database ever gets other idle clients worth keeping.
class RecyclePoolConnections extends Command
{
    protected $signature = 'db:recycle-pool';

    protected $description = 'End idle Postgres backends so the connection pool re-prepares statements against the current schema';

    public function handle(): int
    {
        $db = DB::connection();
        if ($db->getDriverName() !== 'pgsql') {
            $this->line('Not Postgres: nothing to recycle.');

            return self::SUCCESS;
        }

        $ended = $db->selectOne(
            "select count(pg_terminate_backend(pid)) as n from pg_stat_activity
              where datname = current_database() and usename = current_user and pid <> pg_backend_pid() and state = 'idle'"
        )->n;
        $this->info("Ended {$ended} idle connection(s); the pool reopens them on demand.");

        return self::SUCCESS;
    }
}
