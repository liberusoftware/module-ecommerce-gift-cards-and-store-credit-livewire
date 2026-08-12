<?php

use Liberu\Ecommerce\GiftCardsAndStoreCredit\Actions\DisableAccount;
use Liberu\Ecommerce\GiftCardsAndStoreCredit\Livewire\Components\MyBalances;
use Liberu\Ecommerce\GiftCardsAndStoreCredit\Livewire\GiftCardsAndStoreCreditLivewireServiceProvider as Provider;
use Liberu\Ecommerce\GiftCardsAndStoreCredit\Livewire\Tests\Fixtures\IdStub;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

/*
 * What a customer has, answered from `customer_id` to somebody who has proved
 * they are that customer — never from a code. This component is the reason
 * there is no *check your balance* box: the useful half of that feature is here
 * behind a login, and the half that is left is an oracle.
 */

beforeEach(function (): void {
    IdStub::$answer = null;
});

function balances(): Testable
{
    return Livewire::test(MyBalances::class);
}

it('shows a customer their own balances, folded from the ledger', function () {
    $customer = asCustomer();

    issueCard(5000, customerId: (int) $customer->id);
    grantCredit(1250, customerId: (int) $customer->id);

    $html = balances()->html();

    // Both kinds, both folded. 5000 and 1250 by integer arithmetic, rendered by
    // string arithmetic — a float would show 12.500000000000002 here.
    expect($html)
        ->toContain('GBP 50.00')
        ->toContain('GBP 12.50')
        ->toContain(__(Provider::NAMESPACE.'::gift-cards.kind.gift_card'))
        ->toContain(__(Provider::NAMESPACE.'::gift-cards.kind.store_credit'));
});

it('shows a card\'s last four and nothing else about its code', function () {
    $customer = asCustomer();

    $card = issueCard(5000, customerId: (int) $customer->id);

    $code = (string) $card->code;

    expect(balances()->html())
        ->toContain(substr($code, -4))
        ->not->toContain($code)
        ->not->toContain(str_replace('-', '', $code));
});

it('shows nobody somebody else\'s balances', function () {
    asCustomer();

    // Issued to a different person entirely.
    issueCard(5000, customerId: GHOST_CUSTOMER);

    expect(balances()->html())->toContain(__(Provider::NAMESPACE.'::gift-cards.balances.empty'));
});

it('shows a guest nothing at all', function () {
    issueCard(5000, customerId: GHOST_CUSTOMER);

    // Nobody sees nothing. Silent and deliberate, which is the safe direction.
    expect(balances()->html())->toContain(__(Provider::NAMESPACE.'::gift-cards.balances.empty'));
});

it('shows nothing to a host whose customer ids are not whole numbers', function () {
    config()->set('gift-cards-livewire.viewer', IdStub::class);

    IdStub::$answer = '01JQ8Z6X0K7N4B2C';

    issueCard(5000, customerId: GHOST_CUSTOMER);

    // Not `(int) $id`. A ULID casts to 0, and 0 is somebody's row on a database
    // that starts its sequences there — which here would be one customer shown
    // another's balances. Failing closed is silent, and `docs/runbook.md` names
    // it as the first thing to check when a list is unexpectedly empty.
    expect(balances()->html())->toContain(__(Provider::NAMESPACE.'::gift-cards.balances.empty'));
});

it('takes a customer id a host answers with as a numeric string', function () {
    config()->set('gift-cards-livewire.viewer', IdStub::class);

    IdStub::$answer = (string) GHOST_CUSTOMER;

    issueCard(5000, customerId: GHOST_CUSTOMER);

    expect(balances()->html())->toContain('GBP 50.00');
});

it('filters to one team when the deployment names one', function () {
    config()->set('gift-cards-livewire.team', IdStub::class);

    IdStub::$answer = GHOST_OTHER_TEAM;

    issueCard(5000, customerId: GHOST_CUSTOMER, teamId: GHOST_TEAM);

    asCustomer(GHOST_CUSTOMER);

    // The customer's own card, sold by another of the merchant's storefronts. Not
    // a leak — they are the customer on that row — but one brand showing
    // another brand's card, which is why the hook exists.
    expect(balances()->html())->toContain(__(Provider::NAMESPACE.'::gift-cards.balances.empty'));
});

it('still shows the balance on a card that has passed its date', function () {
    $customer = asCustomer();

    issueCard(5000, expiresAt: now()->subDay()->toIso8601String(), customerId: (int) $customer->id);

    // **The single most important line in the domain's expiry decision, rendered
    // as a sentence a customer reads.** Expiry ends redeemability, never the
    // money: nothing is zeroed, no entry is written, and the balance is the same
    // number it was the day before.
    expect(balances()->html())
        ->toContain('GBP 50.00')
        ->toContain(__(Provider::NAMESPACE.'::gift-cards.balances.expired'))
        ->toContain(__(Provider::NAMESPACE.'::gift-cards.status.expired'));
});

it('says plainly when a card has been stopped', function () {
    $customer = asCustomer();

    $card = issueCard(5000, customerId: (int) $customer->id);

    app(DisableAccount::class)->handle($card->account->reference, 'stopped-1', 'reported_lost');

    expect(balances()->html())
        ->toContain(__(Provider::NAMESPACE.'::gift-cards.balances.disabled'))
        ->toContain(__(Provider::NAMESPACE.'::gift-cards.status.disabled'));
});

it('offers no way to look a balance up by code', function () {
    asCustomer();

    $html = balances()->html();

    // The absence is the assertion. A balance box is a question whose answer —
    // *is this code real, and what is on it* — is only useful to somebody who
    // does not already have the card, and building one would mean a second
    // lookup over the code space with its own throttle, its own timing profile
    // and its own refusals to keep identical.
    expect($html)
        ->not->toMatch('/<(?:input|select|textarea|form)\b/i')
        ->toContain(__(Provider::NAMESPACE.'::gift-cards.balances.no_lookup'));
});

it('offers no way to change anything either', function () {
    // Nothing a shopper can do to a ledger is legitimate: the balance is a fold,
    // the expiry is written once, and stopping a card is terminal and staff
    // work. A component with no action at all is the cheapest way to say so.
    $class = new ReflectionClass(MyBalances::class);

    // Filtered by file rather than by declaring class: a method a class picks up
    // from a trait reports the *using* class as its declarer, so the shared
    // concern's helpers would otherwise look like this component's own surface.
    $own = array_values(array_map(
        fn (ReflectionMethod $method): string => $method->getName(),
        array_filter(
            $class->getMethods(ReflectionMethod::IS_PUBLIC),
            fn (ReflectionMethod $method): bool => $method->getFileName() === $class->getFileName(),
        ),
    ));

    sort($own);

    expect($own)->toBe(['accounts', 'render']);
});
