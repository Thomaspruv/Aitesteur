<?php

namespace App\Enums;

enum WorkflowOrigin: string
{
    case Discovered = 'discovered';
    case Authored = 'authored';
    case Imported = 'imported';
}
