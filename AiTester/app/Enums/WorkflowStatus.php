<?php

namespace App\Enums;

enum WorkflowStatus: string
{
    case Candidate = 'candidate';
    case Active = 'active';
    case Ignored = 'ignored';
}
