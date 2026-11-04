<?php

declare(strict_types=1);

namespace Nowo\LegalKitBundle\Versioning;

use RuntimeException;

use function sprintf;

/**
 * A version of the page was published after the editor form was read: the save is aborted (rolled back).
 *
 * @internal
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final class StaleVersionException extends RuntimeException
{
    /**
     * @param string $slug Page slug
     */
    public function __construct(string $slug)
    {
        parent::__construct(sprintf('A version of legal page "%s" was published meanwhile.', $slug));
    }
}
