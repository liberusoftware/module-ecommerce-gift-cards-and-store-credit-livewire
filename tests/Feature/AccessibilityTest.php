<?php

use Liberu\Ecommerce\GiftCardsAndStoreCredit\Livewire\Components\MyBalances;
use Liberu\Ecommerce\GiftCardsAndStoreCredit\Livewire\GiftCardsAndStoreCreditLivewireServiceProvider as Provider;
use Livewire\Livewire;

/*
 * A button that dims and a panel that swaps are both invisible to a screen
 * reader, and on this surface the thing that swapped is whether somebody's gift
 * card has been spent.
 */

it('labels the one field it renders', function () {
    priced();

    // A placeholder is not a label: it disappears on the first keystroke and
    // screen readers are not obliged to read it.
    expectEveryFieldToBeLabelled(applyTo()->html());
});

it('describes the field, because a code is read off a piece of card', function () {
    priced();

    $html = applyTo()->html();

    expect($html)
        ->toContain('aria-describedby')
        ->toContain(__(Provider::NAMESPACE.'::gift-cards.apply.hint'));
});

it('carries both live regions from the first render, before either has anything to say', function () {
    priced();

    // A live region inserted at the same moment as its content is not announced
    // by every screen reader, so both are on the page from the start: an alert
    // for a refusal, which interrupts, and a status for an outcome, which waits
    // its turn.
    expect(applyTo()->html())
        ->toContain('role="alert"')
        ->toContain('role="status"')
        ->toContain('aria-live="polite"');
});

it('says every outcome in words, not only in a panel that swapped', function () {
    $card = issueCard(5000);

    priced(4798);

    expect(applyTo()->call('apply', $card->code)->get('announcement'))
        ->toContain('GBP 47.98')
        ->toContain(substr((string) $card->code, -4));
});

it('says every refusal in words too', function () {
    priced(4798);

    expect(applyTo()->call('apply', A_CODE_NOBODY_HAS)->get('announcement'))->toBe(theRefusal());
});

it('announces a change once rather than on every render after it', function () {
    $card = issueCard(5000);

    priced(4798);

    $applied = applyTo()->call('apply', $card->code);

    expect($applied->get('announcement'))->not->toBe('');

    // A live region announces *changes*. Carrying the sentence into the next
    // request would either say nothing new or say it again at the wrong moment.
    expect($applied->refresh()->get('announcement'))->toBe('');
});

it('gives every panel a heading', function () {
    priced();

    asCustomer();

    expect(applyTo()->html())->toContain('<h2>')
        ->and(Livewire::test(MyBalances::class)->html())->toContain('<h2>');
});

it('reaches the submit control by keyboard, because it is a real button', function () {
    priced();

    $html = applyTo()->html();

    // A `div` with a click handler is not reachable by tab, not announced as a
    // control and not activated by space. This is a `type="submit"` inside a
    // form, so Enter in the field works as well.
    expect($html)->toMatch('/<button\b[^>]*type="submit"/');
});
