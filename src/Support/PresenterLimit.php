<?php

namespace Liberu\Ecommerce\GiftCardsAndStoreCredit\Livewire\Support;

use Illuminate\Support\Facades\RateLimiter;

/**
 * **Who is presenting a code, and how often they may.**
 *
 * The domain refuses an empty `throttleKey` and will not invent one, because it
 * cannot see a request. This is the package that can, so this is where the
 * question is answered — twice, because either answer alone is a limiter
 * somebody walks around.
 *
 * | Bucket | Keyed by | Enforced by | Default |
 * | --- | --- | --- | --- |
 * | Actor | the signed-in customer, or the guest's session | the **domain**, from the key this class builds | 5 per minute |
 * | Address | `request()->ip()` | **here** | 20 per minute |
 *
 * The actor bucket is not reimplemented here. `RedeemByCode` already limits on
 * whatever key it is handed, and a second counter over the same thing would be
 * two answers to one question — the arrangement that put a mutable `balance`
 * column next to a ledger. What this class adds is the bucket the domain
 * *cannot* key, because a session id is a request-scoped fact and an address is
 * a request-scoped fact and the domain has neither.
 *
 * ### Why an address bucket at all
 *
 * A per-session limit is five attempts per cookie jar, and a script mints
 * cookie jars faster than it guesses codes. An address is not free to rotate.
 * It is also not free to *share* — an office, a school and a mobile carrier are
 * each one address — so the address limit is deliberately the looser of the
 * two. A limit that locks out a building is a limit an operator switches off.
 *
 * ### The keys are hashed
 *
 * A customer id, a session id or an IP address sitting verbatim in a shared
 * cache is a small leak into a store that is usually less guarded than the
 * database, and one that the domain's own `AttemptLimit` already refuses to
 * make. This matches it.
 */
final class PresenterLimit
{
    /**
     * The key the domain throttles on: the person, not the browser tab.
     *
     * A signed-in customer keeps one counter across every device they own,
     * which is the honest reading of "per presenter" — an attacker who signs in
     * does not get a fresh five per tab. A guest gets their session, which is
     * the strongest handle there is on somebody who has not told us who they
     * are, and the address bucket is what covers a guest who throws sessions
     * away.
     *
     * Never empty: `RedeemByCode` refuses an empty key outright, and an empty
     * one here would mean every guest in the world sharing a counter.
     */
    public static function actorKey(): string
    {
        $id = auth()->id();

        if (is_int($id) || (is_string($id) && $id !== '')) {
            return 'user:'.$id;
        }

        $session = session()->getId();

        return $session === '' ? 'anonymous' : 'session:'.$session;
    }

    /** Whether this address has spent its attempts for the window. */
    public static function exceededForIp(string $ip): bool
    {
        return RateLimiter::tooManyAttempts(self::key($ip), self::maxAttempts());
    }

    /**
     * Count one refused attempt against an address.
     *
     * Refusals only, and never the attempt that was already being throttled:
     * extending somebody's window every time they ask turns a one-minute
     * lockout into an unbounded one. That is the domain's rule for the actor
     * bucket and this is the same rule one bucket over.
     *
     * A **successful** redemption does not clear this counter, unlike the
     * actor's. A shared address is exactly where a guesser sits behind other
     * people's successes, and one valid card should not buy back the budget for
     * the whole building.
     */
    public static function hitIp(string $ip): void
    {
        RateLimiter::hit(self::key($ip), self::decaySeconds());
    }

    public static function key(string $ip): string
    {
        return 'ecommerce-gift-cards-livewire:apply:ip:'.hash('sha256', $ip);
    }

    private static function maxAttempts(): int
    {
        $max = config('gift-cards-livewire.ip.max_attempts');

        return is_numeric($max) ? max(1, (int) $max) : 20;
    }

    private static function decaySeconds(): int
    {
        $decay = config('gift-cards-livewire.ip.decay_seconds');

        return is_numeric($decay) ? max(1, (int) $decay) : 60;
    }
}
