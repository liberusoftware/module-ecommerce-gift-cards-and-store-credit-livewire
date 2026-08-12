<?php

use Illuminate\Support\Facades\RateLimiter;
use Liberu\Ecommerce\GiftCardsAndStoreCredit\Livewire\Support\PresenterLimit;
use Liberu\Ecommerce\GiftCardsAndStoreCredit\Models\GiftCardEntry;

/*
 * Without a limit the box is a brute-force endpoint. There are two limits, and
 * either one alone is a limiter somebody walks around:
 *
 * - **Per actor**, enforced by the domain on the key this package hands it. Five
 *   attempts a minute by default. A signed-in customer keeps one counter across
 *   every device; a guest gets their session.
 * - **Per address**, enforced here. Twenty a minute by default, deliberately
 *   looser because an office, a school and a mobile carrier are each one
 *   address.
 *
 * A per-session limit alone is five attempts per cookie jar, and a script mints
 * cookie jars faster than it guesses codes. An address limit alone locks out a
 * building the first time one person mistypes.
 */

beforeEach(function (): void {
    priced(4798);
});

it('stops a signed-in customer after the domain\'s five attempts', function () {
    $customer = asCustomer();

    for ($attempt = 0; $attempt < 5; $attempt++) {
        applyTo()->call('apply', A_CODE_NOBODY_HAS);
    }

    $card = issueCard(5000, customerId: (int) $customer->id);

    // The sixth is refused without the card being looked at — and refused in the
    // same words, so being throttled tells a guesser nothing being throttled had
    // not already told them.
    applyTo()->call('apply', $card->code)->assertSet('applied', false);

    expect(GiftCardEntry::query()->where('kind', 'redeemed')->count())->toBe(0);
});

it('gives a guest a counter of their own, keyed by session', function () {
    // Nobody is signed in, which is the composition a storefront is actually in.
    for ($attempt = 0; $attempt < 5; $attempt++) {
        applyTo()->call('apply', A_CODE_NOBODY_HAS);
    }

    $card = issueCard(5000);

    applyTo()->call('apply', $card->code)->assertSet('applied', false);

    expect(PresenterLimit::actorKey())->toStartWith('session:');
});

it('lets a customer who mistyped and then got it right through', function () {
    asCustomer();

    // Four wrong, one right. A successful redemption clears the actor's counter,
    // so a customer is never locked out by their own card.
    for ($attempt = 0; $attempt < 4; $attempt++) {
        applyTo()->call('apply', A_CODE_NOBODY_HAS);
    }

    $card = issueCard(5000);

    applyTo()->call('apply', $card->code)->assertSet('applied', true);
});

it('stops an address that has spent its twenty, whoever is behind it', function () {
    for ($attempt = 0; $attempt < 20; $attempt++) {
        PresenterLimit::hitIp('127.0.0.1');
    }

    // A brand new actor with an untouched five-a-minute of their own. The
    // address bucket is the one a guesser cannot rotate by throwing away a
    // cookie.
    asCustomer();

    $card = issueCard(5000);

    applyTo()->call('apply', $card->code)->assertSet('applied', false);

    expect(GiftCardEntry::query()->where('kind', 'redeemed')->count())->toBe(0);
});

it('counts a refusal against the address and a success against nothing', function () {
    $card = issueCard(5000);

    applyTo()->call('apply', A_CODE_NOBODY_HAS);

    expect(RateLimiter::attempts(PresenterLimit::key('127.0.0.1')))->toBe(1);

    applyTo()->call('apply', $card->code)->assertSet('applied', true);

    // A success neither counts against the address nor clears it. A shared
    // address is exactly where a guesser sits behind other people's successes,
    // so one valid card must not buy back the budget for the whole building.
    expect(RateLimiter::attempts(PresenterLimit::key('127.0.0.1')))->toBe(1);
});

it('does not extend a window that is already closed', function () {
    for ($attempt = 0; $attempt < 20; $attempt++) {
        PresenterLimit::hitIp('127.0.0.1');
    }

    applyTo()->call('apply', A_CODE_NOBODY_HAS);
    applyTo()->call('apply', A_CODE_NOBODY_HAS);

    // Counting a throttled attempt turns a one-minute lockout into an unbounded
    // one for anybody who keeps trying, which is everybody.
    expect(RateLimiter::attempts(PresenterLimit::key('127.0.0.1')))->toBe(20);
});

it('spends nobody\'s attempts on an empty box', function () {
    applyTo()->call('apply', '');
    applyTo()->call('apply');

    // Pressing the button before typing is not a guess, and a shopper who does
    // it twice has not spent two of their five.
    expect(RateLimiter::attempts(PresenterLimit::key('127.0.0.1')))->toBe(0);
});

it('keys the address bucket by address, and never in the clear', function () {
    // A customer id, a session id or an IP address sitting verbatim in a shared
    // cache is a small leak into a store usually less guarded than the database.
    expect(PresenterLimit::key('203.0.113.9'))
        ->not->toContain('203.0.113.9')
        ->not->toBe(PresenterLimit::key('203.0.113.10'));
});

it('honours the limits the deployment set', function () {
    config()->set('gift-cards-livewire.ip.max_attempts', 2);

    PresenterLimit::hitIp('127.0.0.1');

    expect(PresenterLimit::exceededForIp('127.0.0.1'))->toBeFalse();

    PresenterLimit::hitIp('127.0.0.1');

    expect(PresenterLimit::exceededForIp('127.0.0.1'))->toBeTrue();
});

it('falls back to a sane limit when a deployment sets nonsense', function () {
    config()->set('gift-cards-livewire.ip.max_attempts', 'twenty');
    config()->set('gift-cards-livewire.ip.decay_seconds', null);

    // A limiter that silently does nothing is worse than none, because somebody
    // has already ticked the box.
    for ($attempt = 0; $attempt < 20; $attempt++) {
        PresenterLimit::hitIp('127.0.0.1');
    }

    expect(PresenterLimit::exceededForIp('127.0.0.1'))->toBeTrue();
});
