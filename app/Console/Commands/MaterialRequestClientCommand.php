<?php

namespace App\Console\Commands;

use App\Models\ApiClient;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

#[Signature('material-requests:client {name} {--origin=} {--webhook=}')]
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
        $webhook = $this->option('webhook');
        if ($webhook && filter_var($webhook, FILTER_VALIDATE_URL) === false) {
            $this->error('The --webhook value must be a valid URL.');

            return self::FAILURE;
        }
        $key = Str::random(40);

        $client = ApiClient::query()->create([
            'name' => $name,
            'key_hash' => Hash::make($key),
            'prefill_secret' => $key,
            'allowed_origin' => $origin,
            'webhook_url' => $webhook ?: null,
            'active' => true,
        ]);

        $this->info('API client created: '.$client->id);
        $this->line('Name: '.$client->name);
        if ($origin) {
            $this->line('Allowed origin: '.$origin);
        }
        if ($webhook) {
            $this->line('Webhook URL: '.$webhook);
        }
        $this->line('X-LIA-Key / prefill secret: '.$key);
        $this->warn('Store this key securely; it is used for API calls and to sign prefill tokens, and will not be shown again.');

        return self::SUCCESS;
    }
}
