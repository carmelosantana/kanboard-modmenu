<?php

namespace Kanboard\Plugin\ModMenu\Exception;

/**
 * The private-state document (or its directory or lock) failed a rule:
 * ModMenu freezes every mutation until the admin resets it. The message never
 * carries anything read from the damaged file.
 */
class PrivateStateDamaged extends ModMenuException
{
    public function __construct()
    {
        parent::__construct(e("ModMenu's private source state is damaged"));
    }
}
