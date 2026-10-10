<?php

// db:recycle-pool only acts on Postgres (production); the suite runs on SQLite, where it must be a no-op.
it('does nothing outside Postgres', function () {
    $this->artisan('db:recycle-pool')->expectsOutput('Not Postgres: nothing to recycle.')->assertSuccessful();
});
