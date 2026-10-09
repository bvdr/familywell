<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Laravel\Passport\ClientRepository;

// One-off setup for the module-requests board and its admin: a confidential authorization-code client.
// The secret is printed once (Passport stores only its hash) and goes into the Worker as OAUTH_CLIENT_SECRET.
class BoardClient extends Command
{
    protected $signature = 'board:client {name : e.g. "FamilyWell board"} {redirect : the Worker callback URL}';

    protected $description = 'Create a first-party OAuth client for the module-requests board or its admin';

    public function handle(ClientRepository $clients): int
    {
        $client = $clients->createAuthorizationCodeGrantClient($this->argument('name'), [$this->argument('redirect')], confidential: true);

        $this->info("Client ID:     {$client->getKey()}");
        $this->info("Client secret: {$client->plainSecret}");
        $this->warn('Add the ID to BOARD_CLIENT_IDS. The secret is shown only now: store it as the Worker secret OAUTH_CLIENT_SECRET.');

        return self::SUCCESS;
    }
}
