<?php

declare(strict_types=1);

namespace GoCPA\SpaceHealthcheck\Commands;

use Illuminate\Console\Command;
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

        if (! $secretKey = config('space-healthcheck.secretKey')) {
            $this->warn('⚠️ Переменная GOCPASPACE_HEALTHCHECK_SECRET не найдена в .env');

            return Command::FAILURE;
        }

        try {
            $this->sendEnvironmentPayload($secretKey);
            $this->info('✅ Данные успешно отправлены в gocpa.space');
            $this->newLine();

            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('❌ Ошибка при отправке данных: '.$e->getMessage());

            return Command::FAILURE;
        }

        return 0;
    }

    protected function sendEnvironmentPayload(string $secretKey): void
    {
        $payload = $this->buildPayload();
        dd($payload);

        Http::acceptJson()
            ->withoutVerifying()
            ->withHeader('x-space-secret-key', $secretKey)
            ->post(
                'https://gocpa.space/api/webhooks/project/environments/update',
                $payload
            )
            ->throw();
    }

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

    protected function getAppInfo(): array
    {
        return [
            'name' => config('app.name'),
            'env' => config('app.env'),
            'url' => config('app.url'),
            'timezone' => config('app.timezone'),
        ];
    }

    protected function getDatabaseInfo(): array
    {
        $defaultDb = config('database.default');

        return [
            'connection' => [
                'type' => $defaultDb,
                'host' => config('database.connections.'.$defaultDb.'.host'),
                'port' => config('database.connections.'.$defaultDb.'.port'),
                'database' => config('database.connections.'.$defaultDb.'.database'),
                'username' => config('database.connections.'.$defaultDb.'.username'),
            ],
            'redis' => [
                'client' => config('database.redis.client'),
                'host' => config('database.redis.default.host'),
                'port' => config('database.redis.default.port'),
            ],
        ];
    }

    protected function getQueueInfo(): array
    {
        $horizon = [];

        try {
            $horizon['prefix'] = config('horizon.prefix');
            $supervisors = config('horizon.defaults', []);
            $environments = config('horizon.environments.'.config('app.env'), []);
            $horizon['config'] = array_merge_recursive($supervisors, $environments);
        } catch (\Throwable $th) {
            $horizon['th'] = $th->getMessage();
        }

        return [
            'driver' => config('queue.default'),
            'horizon' => $horizon,
        ];
    }

    protected function getMailInfo(): array
    {
        return [
            'mailer' => config('mail.default'),
            'host' => config('mail.mailers.smtp.host'),
            'port' => config('mail.mailers.smtp.port'),
        ];
    }

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

    protected function getCloudInfo(): array
    {
        return [
            'GOCPA_PROJECT' => config('app.gocpa_project'),
            'PROJECT_NAME' => config('app.project_name'),
        ];
    }
}
