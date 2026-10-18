<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Nowo\AuditKitBundle\ActionLog\AbstractActionLog;

/**
 * AuditKit action log of the demo: LegalKit records legal_document.saved / restored / imported here.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
#[ORM\Entity]
#[ORM\Table(name: 'action_log')]
class ActionLog extends AbstractActionLog
{
}
