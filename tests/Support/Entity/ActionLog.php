<?php

declare(strict_types=1);

namespace Nowo\LegalKitBundle\Tests\Support\Entity;

use Doctrine\ORM\Mapping as ORM;
use Nowo\AuditKitBundle\ActionLog\AbstractActionLog;

/**
 * Host action log entity of the test kernel (AuditKit action_log.entity_class).
 */
#[ORM\Entity]
#[ORM\Table(name: 'action_log')]
class ActionLog extends AbstractActionLog
{
}
