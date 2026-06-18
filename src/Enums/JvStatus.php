<?php

namespace Nml\FinCore\Enums;

enum JvStatus: string
{
    case DRAFT = 'draft';
    case SUBMITTED = 'submitted';
    case POSTED = 'posted';
    case VOID = 'void';
}
