<?php

namespace App\Console\Commands;

use App\Models\ApiClient;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

#[Signature('material-requests:client {name} {--origin=}')]
#[Description('Create an API client for material requests and output the plain key')]
class MaterialRequestClientCommand extends Command
{
    /**
     * Execute the console command.
     */
    public function handle()
    {
        $name = $this->argument('name');
        $origin = $this->option('origin');
        $key = Str::random(40);

        $client = ApiClient::query()->create([
            'name' => $name,
            'key_hash' => Hash::make($key),
            'prefill_secret' => $key,
            'allowed_origin' => $origin,
            'active' => true,
        ]);

        $this->info('API client created: '.$client->id);
        $this->line('Name: '.$client->name);
        if ($origin) {
            $this->line('Allowed origin: '.$origin);
        }
        $this->line('X-LIA-Key / prefill secret: '.$key);
        $this->warn('Store this key securely; it is used for API calls and to sign prefill tokens, and will not be shown again.');

        return self::SUCCESS;
    }
}
