<?php

namespace App\Console\Commands;

use App\Models\ApiClient;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('material-requests:alumni-certificate-exempt {client? : API client id or exact name} {--enable : Allow this client to submit grandfather proofs} {--disable : Remove that permission}')]
#[Description('Show or set alumni certificate grandfather exemption, and whether prefill_secret matches the API key')]
class AlumniCertificateExemptCommand extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $needle = $this->argument('client');
        if ($needle === null || $needle === '') {
            if ($this->option('enable') || $this->option('disable')) {
                $this->error('Pass a client id or exact name with --enable or --disable.');

                return self::FAILURE;
            }
            foreach (ApiClient::query()->orderBy('id')->get() as $client) {
                $this->line($this->describe($client));
            }

            return self::SUCCESS;
        }

        if ($this->option('enable') && $this->option('disable')) {
            $this->error('Use only one of --enable or --disable.');

            return self::FAILURE;
        }

        $client = $this->findClient((string) $needle);
        if (! $client) {
            $this->error('API client not found: '.$needle);

            return self::FAILURE;
        }

        if ($this->option('enable')) {
            $client->allows_alumni_certificate_exempt = true;
            $client->save();
        }
        if ($this->option('disable')) {
            $client->allows_alumni_certificate_exempt = false;
            $client->save();
        }

        $client = $client->fresh() ?? $client;
        $this->line($this->describe($client));
        if ($client->allows_alumni_certificate_exempt && ! $client->prefillSecretMatchesApiKey()) {
            $this->warn('prefill_secret does not match this client API key. Proofs signed with the portal API key will fail until the stored prefill secret is that same value.');
        }

        return self::SUCCESS;
    }

    private function findClient(string $needle): ?ApiClient
    {
        $query = ApiClient::query();
        if (ctype_digit($needle)) {
            $query->whereKey((int) $needle);
        } else {
            $query->where('name', $needle);
        }

        return $query->first();
    }

    private function describe(ApiClient $client): string
    {
        return sprintf(
            'id=%d name=%s active=%s exempt=%s prefill_secret_present=%s prefill_secret_matches_api_key=%s',
            $client->id,
            $client->name,
            $client->active ? 'yes' : 'no',
            $client->allows_alumni_certificate_exempt ? 'yes' : 'no',
            $this->secretPresent($client) ? 'yes' : 'no',
            $client->prefillSecretMatchesApiKey() ? 'yes' : 'no',
        );
    }

    private function secretPresent(ApiClient $client): bool
    {
        try {
            return filled($client->prefill_secret);
        } catch (\Throwable) {
            return false;
        }
    }
}
