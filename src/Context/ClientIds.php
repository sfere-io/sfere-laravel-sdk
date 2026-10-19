<?php

namespace Sfere\Context;

use Closure;

/**
 * Identifier rules every Sfere SDK applies the same way (WIRE-CONTRACT §4); a port of
 * packages/shared/src/index.ts in sfere-sdk. The output key names are fixed by the conversion
 * destinations that read `context.clientIds`.
 */
final class ClientIds
{
    /** First-party cookie name -> `context.clientIds` key. */
    public const COOKIE_CLIENT_IDS = [
        '_fbp' => 'fbp',
        '_fbc' => 'fbc',
        '_ttp' => 'ttp',
        'ttclid' => 'ttclid',
        '_scid' => 'scid',
        '_scclid' => 'scclid',
        '_epik' => 'epik',
        'li_fat_id' => 'li_fat_id',
        '_uetmsclkid' => 'msclkid',
        '__oppref' => 'oppref',
        // OpenAI pixel browser id; the OpenAI CAPI sends it as `user.obref`.
        '__obref' => 'obref',
    ];

    /** Landing URL parameter -> `context.clientIds` key. `fbclid` is separate: it becomes `fbc`. */
    public const URL_CLICK_IDS = [
        'gclid' => 'gclid',
        'wbraid' => 'wbraid',
        'gbraid' => 'gbraid',
        'ttclid' => 'ttclid',
        'ScCid' => 'scclid',
        'twclid' => 'twclid',
        'epik' => 'epik',
        'msclkid' => 'msclkid',
        'li_fat_id' => 'li_fat_id',
        'oppref' => 'oppref',
        'rdt_cid' => 'rdt_cid',
    ];

    /** Google Ads landing parameters merged into `context.campaign`. */
    public const CAMPAIGN_PARAMS = ['gad_source', 'gad_campaignid'];

    /**
     * Meta's documented click-ID cookie format: `fb.<subdomainIndex>.<creationTimeMs>.<fbclid>`.
     */
    public static function fbcFromFbclid(string $fbclid, int $nowMs): string
    {
        return "fb.1.{$nowMs}.{$fbclid}";
    }

    /**
     * Creation time (ms) inside an fbc value; 0 when it cannot be read.
     */
    public static function fbcTime(string $fbc): int
    {
        $part = explode('.', $fbc)[2] ?? '';

        return ctype_digit($part) ? (int) $part : 0;
    }

    /**
     * `_gcl_aw` / `_gcl_dc` look like `GCL.<ts>.<gclid>`: the gclid is everything after the 2nd dot.
     */
    public static function gclidFromGclCookie(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $parts = explode('.', $value);

        return count($parts) >= 3 ? implode('.', array_slice($parts, 2)) : null;
    }

    /**
     * Clarity's `_clck` (user) / `_clsk` (session): the ID is the first field. Current Clarity
     * separates fields with `^` (stored URL-encoded as `%5E`); `|` is also accepted.
     */
    public static function clarityIdFromCookie(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $id = preg_split('/[\^|]/', $value)[0] ?? '';

        return $id === '' ? null : $id;
    }

    /**
     * Every cookie-derived `context.clientIds` entry, given a way to read one (decoded) cookie.
     *
     * @param  Closure(string): ?string  $readCookie  returns null for a missing or empty cookie
     * @return array<string, string>
     */
    public static function fromCookies(Closure $readCookie): array
    {
        $ids = [];
        foreach (self::COOKIE_CLIENT_IDS as $cookie => $key) {
            $value = $readCookie($cookie);
            if ($value !== null && $value !== '') {
                $ids[$key] = $value;
            }
        }

        $gclid = self::gclidFromGclCookie(self::nonEmpty($readCookie('_gcl_aw')) ?? self::nonEmpty($readCookie('_gcl_dc')));
        if ($gclid !== null && $gclid !== '') {
            $ids['gclid'] = $gclid;
        }
        $clarityUid = self::clarityIdFromCookie(self::nonEmpty($readCookie('_clck')));
        if ($clarityUid !== null) {
            $ids['clarity_uid'] = $clarityUid;
        }
        $claritySid = self::clarityIdFromCookie(self::nonEmpty($readCookie('_clsk')));
        if ($claritySid !== null) {
            $ids['clarity_sid'] = $claritySid;
        }

        return $ids;
    }

    private static function nonEmpty(?string $value): ?string
    {
        return $value === null || $value === '' ? null : $value;
    }

    private function __construct() {}
}
