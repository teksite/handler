<?php

namespace Teksite\Handler\Enums;

enum ResponseType: string
{
    case SUCCESS = 'success';
    case FAILED = 'failed';
    case Error = 'error';
    case WARNING = 'warning';
    case INFO = 'info';
}
