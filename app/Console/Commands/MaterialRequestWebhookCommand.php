<?php

namespace App\Console\Commands;

use App\Models\ApiClient;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('material-requests:webhook {client : API client id or exact name} {url? : Webhook URL} {--clear : Remove the webhook URL}')]
#[Description('Set or clear the webhook URL for a material-request API client')]
class MaterialRequestWebhookCommand extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $needle = (string) $this->argument('client');
        $client = ApiClient::query()
            ->where('id', is_numeric($needle) ? (int) $needle : -1)
            ->orWhere('name', $needle)
            ->first();

        if (! $client) {
            $this->error('API client not found: '.$needle);

            return self::FAILURE;
        }

        if ($this->option('clear')) {
            $client->update(['webhook_url' => null]);
            $this->info('Webhook cleared for '.$client->name.'.');

            return self::SUCCESS;
        }

        $url = (string) $this->argument('url');
        if ($url === '' || filter_var($url, FILTER_VALIDATE_URL) === false) {
            $this->error('Provide a valid webhook URL or use --clear.');

            return self::FAILURE;
        }

        if (! str_starts_with($url, 'https://')) {
            $this->warn('Webhook URLs should use HTTPS in production.');
        }

        $client->update(['webhook_url' => $url]);
        $this->info('Webhook set for '.$client->name.': '.$url);

        return self::SUCCESS;
    }
}
