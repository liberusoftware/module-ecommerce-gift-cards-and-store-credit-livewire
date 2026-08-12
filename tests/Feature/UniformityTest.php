<?php

use Liberu\Ecommerce\GiftCardsAndStoreCredit\Actions\DisableAccount;
use Liberu\Ecommerce\GiftCardsAndStoreCredit\Actions\RedeemByCode;
use Liberu\Ecommerce\GiftCardsAndStoreCredit\Data\RedemptionInput;
use Liberu\Ecommerce\GiftCardsAndStoreCredit\Enums\RefusalReason;
use Liberu\Ecommerce\GiftCardsAndStoreCredit\Livewire\Components\ApplyGiftCard;
use Liberu\Ecommerce\GiftCardsAndStoreCredit\Livewire\Support\PresenterLimit;

/*
 * **The security spine of this package, in one file.**
 *
 * A code space is only as strong as the cheapest oracle over it. The domain
 * closed the two it owns — one exception class with one constant message, and
 * one password verification whether or not a row was found, so the clock says
 * nothing either. A surface can undo both by being helpful.
 *
 * So every way this box can refuse is enumerated below and asserted to produce
 * the same sentence *and* the same component state, which is what a browser
 * actually receives. Being told "that card has expired" would tell a guesser
 * their code is real; "not enough left" would tell them that and roughly what is
 * on it. Being told nothing at all is the only answer that gives away nothing at
 * all.
 *
 * Trap already paid for: a closure inside a dataset row is passed through
 * untouched, so these are one level of closure and the test calls them.
 */

dataset('every way this box can refuse', [
    'a code no card has' => [fn (): string => A_CODE_NOBODY_HAS],

    // Refused by shape, before any lookup. Answering "that is not a code" would
    // hand a guesser a free format check for nothing.
    'a code that is not even the right shape' => [fn (): string => 'not-a-real-code'],

    'an empty box' => [fn (): string => ''],

    'a code with the wrong characters in it' => [fn (): string => 'ZZZZ-ZZZZ-ZZZZ-ZZZZ-ZZZ!'],

    'a disabled card' => [function (): string {
        $card = issueCard(5000);

        app(DisableAccount::class)->handle($card->account->reference, 'stopped-1', 'reported_lost');

        return (string) $card->code;
    }],

    // Expiry ends redeemability and not the money: the balance is still there,
    // still theirs, and still shown on the balances list. It just cannot be
    // spent, and the bearer is not told which of these eight things happened.
    'an expired card' => [fn (): string => (string) issueCard(5000, expiresAt: now()->subDay()->toIso8601String())->code],

    // Refused, never converted. Picking a rate on a merchant's behalf is a
    // decision they would find out about at the end of the month.
    'a card sold in another currency' => [fn (): string => (string) issueCard(5000, 'USD')->code],

    'a card with less on it than this costs' => [fn (): string => (string) issueCard(100)->code],

    'a card with nothing left on it' => [function (): string {
        $card = issueCard(4798);

        app(RedeemByCode::class)->handle(new RedemptionInput(
            code: (string) $card->code,
            entryKey: 'spent-at-a-till',
            amount: money(4798),
            throttleKey: 'a-till',
        ));

        return (string) $card->code;
    }],

    // The one refusal the domain says a surface *may* treat differently, because
    // a guesser has already worked out they are throttled. This package treats
    // it the same anyway: one message means one message, and a shopper who
    // mistyped four times does not need to know which wall they hit.
    'a presenter who has spent their five attempts' => [function (): string {
        // Signed in, so the actor key is the customer rather than the session —
        // the same counter across every device they own, and a stable one to
        // assert against. The guest half of that key is proved in ThrottleTest.
        asCustomer();

        $card = issueCard(5000);

        for ($attempt = 0; $attempt < 5; $attempt++) {
            applyTo()->call('apply', A_CODE_NOBODY_HAS);
        }

        return (string) $card->code;
    }],

    'an address that has spent its twenty' => [function (): string {
        $card = issueCard(5000);

        for ($attempt = 0; $attempt < 20; $attempt++) {
            PresenterLimit::hitIp('127.0.0.1');
        }

        return (string) $card->code;
    }],

    // A different card, against a step that already recorded one. The domain
    // calls this a permanent conflict and a caller elsewhere in this fleet would
    // be entitled to know; here, reaching it at all requires having presented a
    // real code, so telling the two apart would confirm a guess.
    'a second card after a reload' => [function (): string {
        $first = issueCard(5000, key: 'issue-1');
        $second = issueCard(5000, key: 'issue-2');

        applyTo()->call('apply', $first->code);

        return (string) $second->code;
    }],
]);

it('answers every refusal with the same sentence and the same state', function (Closure $arrange) {
    priced(4798);

    $code = $arrange();

    $refused = applyTo()->call('apply', $code);

    // The sentence.
    expect($refused->get('problem'))->toBe(theRefusal());

    // And the shape. This is every public property, which is exactly what
    // Livewire dehydrates into the snapshot the page receives — so the payload a
    // guesser gets for a wrong code is indistinguishable from the payload they
    // get for a card that is real, disabled, expired, foreign, empty or short.
    expect(snapshotOf($refused))->toEqual([
        'announcement' => theRefusal(),
        'problem' => theRefusal(),
        'reference' => 'CHK-1',
        'entryKey' => ApplyGiftCard::keyFor('CHK-1'),
        'applied' => false,
        'replayed' => false,
        'lastFour' => '',
        'appliedReference' => '',
        'unconfigured' => false,
    ]);
})->with('every way this box can refuse');

it('renders the same words for every refusal, right down to the help text', function (Closure $arrange) {
    priced(4798);

    $code = $arrange();

    $html = applyTo()->call('apply', $code)->html();

    // The elaboration is shown for every refusal alike, so no adopter can make
    // one case chattier than another without editing the one string both use.
    expect($html)
        ->toContain(theRefusal())
        ->toContain(__('module-ecommerce-gift-cards-and-store-credit::gift-cards.apply.refused_help'));

    // Tags stripped first. `wire:loading.attr="disabled"` is markup rather than
    // prose, and a reason leaks by being *read*, so what is checked is what a
    // shopper can read.
    $words = (string) preg_replace('/<[^>]*>/', ' ', $html);

    foreach (RefusalReason::cases() as $reason) {
        // The reason is for the operator's log and the domain's own
        // `RedemptionFailed` event. A surface that rendered it would have undone
        // the whole control in one interpolation.
        expect($words)->not->toContain($reason->value);
    }
})->with('every way this box can refuse');

it('has exactly one refusal message in the whole package', function () {
    $offenders = [];

    foreach (everySourceFile() as $file) {
        // Every refusal in the component funnels through one private method with
        // no argument, so there is no call site that could choose a different
        // string. Asserted rather than trusted: a second `apply.refused_*` key
        // reaching a shopper is how this gets undone, and it would be a
        // one-line diff nobody read carefully.
        preg_match_all("/apply\.refused[a-z_]*/", (string) file_get_contents($file), $found);

        $offenders = array_merge($offenders, $found[0]);
    }

    expect(array_unique($offenders))->toBe(['apply.refused']);
});
