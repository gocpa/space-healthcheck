<?php

namespace GoCPA\SpaceHealthcheck\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @see \GoCPA\SpaceHealthcheck\SpaceHealthcheck
 */
class SpaceHealthcheck extends Facade
{
    protected static function getFacadeAccessor()
    {
        return \GoCPA\SpaceHealthcheck\SpaceHealthcheck::class;
    }
}
