<?php

namespace GoCPA\SpaceHealthcheck\Commands;

use Illuminate\Console\Command;

class SpaceHealthcheckCommand extends Command
{
    public $signature = 'space-healthcheck';

    public $description = 'My command';

    public function handle(): int
    {
        $this->comment('All done');

        return self::SUCCESS;
    }
}
