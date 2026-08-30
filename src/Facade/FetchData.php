<?php

namespace Teksite\Handler\Facade;


use Illuminate\Support\Facades\Facade;
use Teksite\Handler\Contracts\FetchDataContract;

class FetchData extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return FetchDataContract::class;
    }
}
