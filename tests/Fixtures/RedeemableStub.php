<?php

namespace Liberu\Ecommerce\GiftCardsAndStoreCredit\Livewire\Tests\Fixtures;

use Liberu\Ecommerce\GiftCardsAndStoreCredit\Livewire\Data\Redeemable;

/**
 * The host's pricing resolver, as a test double.
 *
 * The real one belongs to the application: what a basket costs is a fact about
 * an order and this package does not own orders. What the suite needs from it
 * is the same thing the component needs — a reference in, an amount out, and no
 * way for a browser to influence either.
 */
final class RedeemableStub
{
    /** @var array<string, Redeemable> */
    public static array $prices = [];

    public function __invoke(string $reference): ?Redeemable
    {
        return self::$prices[$reference] ?? null;
    }
}
