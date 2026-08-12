<?php

return [

    'loading' => 'Working…',

    // Currency code rather than a symbol. A symbol table is a per-locale problem
    // this package would get wrong, and "GBP 19.99" is never ambiguous about
    // which of the four dollars it means — which matters here more than most
    // places, because a card in one currency is refused against another rather
    // than converted.
    'money' => ':currency :amount',

    'apply' => [
        'heading' => 'Pay with a gift card',
        'label' => 'Gift card code',
        'hint' => 'The code printed on your card. Letters and numbers, spaces do not matter.',
        'due' => ':amount to pay.',
        'button' => 'Use this gift card',
        'applying' => 'Checking your gift card…',

        // **The one refusal, and it must stay one.**
        //
        // Every reason a redemption can fail says exactly this: an unknown code,
        // a disabled card, an expired card, a card in the wrong currency, a card
        // without enough left on it, an empty card, a rate-limited presenter,
        // and a different card already applied to this order.
        //
        // Anything more specific tells whoever typed it that the code they typed
        // is *real*, which is the one thing a guesser cannot work out on their
        // own. An adopter translating this file: keep it one string. If your
        // wording needs a second sentence, add it to `refused_help` below, which
        // is shown in the same place for every refusal alike.
        'refused' => 'That gift card cannot be used for this purchase.',
        'refused_help' => 'Check the code and try again. If it keeps happening, contact us and we will look it up for you.',

        // Announced once, in the live region, by the request that spent the
        // card — the only place the amount actually spent is known without
        // holding a money value on the component.
        'applied' => ':amount paid with the gift card ending :last_four.',
        // A replay is the shopper's own second click, or their reload. They see
        // their payment, not a second one and not an error.
        'replayed' => ':amount was already paid with the gift card ending :last_four. It has not been used twice.',

        // The line that stays on the page afterwards. No amount in it: what was
        // spent is a fact about a movement, and the component holds no money
        // value to re-render it from.
        'confirmed' => 'Gift card applied.',
        'confirmed_replayed' => 'Gift card applied. It has not been used twice.',
        'already' => 'A gift card has already been used for this purchase.',
        'remaining' => ':amount left on this card.',
        'card' => 'Gift card ending :last_four',

        'closed' => 'This purchase can no longer be paid for. Go back and start again.',

        // Said to a shopper, because it is not their fault and there is nothing
        // for them to fix. The deployment's problem is in docs/runbook.md, and
        // in this state the form is not rendered at all — there is nowhere to
        // type a credential into a page that would only drop it.
        'unconfigured' => 'This shop cannot take gift cards yet.',
    ],

    'balances' => [
        'heading' => 'Your gift cards and store credit',
        'empty' => 'You have no gift cards or store credit.',
        'balance' => 'Balance',
        'expires' => 'Can be used until :date',
        'expired' => 'This card can no longer be spent. The balance is still yours — contact us.',
        'disabled' => 'This card has been stopped. Contact us.',
        'spent' => 'Nothing left on this one.',
        // No box to type a code into, and this says why in the place somebody
        // would look for one.
        'no_lookup' => 'A gift card bought for you appears here once we know it is yours. To spend one you were given, enter its code at checkout.',
    ],

    'kind' => [
        'gift_card' => 'Gift card',
        'store_credit' => 'Store credit',
    ],

    'status' => [
        'active' => 'Ready to use',
        'empty' => 'Nothing left',
        'expired' => 'Past its date',
        'disabled' => 'Stopped',
    ],

];
