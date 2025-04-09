<?php

declare(strict_types=1);

namespace GoCPA\SpaceHealthcheck\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(
    name: 'gocpaspace:send-environment',
    description: 'Отправка текущего стенда в gocpa.space',
)]
final class SpaceSendEnvironmentCommand extends Command
{
    public function handle(): int
    {
        $this->components->info('Отправка данных стенда в gocpa.space');

        try {
            $this->sendEnvironmentPayload();
            $this->info('✅ Данные успешно отправлены в gocpa.space');
            $this->newLine();
        } catch (\Throwable $e) {
            $this->error('❌ Ошибка при отправке данных: '.$e->getMessage());

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    protected function sendEnvironmentPayload(): void
    {
        if (! $secretKey = config('space-healthcheck.secretKey')) {
            $this->warn('⚠️ Переменная GOCPASPACE_HEALTHCHECK_SECRET не найдена в .env');

            return;
        }

        $payload = $this->buildPayload();

        Http::acceptJson()
            ->withoutVerifying()
            ->withHeader('x-space-secret-key', $secretKey)
            ->post(
                'https://gocpa.space/api/webhooks/project/environments/update',
                $payload
            )
            ->throw();
    }

    /** @phpstan-ignore missingType.iterableValue */
    protected function buildPayload(): array
    {
        return [
            'app' => $this->getAppInfo(),
            'mail' => $this->getMailInfo(),
            'database' => $this->getDatabaseInfo(),
            'queue' => $this->getQueueInfo(),
            'space-healthcheck' => $this->getHealthcheckInfo(),
            'cloud' => $this->getCloudInfo(),
        ];
    }

    /** @phpstan-ignore missingType.iterableValue */
    protected function getAppInfo(): array
    {
        return [
            'name' => config('app.name'),
            'env' => config('app.env'),
            'url' => config('app.url'),
            'timezone' => config('app.timezone'),
        ];
    }

    /** @phpstan-ignore missingType.iterableValue */
    protected function getDatabaseInfo(): array
    {
        $defaultDb = Config::string('database.default');
        $databases = Config::array('database.connections', []);

        $database = [];
        if (array_key_exists($defaultDb, $databases)) {
            $currDb = $databases[$defaultDb];
            $database['url'] = $currDb['url'];
            $database['host'] = $currDb['host'];
            $database['port'] = $currDb['port'];
            $database['database'] = $currDb['database'];
            $database['username'] = $currDb['username'];
        }

        return [
            'type' => $defaultDb,
            'database' => $database,
            'redis' => [
                'client' => config('database.redis.client'),
                'host' => config('database.redis.default.host'),
                'port' => config('database.redis.default.port'),
            ],
        ];
    }

    /** @phpstan-ignore missingType.iterableValue */
    protected function getQueueInfo(): array
    {
        $horizon = [];

        try {
            $horizon['prefix'] = config('horizon.prefix');
            $supervisors = Config::array('horizon.defaults', []);
            $environments = Config::array('horizon.environments', []);
            $env = Config::string('app.env');
            $environment = array_key_exists($env, $environments) ? $environments[$env] : [];
            $horizon['config'] = array_merge_recursive($supervisors, $environment);
        } catch (\Throwable $th) {
            $horizon['th'] = $th->getMessage();
        }

        return [
            'driver' => config('queue.default'),
            'horizon' => $horizon,
        ];
    }

    /** @phpstan-ignore missingType.iterableValue */
    protected function getMailInfo(): array
    {
        return [
            'mailer' => config('mail.default'),
            'host' => config('mail.mailers.smtp.host'),
            'port' => config('mail.mailers.smtp.port'),
        ];
    }

    /** @phpstan-ignore missingType.iterableValue */
    protected function getHealthcheckInfo(): array
    {
        $folder = config('space-healthcheck.folder');
        if (is_null($folder) && function_exists('base_path')) {
            $folder = base_path();
        }

        return [
            'projectId' => config('space-healthcheck.projectId'),
            'folder' => $folder,
            'webserverExtPort' => config('space-healthcheck.webserverExtPort'),
        ];
    }

    /** @phpstan-ignore missingType.iterableValue */
    protected function getCloudInfo(): array
    {
        return [
            'GOCPA_PROJECT' => config('app.gocpa_project'),
            'PROJECT_NAME' => config('app.project_name'),
        ];
    }
}
