<?php

namespace Liberu\Ecommerce\GiftCardsAndStoreCredit\Livewire\Tests\Fixtures;

/**
 * A host that answers "who is looking" or "whose shop is this" its own way.
 *
 * Both resolvers have the same shape — `__invoke(): int|string|null` — so they
 * share one double. What is being tested through it is that a host can answer
 * with something that is not a whole number and be told *nobody* rather than
 * *everybody*.
 */
final class IdStub
{
    public static int|string|null $answer = null;

    public function __invoke(): int|string|null
    {
        return self::$answer;
    }
}
