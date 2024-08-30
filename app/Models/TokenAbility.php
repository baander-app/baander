<?php

namespace App\Models;

enum TokenAbility: string
{
    case ACCESS_API = 'access-api';
    case ACCESS_STREAM = 'access-stream';
    case ISSUE_ACCESS_TOKEN = 'issue-access-token';
}
