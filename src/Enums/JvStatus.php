<?php

namespace Nml\FinCore\Enums;

enum JvStatus: string
{
    case DRAFT = 'draft';
    case SUBMITTED = 'submitted';
    case PENDING_APPROVAL = 'pending_approval';
    case APPROVED = 'approved';
    case REJECTED = 'rejected';
    case POSTED = 'posted';
    case VOID = 'void';
}
