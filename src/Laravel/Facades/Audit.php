<?php

namespace DevInspector\Laravel\Facades;

use Illuminate\Support\Facades\Facade;
use DevInspector\AuditCore;

class Audit extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return AuditCore::class;
    }
}