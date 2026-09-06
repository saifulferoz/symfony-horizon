<?php

declare(strict_types=1);

namespace Saifulferoz\SymfonyHorizon\Transport;

use Symfony\Component\Messenger\Stamp\NonSendableStampInterface;

/**
 * In-process carrier for the dispatch timestamp read back from the transport
 * header. NonSendable: it must never be written to a transport, so the bundle
 * stays absent from the wire format.
 */
final class DispatchedAt implements NonSendableStampInterface
{
    public function __construct(public readonly float $dispatchedAt)
    {
    }
}
