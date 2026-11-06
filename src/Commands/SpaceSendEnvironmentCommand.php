<?php

declare(strict_types=1);

namespace GoCPA\SpaceHealthcheck\Commands;

use GoCPA\SpaceHealthcheck\Git;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(
    name: 'gocpaspace:send-environment',
    description: 'Отправка текущего стенда в gocpa.space',
)]
final class SpaceSendEnvironmentCommand extends Command
{
    public const DEFAULT_WEBHOOK_URL = 'https://gocpa.space/api/webhooks/project/environments/update';

    /** Этапы деплоя; без --event команда сообщает о завершённом деплое, как раньше. */
    public const EVENTS = ['started', 'finished', 'failed'];

    /** Всего попыток отправки, включая первую. */
    private const ATTEMPTS = 3;

    private const RETRY_DELAY_MS = 2000;

    private const MESSAGE_LIMIT = 2000;

    private const MIGRATIONS_LIMIT = 100;

    /** @var string */
    protected $signature = 'gocpaspace:send-environment
        {--event=finished : Этап деплоя: started, finished или failed}
        {--deploy-id= : Идентификатор запуска, общий для started/finished/failed одного деплоя}
        {--by= : Кто запустил деплой}
        {--message= : Пояснение к этапу, обычно текст ошибки при failed (до 2000 символов)}';

    public function handle(): int
    {
        $event = $this->option('event');
        if (! is_string($event) || ! in_array($event, self::EVENTS, true)) {
            $this->error(sprintf(
                '❌ Неизвестный этап деплоя «%s», допустимые значения --event: %s',
                is_string($event) ? $event : '',
                implode(', ', self::EVENTS),
            ));

            return Command::FAILURE;
        }

        $this->components->info(sprintf('Отправка данных стенда в gocpa.space (этап: %s)', $event));

        try {
            if (! $this->sendEnvironmentPayload($event)) {
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

    protected function sendEnvironmentPayload(string $event): bool
    {
        if (! $secretKey = self::configString('space-healthcheck.secretKey')) {
            $this->warn('⚠️ Переменная GOCPASPACE_HEALTHCHECK_SECRET не найдена в .env');

            return false;
        }

        // Тело собирается один раз, до retry(), — во всех попытках уходит одно и то же.
        $payload = $this->buildPayload($event, now()->getTimestamp());

        Http::acceptJson()
            ->timeout(15)
            ->withHeader('x-space-secret-key', $secretKey)
            // Позиционные аргументы — сигнатура retry(int, int, callable, bool)
            // совпадает во всём диапазоне Laravel 10–13.
            ->retry(self::ATTEMPTS, self::RETRY_DELAY_MS, fn (\Throwable $e): bool => self::shouldRetry($e), true)
            ->post(self::configString('space-healthcheck.webhookUrl') ?: self::DEFAULT_WEBHOOK_URL, $payload)
            ->throw();

        return true;
    }

    /**
     * Повторяем только то, что может пройти со второго раза: обрыв соединения,
     * 5xx и 429. Остальные 4xx не повторяем — 404 означает неверный секрет.
     */
    private static function shouldRetry(\Throwable $e): bool
    {
        if ($e instanceof ConnectionException) {
            return true;
        }

        if ($e instanceof RequestException) {
            $status = $e->response->status();

            return $status === 429 || $status >= 500;
        }

        return false;
    }

    /** @return array<string, mixed> */
    protected function buildPayload(string $event, int $sentAt): array
    {
        return [
            // Порядок и ключи старых секций не менять — их читает портал.
            'app' => $this->getAppInfo(),
            'mail' => $this->getMailInfo(),
            'database' => $this->getDatabaseInfo(),
            'queue' => $this->getQueueInfo(),
            'space-healthcheck' => $this->getHealthcheckInfo(),
            'cloud' => $this->getCloudInfo(),
            'git' => $this->getGitInfo(),
            'deploy' => $this->getDeployInfo($event, $sentAt),
            'migrations' => $this->getMigrationsInfo(),
        ];
    }

    /**
     * Та же форма, что в /space/check: без .git/ — текст ошибки, отправка не падает.
     *
     * @return array<string, string|int|null>
     */
    protected function getGitInfo(): array
    {
        try {
            return app(Git::class)->run();
        } catch (\Throwable $th) {
            return ['exception' => $th->getMessage()];
        }
    }

    /**
     * @return array{event: string, id: ?string, by: ?string, message: ?string, maintenance: ?bool, sentAt: int}
     */
    protected function getDeployInfo(string $event, int $sentAt): array
    {
        $message = $this->stringOption('message');

        return [
            'event' => $event,
            'id' => $this->stringOption('deploy-id'),
            // Инициатора не угадываем: владелец процесса — не тот, кто запустил деплой.
            'by' => $this->stringOption('by'),
            'message' => $message !== null ? mb_substr($message, 0, self::MESSAGE_LIMIT) : null,
            'maintenance' => $this->isDownForMaintenance(),
            'sentAt' => $sentAt,
        ];
    }

    /** null — состояние узнать не удалось (например, недоступен cache-драйвер режима обслуживания). */
    private function isDownForMaintenance(): ?bool
    {
        try {
            return app()->isDownForMaintenance();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Миграции последнего batch основного подключения. Свежий ли это batch,
     * решает портал, сравнивая номер с прошлым деплоем.
     *
     * @return array{batch: ?int, items: list<string>}|array{exception: string}
     */
    protected function getMigrationsInfo(): array
    {
        try {
            $table = self::migrationsTable();
            $batch = DB::connection()->table($table)->max('batch');

            if (! is_numeric($batch)) {
                return ['batch' => null, 'items' => []];
            }

            $batch = (int) $batch;
            $items = DB::connection()->table($table)
                ->where('batch', $batch)
                ->orderBy('migration')
                ->limit(self::MIGRATIONS_LIMIT)
                ->pluck('migration')
                ->all();

            return [
                'batch' => $batch,
                'items' => array_values(array_filter($items, 'is_string')),
            ];
        } catch (\Throwable $th) {
            return ['exception' => $th->getMessage()];
        }
    }

    /** В Laravel 10 database.migrations — строка, в 11+ — массив с ключом table. */
    private static function migrationsTable(): string
    {
        $config = config('database.migrations');
        if (is_array($config)) {
            $config = $config['table'] ?? null;
        }

        return is_string($config) && $config !== '' ? $config : 'migrations';
    }

    /** Пустое значение опции равносильно её отсутствию. */
    private function stringOption(string $name): ?string
    {
        $value = $this->option($name);

        return is_string($value) && trim($value) !== '' ? $value : null;
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
