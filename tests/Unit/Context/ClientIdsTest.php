<?php

namespace Sfere\Tests\Unit\Context;

use PHPUnit\Framework\TestCase;
use Sfere\Context\ClientIds;

final class ClientIdsTest extends TestCase
{
    public function test_fbc_is_built_from_a_landing_fbclid(): void
    {
        $this->assertSame('fb.1.1727500000000.FB1', ClientIds::fbcFromFbclid('FB1', 1_727_500_000_000));
        $this->assertSame(1_727_500_000_000, ClientIds::fbcTime('fb.1.1727500000000.FB1'));
        $this->assertSame(0, ClientIds::fbcTime('garbage'));
    }

    public function test_gclid_is_everything_after_the_second_dot(): void
    {
        $this->assertSame('CjwK.x', ClientIds::gclidFromGclCookie('GCL.1700000000.CjwK.x'));
        $this->assertNull(ClientIds::gclidFromGclCookie('GCL.1700000000'));
        $this->assertNull(ClientIds::gclidFromGclCookie(null));
    }

    public function test_clarity_id_is_the_first_field(): void
    {
        $this->assertSame('1wokzhv', ClientIds::clarityIdFromCookie('1wokzhv^2^g9u^0^2462'));
        $this->assertSame('abc', ClientIds::clarityIdFromCookie('abc|1|2'));
        $this->assertNull(ClientIds::clarityIdFromCookie('^x'));
        $this->assertNull(ClientIds::clarityIdFromCookie(null));
    }

    public function test_every_cookie_maps_to_its_fixed_key(): void
    {
        $cookies = [
            '_fbp' => 'fbp-v', '_fbc' => 'fbc-v', '_ttp' => 'ttp-v', 'ttclid' => 'ttclid-v',
            '_scid' => 'scid-v', '_scclid' => 'scclid-v', '_epik' => 'epik-v', 'li_fat_id' => 'li-v',
            '_uetmsclkid' => 'ms-v', '__oppref' => 'opp-v', '__obref' => 'ob-v',
            '_gcl_dc' => 'GCL.1.dc-gclid', '_clck' => 'uid^1', '_clsk' => 'sid|2', 'unrelated' => 'x',
        ];

        $ids = ClientIds::fromCookies(fn (string $name): ?string => $cookies[$name] ?? null);

        $this->assertSame([
            'fbp' => 'fbp-v', 'fbc' => 'fbc-v', 'ttp' => 'ttp-v', 'ttclid' => 'ttclid-v',
            'scid' => 'scid-v', 'scclid' => 'scclid-v', 'epik' => 'epik-v', 'li_fat_id' => 'li-v',
            'msclkid' => 'ms-v', 'oppref' => 'opp-v', 'obref' => 'ob-v',
            'gclid' => 'dc-gclid', 'clarity_uid' => 'uid', 'clarity_sid' => 'sid',
        ], $ids);
    }

    public function test_gcl_aw_wins_over_gcl_dc_and_empty_values_are_skipped(): void
    {
        $cookies = ['_gcl_aw' => 'GCL.1.aw', '_gcl_dc' => 'GCL.1.dc', '_fbp' => ''];

        $this->assertSame(['gclid' => 'aw'], ClientIds::fromCookies(fn (string $name): ?string => $cookies[$name] ?? null));
    }
}
