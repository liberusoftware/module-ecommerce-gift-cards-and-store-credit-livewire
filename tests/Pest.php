<?php

use Liberu\Ecommerce\GiftCardsAndStoreCredit\Actions\IssueAccount;
use Liberu\Ecommerce\GiftCardsAndStoreCredit\Data\IssueInput;
use Liberu\Ecommerce\GiftCardsAndStoreCredit\Data\IssueResult;
use Liberu\Ecommerce\GiftCardsAndStoreCredit\Data\Money;
use Liberu\Ecommerce\GiftCardsAndStoreCredit\Enums\AccountKind;
use Liberu\Ecommerce\GiftCardsAndStoreCredit\Livewire\Components\ApplyGiftCard;
use Liberu\Ecommerce\GiftCardsAndStoreCredit\Livewire\Components\MyBalances;
use Liberu\Ecommerce\GiftCardsAndStoreCredit\Livewire\Data\Redeemable;
use Liberu\Ecommerce\GiftCardsAndStoreCredit\Livewire\GiftCardsAndStoreCreditLivewireServiceProvider as Provider;
use Liberu\Ecommerce\GiftCardsAndStoreCredit\Livewire\Tests\Fixtures\RedeemableStub;
use Liberu\PackageTestbench\PackageTestCase;
use Liberu\PackageTestbench\TestUser;
use Liberu\PackageTestbench\UsesTestUser;
use Livewire\Component;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

/*
 * `UsesTestUser` for the `users` table — the balances list is scoped to a person
 * and the default way of finding them is `auth()->id()` — and for the
 * `RefreshDatabase` it brings, without which the domain's two tables do not
 * exist.
 *
 * The apply control is not scoped to a person: a guest pays with a gift card,
 * and that is the composition a storefront is actually in. Its tests sign
 * nobody in unless the point is the actor throttle key.
 */
/*
 * The setup hangs off `uses()->beforeEach()` rather than a bare `beforeEach()`.
 * `Pest.php` is a bootstrap file with no tests in it, so a top-level
 * `beforeEach()` here binds to nothing and runs never — which looks exactly like
 * a package that is simply unconfigured.
 */
uses(PackageTestCase::class, UsesTestUser::class)
    ->beforeEach(function (): void {
        RedeemableStub::$prices = [];

        config()->set('gift-cards-livewire.redeemable', RedeemableStub::class);
        config()->set('gift-cards-livewire.viewer', null);
        config()->set('gift-cards-livewire.team', null);

        // The pepper has no default and `Code::pepper()` throws without one, on
        // purpose: a package that quietly kept working with no pepper would be a
        // package whose central guarantee had been switched off.
        config()->set('gift-cards.code_pepper', TEST_PEPPER);

        // Bcrypt at its lowest cost. The *uniformity* of that cost is the timing
        // control, not its size, and a suite paying production cost per refusal
        // takes minutes.
        config()->set('gift-cards.code_hash_cost', 4);
    })
    ->in(__DIR__);

const TEST_PEPPER = 'a-pepper-that-lives-in-the-environment-and-not-in-a-row';

const GHOST_CUSTOMER = 9_000_055;
const GHOST_TEAM = 9_000_007;
const GHOST_OTHER_TEAM = 9_000_008;

/**
 * A code that is the right shape and belongs to no card.
 *
 * Twenty Crockford characters, so it passes `Code::isWellFormed()` and is
 * refused for being unknown rather than for being malformed — which is the case
 * a guesser is actually in.
 */
const A_CODE_NOBODY_HAS = 'ZZZZZZZZZZZZZZZZZZZZ';

/** The one sentence every refusal on this surface answers with. */
function theRefusal(): string
{
    return __(Provider::NAMESPACE.'::gift-cards.apply.refused');
}

/** @return list<class-string<Component>> */
function everyComponent(): array
{
    return [ApplyGiftCard::class, MyBalances::class];
}

/** Every PHP file this package ships in `src/`. */
function everySourceFile(): Generator
{
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__.'/../src')) as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            yield $file->getPathname();
        }
    }
}

/**
 * A reference the host prices at something.
 *
 * `4798` deliberately: two of a 19.99 thing plus 20% tax, the same figure the
 * checkout and payment packages use, so a decimal rendered by float arithmetic
 * would look wrong rather than round cleanly.
 */
function priced(int $minor = 4798, string $reference = 'CHK-1', string $currency = 'GBP', ?string $sourceReference = null): string
{
    RedeemableStub::$prices[$reference] = new Redeemable(
        amount: new Money($minor, $currency),
        sourceReference: $sourceReference,
    );

    return $reference;
}

/** The domain's value type, for the handful of tests that drive it directly. */
function money(int $minor, string $currency = 'GBP'): Money
{
    return new Money($minor, $currency);
}

/** The apply control, mounted on a priced reference. */
function applyTo(string $reference = 'CHK-1'): Testable
{
    return Livewire::test(ApplyGiftCard::class, ['reference' => $reference]);
}

/**
 * A gift card, and the one and only time its code exists.
 *
 * `IssueResult::$code` is returned by the call that minted it and by nothing
 * else, ever. A replayed issue returns null — not as a policy, but because the
 * module could not produce it.
 */
function issueCard(int $minor = 5000, string $currency = 'GBP', ?string $expiresAt = null, string $key = 'issue-1', ?int $customerId = GHOST_CUSTOMER, ?int $teamId = GHOST_TEAM): IssueResult
{
    return app(IssueAccount::class)->handle(new IssueInput(
        kind: AccountKind::GiftCard,
        issueKey: $key,
        amount: new Money($minor, $currency),
        customerId: $customerId,
        teamId: $teamId,
        expiresAt: $expiresAt,
    ));
}

/** Store credit: the same ledger, granted to a named customer, with no code. */
function grantCredit(int $minor = 5000, string $key = 'grant-1', ?int $customerId = GHOST_CUSTOMER, ?int $teamId = GHOST_TEAM): IssueResult
{
    return app(IssueAccount::class)->handle(new IssueInput(
        kind: AccountKind::StoreCredit,
        issueKey: $key,
        amount: new Money($minor, 'GBP'),
        customerId: $customerId,
        teamId: $teamId,
    ));
}

/** Somebody signed in, whose id is what `customer_id` is compared against. */
function asCustomer(?int $customerId = null): TestUser
{
    $user = TestUser::factory()->create($customerId === null ? [] : ['id' => $customerId]);

    test()->actingAs($user);

    return $user;
}

/**
 * Everything a browser gets back about this component, in one array.
 *
 * Public properties are exactly what Livewire dehydrates into the snapshot it
 * sends to the page, so this is the payload — and comparing two of them is how
 * "one shape" is asserted rather than asserted about.
 *
 * @param  class-string<Component>  $component
 * @return array<string, mixed>
 */
function snapshotOf(Testable $rendered, string $component = ApplyGiftCard::class): array
{
    $state = [];

    foreach (new ReflectionClass($component)->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
        if (! $property->isStatic()) {
            $state[$property->getName()] = $rendered->get($property->getName());
        }
    }

    return $state;
}

/**
 * Every field a component renders has a real label pointing at it.
 *
 * A placeholder is not a label: it disappears on the first keystroke and screen
 * readers are not obliged to read it. This walks the rendered markup rather than
 * trusting a per-view assertion, so a field added later without a label fails
 * here rather than never.
 */
function expectEveryFieldToBeLabelled(string $html): void
{
    preg_match_all('/<(?:input|select|textarea)\b[^>]*>/i', $html, $fields);

    foreach ($fields[0] as $field) {
        expect($field)->toMatch('/\sid="[^"]+"/');

        preg_match('/\sid="([^"]+)"/', $field, $id);

        expect($html)->toContain('for="'.$id[1].'"');
    }
}
