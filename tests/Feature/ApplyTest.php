<?php

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Liberu\Ecommerce\GiftCardsAndStoreCredit\Actions\DisableAccount;
use Liberu\Ecommerce\GiftCardsAndStoreCredit\Actions\RedeemByCode;
use Liberu\Ecommerce\GiftCardsAndStoreCredit\Data\RedemptionInput;
use Liberu\Ecommerce\GiftCardsAndStoreCredit\Events\GiftCardRedeemed;
use Liberu\Ecommerce\GiftCardsAndStoreCredit\Livewire\Components\ApplyGiftCard;
use Liberu\Ecommerce\GiftCardsAndStoreCredit\Livewire\GiftCardsAndStoreCreditLivewireServiceProvider as Provider;
use Liberu\Ecommerce\GiftCardsAndStoreCredit\Livewire\Support\PresenterLimit;
use Liberu\Ecommerce\GiftCardsAndStoreCredit\Livewire\Tests\Fixtures\RedeemableStub;
use Liberu\Ecommerce\GiftCardsAndStoreCredit\Models\GiftCardEntry;
use Livewire\Livewire;

/*
 * The box, and everything that has to be true for it to be one box.
 */

it('spends a card for what the server says the purchase costs', function () {
    $card = issueCard(5000);

    priced(4798);

    $applied = applyTo()->call('apply', $card->code);

    $applied->assertSet('applied', true)
        ->assertSet('replayed', false)
        // One of exactly two things a bearer credential may be shown back as.
        ->assertSet('lastFour', substr((string) $card->code, -4));

    $entry = GiftCardEntry::query()->where('kind', 'redeemed')->firstOrFail();

    // The amount is the host's, to the penny, and no browser was consulted.
    expect($entry->amount_minor)->toBe(4798)
        ->and($entry->currency)->toBe('GBP')
        // Recorded against the step, so the movement and the basket can be
        // reconciled later without either module knowing the other.
        ->and($entry->source_reference)->toBe('CHK-1');
});

it('shows the balance left on the card, folded rather than carried', function () {
    $card = issueCard(5000);

    priced(4798);

    // 5000 - 4798, by integer arithmetic, rendered by string arithmetic. A float
    // would show 2.0200000000000005 here.
    expect(applyTo()->call('apply', $card->code)->html())->toContain('GBP 2.02');
});

it('leaves the remainder on the card rather than reissuing it', function () {
    $card = issueCard(5000);

    priced(1000);

    applyTo()->call('apply', $card->code);

    // One account, one code, one credential for the customer to keep hold of.
    // The alternative — voiding the card and minting another for the change —
    // is a second bearer credential and a second thing to lose.
    expect(GiftCardEntry::query()->count())->toBe(2);
});

it('prices the purchase on the request that spends, not on the one that rendered', function () {
    $card = issueCard(5000);

    priced(1000);

    $component = applyTo();

    // The basket moved between render and submit. Nothing on the component
    // carried the old number, so the ledger is asked for the new one.
    priced(1500);

    $component->call('apply', $card->code);

    expect(GiftCardEntry::query()->where('kind', 'redeemed')->firstOrFail()->amount_minor)->toBe(1500);
});

it('answers a second submit of the same card as a replay, not a second debit', function () {
    $card = issueCard(5000);

    priced(4798);

    $component = applyTo()->call('apply', $card->code);

    // The double click. The button disables itself, but the button is the
    // courtesy — this is the guarantee.
    $component->call('apply', $card->code)->assertSet('applied', true);

    expect(GiftCardEntry::query()->where('kind', 'redeemed')->count())->toBe(1);
});

it('answers a reload and a second submit as a replay too', function () {
    $card = issueCard(5000);

    priced(4798);

    applyTo()->call('apply', $card->code);

    // **The reason the key is derived rather than drawn.** A fresh component is
    // a fresh mount with a fresh everything, and a random key here would debit
    // the card a second time. This is the case Payment Operations catches by
    // reading the ledger for the order, which this package cannot do: the ledger
    // is indexed by card and there is no card until somebody types a code.
    $reloaded = applyTo()->call('apply', $card->code);

    $reloaded->assertSet('applied', true)
        ->assertSet('replayed', true);

    expect(GiftCardEntry::query()->where('kind', 'redeemed')->count())->toBe(1)
        ->and(GiftCardEntry::query()->where('kind', 'redeemed')->firstOrFail()->amount_minor)->toBe(4798);
});

it('dispatches the host reference and nothing about the card', function () {
    $card = issueCard(5000);

    priced(4798);

    $applied = applyTo()->call('apply', $card->code);

    // The host's own reference and nothing else: enough for a checkout to
    // re-total itself, and it names no card. Anything richer than "this step is
    // done" is the domain's own `GiftCardRedeemed`, which carries the account
    // and the entry to server-side listeners only.
    $applied->assertDispatched(Provider::NAMESPACE.'.applied', reference: 'CHK-1');
});

it('replays without dispatching a second domain event', function () {
    $card = issueCard(5000);

    priced(4798);

    Event::fake([GiftCardRedeemed::class]);

    applyTo()->call('apply', $card->code);
    applyTo()->call('apply', $card->code);

    Event::assertDispatchedTimes(GiftCardRedeemed::class, 1);
});

it('refuses a different card against a step that already has one, and mints no fresh key', function () {
    $first = issueCard(5000, key: 'issue-1');
    $second = issueCard(5000, key: 'issue-2');

    priced(1000);

    applyTo()->call('apply', $first->code);

    // The Payment Operations shape, not the Checkout one. A conflict here means
    // the key already recorded a movement against a *different* card, so minting
    // a fresh key would spend a second card for one purchase. It refuses, in the
    // same sentence as every other refusal.
    $reloaded = applyTo()->call('apply', $second->code);

    $reloaded->assertSet('applied', false)
        ->assertSet('problem', theRefusal())
        // Unchanged. A fresh key is exactly what must not happen.
        ->assertSet('entryKey', ApplyGiftCard::keyFor('CHK-1'));

    expect(GiftCardEntry::query()->where('kind', 'redeemed')->count())->toBe(1);
});

it('will not call the domain again once a card is on the step', function () {
    $card = issueCard(5000, key: 'issue-1');
    $other = issueCard(5000, key: 'issue-2');

    priced(1000);

    $component = applyTo()->call('apply', $card->code);

    // The oracle this closes from in front: after a success the domain answers a
    // *valid* second code with a conflict and an invalid one with a refusal.
    // Both render identically, but a component that called the domain at all
    // would still be counting attempts differently, so it does not call it.
    $before = RateLimiter::attempts(PresenterLimit::key('127.0.0.1'));

    $component->call('apply', $other->code);
    $component->call('apply', A_CODE_NOBODY_HAS);

    expect(GiftCardEntry::query()->where('kind', 'redeemed')->count())->toBe(1)
        // Not even counted: nothing about a card was asked, so nothing about a
        // card was learned, and no attempt was spent finding that out.
        ->and(RateLimiter::attempts(PresenterLimit::key('127.0.0.1')))->toBe($before);
});

it('renders no field at all when the deployment has not said what anything costs', function () {
    config()->set('gift-cards-livewire.redeemable', null);

    $component = Livewire::test(ApplyGiftCard::class, ['reference' => 'CHK-1']);

    $component->assertSet('unconfigured', true)
        ->assertSee(__(Provider::NAMESPACE.'::gift-cards.apply.unconfigured'));

    // A box that took a bearer credential and then dropped it would be worse
    // than no box.
    expect($component->html())->not->toMatch('/<input\b/i');
});

it('answers a reference it cannot price exactly as one that was never issued', function () {
    // Nothing is registered for this reference, so it is not distinguishable
    // from somebody else's basket — which is the point. Asserted on the refusal
    // rather than on a class name: what matters is that mounting stops, not
    // which of Symfony's HTTP exceptions carries it.
    expect(fn () => applyTo('CHK-SOMEBODY-ELSES'))->toThrow(Exception::class);
});

it('refuses when the purchase stops being priceable between mount and submit', function () {
    $card = issueCard(5000);

    priced(4798);

    $component = applyTo();

    RedeemableStub::$prices = [];

    $component->call('apply', $card->code)
        ->assertSet('applied', false)
        ->assertSee(__(Provider::NAMESPACE.'::gift-cards.apply.closed'));

    expect(GiftCardEntry::query()->where('kind', 'redeemed')->count())->toBe(0);
});

it('records the movement against the source reference the host names', function () {
    $card = issueCard(5000);

    priced(1000, 'CHK-2', sourceReference: 'ORD-7788');

    applyTo('CHK-2')->call('apply', $card->code);

    expect(GiftCardEntry::query()->where('kind', 'redeemed')->firstOrFail()->source_reference)->toBe('ORD-7788');
});

it('has nothing to type for store credit, because store credit has no code', function () {
    // The whole difference between the two kinds this module holds, in one
    // assertion: a gift card is a bearer credential and store credit is not.
    // Store credit is granted to a named customer and spent because the caller
    // knows who is asking, so it appears on the balances list and never in this
    // box.
    expect(grantCredit(5000)->code)->toBeNull();
});

it('never lets a partly spent card pay for more than is left', function () {
    $card = issueCard(5000);

    // Spent elsewhere — a till, another checkout — down to 1000.
    app(RedeemByCode::class)->handle(new RedemptionInput(
        code: (string) $card->code,
        entryKey: 'spent-elsewhere',
        amount: money(4000),
        throttleKey: 'a-till',
    ));

    priced(4798);

    applyTo()->call('apply', $card->code)->assertSet('applied', false);

    // Refused, never clamped. Debiting what is there and dropping the difference
    // turns a loud failure into a basket that is short by an amount nobody is
    // told about.
    expect(GiftCardEntry::query()->where('entry_key', ApplyGiftCard::keyFor('CHK-1'))->count())->toBe(0);
});

it('disables nothing, adjusts nothing and issues nothing', function () {
    // The scope, asserted rather than described. Issuing, adjusting and stopping
    // a card are staff work with a policy behind each one, and they live in
    // `-filament`. What is here is spending a card you are holding.
    $source = '';

    foreach (everySourceFile() as $file) {
        $source .= (string) file_get_contents($file);
    }

    expect($source)
        ->not->toContain('IssueAccount')
        ->not->toContain('RecordAdjustment')
        ->not->toContain('RecordCredit')
        ->not->toContain(DisableAccount::class);
});
