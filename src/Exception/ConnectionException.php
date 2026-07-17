<?php

declare(strict_types=1);

namespace Normbill\Xrechnung\Exception;

/**
 * The request never produced an HTTP response — DNS/network failure or
 * timeout. The curl error message is in getMessage().
 */
class ConnectionException extends NormbillException
{
}
