<?php

namespace Liberu\Ecommerce\GiftCardsAndStoreCredit\Livewire\Data;

use Liberu\Ecommerce\GiftCardsAndStoreCredit\Data\Money;

/**
 * **What a reference is worth, decided by the host, on the server.**
 *
 * The apply control is mounted with an opaque reference and nothing else. It
 * has no amount property and no currency property, because a browser that can
 * name either can spend a pound of a fifty pound card and keep the rest.
 * What it does instead is hand the reference to the class named in
 * `gift-cards-livewire.redeemable` and use whatever comes back.
 *
 * That resolver is the host's, and it must be: what somebody owes is a fact
 * about a basket, and this package does not own baskets. It owns the box.
 *
 * ### Why this is a type and not an array
 *
 * The amount is the domain's own `Money` — integer minor units and a currency
 * with no default — so a host that tries to return a float cannot, and a host
 * that forgets the currency gets a `TypeError` at the seam rather than a
 * redemption refused at the end of the month for being denominated in nothing.
 *
 * ### Why the resolver does not build the domain's `RedemptionInput`
 *
 * That was the shorter version and it is the wrong one. `RedemptionInput`
 * carries the code and the idempotency key. A resolver that built it would be
 * a second place a bearer credential exists, in host code this package cannot
 * see, one `Log::debug($input)` away from being written down. The code never
 * leaves the component's stack frame, and the key belongs to the component.
 */
final readonly class Redeemable
{
    public function __construct(
        /**
         * What this reference is worth right now, from the server, in integer
         * minor units.
         *
         * The card is redeemed for **this exact amount or not at all** — the
         * domain refuses rather than clamping, and this package has no way to
         * ask what is on a card before spending it. See `docs/domain.md` §4.
         */
        public Money $amount,

        /**
         * What the movement is recorded against in the ledger, for later
         * reconciliation. An opaque string this module never interprets —
         * a checkout reference is the obvious one.
         *
         * Defaults to the reference the component was mounted with.
         */
        public ?string $sourceReference = null,
    ) {}
}
