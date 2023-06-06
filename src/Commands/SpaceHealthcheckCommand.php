<?php

declare(strict_types=1);

namespace GoCPA\SpaceHealthcheck\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;

class SpaceHealthcheckCommand extends Command
{
    public $signature = 'gocpa:space-healthcheck {--secretKey=}';

    public $description = 'Configures the GoCPA/HealthCheck config.';

    protected $hidden = true;

    public function handle(): int
    {
        $arg = [];
        $env = [];

        $secretKey = $this->option('secretKey');

        if (! empty($secretKey) || ! $this->isEnvKeySet('GOCPASPACE_HEALTHCHECK_SECRET')) {
            if (empty($secretKey)) {
                $secretKeyFromInput = $this->askForSecretKeyInput();

                if (empty($secretKeyFromInput)) {
                    $this->error('Please provide a valid secret key using the `--secretKey` option or setting `GOCPASPACE_HEALTHCHECK_SECRET` in your `.env` file!');

                    return 1;
                }

                $secretKey = $secretKeyFromInput;
            }

            $env['GOCPASPACE_HEALTHCHECK_SECRET'] = $secretKey;
            $arg['--secretKey'] = $secretKey;
        }

        if (! $this->setEnvValues($env)) {
            return 1;
        }

        $this->info('Open this link in browser: '.route('space.check', ['secretKey' => config('space-healthcheck.secretKey')]));

        return self::SUCCESS;
    }

    private function setEnvValues(array $values): bool
    {
        $envFilePath = app()->environmentFilePath();

        $envFileContents = file_get_contents($envFilePath);

        if (! $envFileContents) {
            $this->error('Could not read `.env` file!');

            return false;
        }

        if (count($values) > 0) {
            foreach ($values as $envKey => $envValue) {
                if ($this->isEnvKeySet($envKey, $envFileContents)) {
                    $envFileContents = preg_replace("/^{$envKey}=.*?[\s$]/m", "{$envKey}={$envValue}\n", $envFileContents);

                    $this->info("Updated {$envKey} with new value in your `.env` file.");
                } else {
                    $envFileContents .= "{$envKey}={$envValue}\n";

                    $this->info("Added {$envKey} to your `.env` file.");
                }
            }
        }

        if (! file_put_contents($envFilePath, $envFileContents)) {
            $this->error('Updating the `.env` file failed!');

            return false;
        }

        return true;
    }

    private function isEnvKeySet(string $envKey, ?string $envFileContents = null): bool
    {
        $envFileContents = $envFileContents ?? file_get_contents(app()->environmentFilePath());

        return (bool) preg_match("/^{$envKey}=.*?[\s$]/m", $envFileContents);
    }

    private function askForSecretKeyInput(): string
    {
        if ($this->option('no-interaction')) {
            return '';
        }

        while (true) {
            $this->info('');

            $this->question('Please paste the GoCPA.space Healthcheck secret key here');

            $secretKey = $this->ask('Secret Key');

            // In case someone copies it with GOCPASPACE_HEALTHCHECK_SECRET=
            $secretKey = Str::after($secretKey, '=');

            return $secretKey;
        }
    }
}
