<?php

declare(strict_types=1);

namespace GoCPA\SpaceHealthcheck\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
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
            if (! $this->sendEnvironmentPayload()) {
                return Command::FAILURE;
            }

            $this->info('✅ Данные успешно отправлены в gocpa.space');
            $this->newLine();
        } catch (\Throwable $e) {
            $this->error('❌ Ошибка при отправке данных: '.$e->getMessage());

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    protected function sendEnvironmentPayload(): bool
    {
        if (! $secretKey = self::configString('space-healthcheck.secretKey')) {
            $this->warn('⚠️ Переменная GOCPASPACE_HEALTHCHECK_SECRET не найдена в .env');

            return false;
        }

        $payload = $this->buildPayload();

        Http::acceptJson()
            ->timeout(15)
            ->withHeader('x-space-secret-key', $secretKey)
            ->post(
                'https://gocpa.space/api/webhooks/project/environments/update',
                $payload
            )
            ->throw();

        return true;
    }

    /** @return array<string, mixed> */
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

    /** @return array{name: string, env: string, url: string, timezone: string} */
    protected function getAppInfo(): array
    {
        return [
            'name' => self::configString('app.name'),
            'env' => self::configString('app.env'),
            'url' => self::configString('app.url'),
            'timezone' => self::configString('app.timezone'),
        ];
    }

    /** @return array{type: string, database: array<string, string>, redis: array<string, string>} */
    protected function getDatabaseInfo(): array
    {
        $defaultDb = self::configString('database.default');
        $databases = self::configArray('database.connections');

        $database = [];
        if (array_key_exists($defaultDb, $databases)) {
            $database['url'] = self::configString('database.connections.'.$defaultDb.'.url');
            $database['host'] = self::configString('database.connections.'.$defaultDb.'.host');
            $database['port'] = self::configString('database.connections.'.$defaultDb.'.port');
            $database['database'] = self::configString('database.connections.'.$defaultDb.'.database');
            $database['username'] = self::configString('database.connections.'.$defaultDb.'.username');
        }

        return [
            'type' => $defaultDb,
            'database' => $database,
            'redis' => [
                'client' => self::configString('database.redis.client'),
                'host' => self::configString('database.redis.default.host'),
                'port' => self::configString('database.redis.default.port'),
            ],
        ];
    }

    /** @return array{driver: string, horizon: array<string, mixed>} */
    protected function getQueueInfo(): array
    {
        $horizon = [];

        try {
            $horizon['prefix'] = self::configString('horizon.prefix');
            $supervisors = self::configArray('horizon.defaults');
            $environments = self::configArray('horizon.environments');
            $environment = $environments[self::configString('app.env')] ?? [];
            $horizon['config'] = array_replace_recursive($supervisors, is_array($environment) ? $environment : []);
        } catch (\Throwable $th) {
            $horizon['th'] = $th->getMessage();
        }

        return [
            'driver' => self::configString('queue.default'),
            'horizon' => $horizon,
        ];
    }

    /** @return array{mailer: string, host: string, port: string} */
    protected function getMailInfo(): array
    {
        return [
            'mailer' => self::configString('mail.default'),
            'host' => self::configString('mail.mailers.smtp.host'),
            'port' => self::configString('mail.mailers.smtp.port'),
        ];
    }

    /** @return array{projectId: string, folder: string, webserverExtPort: string} */
    protected function getHealthcheckInfo(): array
    {
        $folder = self::configString('space-healthcheck.folder');
        if (empty($folder) && function_exists('base_path')) {
            $folder = base_path();
        }

        return [
            'projectId' => self::configString('space-healthcheck.projectId'),
            'folder' => $folder,
            'webserverExtPort' => self::configString('space-healthcheck.webserverExtPort'),
        ];
    }

    /** @return array{GOCPA_PROJECT: string, PROJECT_NAME: string} */
    protected function getCloudInfo(): array
    {
        return [
            'GOCPA_PROJECT' => self::configString('app.gocpa_project'),
            'PROJECT_NAME' => self::configString('app.project_name'),
        ];
    }

    /**
     * Get the specified array configuration value.
     *
     * @param  (\Closure():(array<array-key, mixed>|null))|array<array-key, mixed>|null  $default
     * @return array<array-key, mixed>
     */
    public static function configArray(string $key, $default = null): array
    {
        $value = config($key, $default);

        if (! is_array($value)) {
            throw new InvalidArgumentException(
                sprintf('Configuration value for key [%s] must be an array, %s given.', $key, gettype($value))
            );
        }

        return $value;
    }

    /**
     * Get the specified string configuration value.
     *
     * Числа приводятся к строке: во фреймворке хватает конфигов с числовым
     * дефолтом (например, mail.mailers.smtp.port => env('MAIL_PORT', 2525)),
     * и падать из-за них всей командой нельзя.
     *
     * @param  (\Closure():(string|null))|string|null  $default
     */
    public static function configString(string $key, $default = ''): string
    {
        $value = config($key, $default);
        if (is_null($value)) {
            return '';
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        if (! is_string($value)) {
            throw new InvalidArgumentException(
                sprintf('Configuration value for key [%s] must be a string, %s given.', $key, gettype($value))
            );
        }

        return $value;
    }
}
