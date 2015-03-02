<?php namespace Digitalkrikits\Pushnotifications\Facades;

use Illuminate\Support\Facades\Facade;

class Pushnotifications extends Facade {

    /**
     * Get the registered name of the component.
     *
     * @return string
     */
    protected static function getFacadeAccessor()
    {
        return 'pushnotifications';
    }

}
