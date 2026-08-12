<?php

return [

    /*
    |--------------------------------------------------------------------------
    | What a reference is worth, and where the movement is recorded against
    |--------------------------------------------------------------------------
    |
    | **The amount is never a client input.** The apply control is mounted with
    | an opaque reference — a checkout reference, a basket id, whatever the host
    | issues — and that reference is turned into an amount *here*, on the
    | server, on the request that spends the card, by a class the deployment
    | names.
    |
    | Name a class the container can build and that is invokable as:
    |
    |     __invoke(string $reference): ?\Liberu\Ecommerce\GiftCardsAndStoreCredit\Livewire\Data\Redeemable
    |
    | returning null for a reference that means nothing, which the component
    | answers with a 404 — the same answer a reference belonging to somebody
    | else gets, because the difference between the two is information about
    | another shopper's basket.
    |
    | There is no default and there can be no default: a package that guessed
    | what somebody was spending would be the bug this arrangement prevents.
    | Left unset, the apply control renders no field at all.
    |
    */

    'redeemable' => env('GIFT_CARDS_LIVEWIRE_REDEEMABLE'),

    /*
    |--------------------------------------------------------------------------
    | Who is looking
    |--------------------------------------------------------------------------
    |
    | The balances list is scoped to one person, and that person is identified
    | by the id the domain stored in `customer_id`.
    |
    | Left unset this is `auth()->id()`, which is what a Liberu application
    | wants. Name a class invokable as `__invoke(): int|string|null` to say
    | otherwise — a host whose customers are not its users, say.
    |
    | A non-numeric id resolves to **nobody**, and nobody sees nothing. That
    | fails closed and it is completely silent, which is the direction to fail
    | in and worth knowing about when a ULID-keyed host sees empty lists:
    | `customer_id` is an integer column in the domain.
    |
    */

    'viewer' => env('GIFT_CARDS_LIVEWIRE_VIEWER'),

    /*
    |--------------------------------------------------------------------------
    | Which team's liabilities are being looked at
    |--------------------------------------------------------------------------
    |
    | A gift card is a liability of the merchant that sold it, and in this
    | platform the merchant entity is the team. A host running several
    | storefronts off one database names a class invokable as
    | `__invoke(): int|string|null` here, and the balances list is filtered to
    | that team.
    |
    | Left unset, a customer sees every balance of their own, whichever
    | storefront sold it. That is not a leak — they are the customer on every
    | row — but on a multi-brand host it is one brand showing another brand's
    | card, so set it.
    |
    | A non-numeric answer is treated as unset rather than as a team, for the
    | same reason as above.
    |
    */

    'team' => env('GIFT_CARDS_LIVEWIRE_TEAM'),

    /*
    |--------------------------------------------------------------------------
    | The second rate limit, the one keyed by address
    |--------------------------------------------------------------------------
    |
    | The domain already limits per **presenter**, and this package hands it an
    | actor key — the signed-in customer, or the session for a guest. That alone
    | is a limiter somebody walks around with a cookie jar: a fresh session is a
    | fresh five attempts, and a script can mint sessions faster than it can
    | guess codes.
    |
    | So there is a second bucket here, keyed by IP address, which a guesser
    | cannot rotate for free. It is deliberately looser than the per-actor one,
    | because an office, a school and a mobile network are all one address, and
    | a limit that locked out a building would be a limit somebody switched off.
    |
    | Both are documented in `docs/adoption.md`, and being throttled by either
    | answers the shopper in exactly the same words as a wrong code does.
    |
    */

    'ip' => [
        'max_attempts' => (int) env('GIFT_CARDS_LIVEWIRE_IP_MAX_ATTEMPTS', 20),
        'decay_seconds' => (int) env('GIFT_CARDS_LIVEWIRE_IP_DECAY', 60),
    ],

];
