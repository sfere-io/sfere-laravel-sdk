<?php

namespace Sfere\Tests\Unit\Context;

use Illuminate\Http\Request;
use PHPUnit\Framework\TestCase;
use Sfere\Context\RequestContext;

/**
 * Port of packages/node/test/unit/context.test.ts (sfere-sdk), plus Laravel request cases.
 */
final class RequestContextTest extends TestCase
{
    private const NOW = 1_727_500_000_000;

    private static function now(): \Closure
    {
        return static fn (): int => self::NOW;
    }

    public function test_it_takes_the_left_most_forwarded_for_the_user_agent_language_page_and_referrer(): void
    {
        $context = RequestContext::build(
            headers: [
                'x-forwarded-for' => '203.0.113.7, 10.0.0.2',
                'x-forwarded-proto' => 'https',
                'host' => 'shop.example.com',
                'user-agent' => 'UA/1',
                'accept-language' => 'ar-SA,ar;q=0.9,en;q=0.8',
                'referer' => 'https://www.google.com/',
            ],
            url: '/p/jersey?utm_source=google&utm_medium=cpc&utm_campaign=spring&gad_source=1',
            clientAddress: '10.0.0.2',
            now: self::now(),
        );

        $this->assertSame([
            'ip' => '203.0.113.7',
            'userAgent' => 'UA/1',
            'locale' => 'ar-SA',
            'page' => [
                'url' => 'https://shop.example.com/p/jersey?utm_source=google&utm_medium=cpc&utm_campaign=spring&gad_source=1',
                'path' => '/p/jersey',
                'search' => '?utm_source=google&utm_medium=cpc&utm_campaign=spring&gad_source=1',
                'referrer' => 'https://www.google.com/',
            ],
            'campaign' => ['source' => 'google', 'medium' => 'cpc', 'name' => 'spring', 'gad_source' => '1'],
        ], $context);
    }

    public function test_it_falls_back_to_x_real_ip_then_the_client_address_and_strips_ffff(): void
    {
        $this->assertSame('198.51.100.1', RequestContext::build(['x-real-ip' => '198.51.100.1'], null)['ip']);
        $this->assertSame('198.51.100.2', RequestContext::build([], null, '::ffff:198.51.100.2')['ip']);
        $this->assertSame('2001:db8::1', RequestContext::build([], null, '2001:db8::1')['ip']);
    }

    public function test_trust_proxy_false_ignores_forwarding_headers(): void
    {
        $context = RequestContext::build(
            headers: ['x-forwarded-for' => '6.6.6.6', 'x-forwarded-host' => 'evil.test', 'host' => 'shop.example.com'],
            url: '/',
            clientAddress: '198.51.100.9',
            trustProxy: false,
            secure: true,
        );

        $this->assertSame('198.51.100.9', $context['ip']);
        $this->assertSame('https://shop.example.com/', $context['page']['url']);
    }

    public function test_it_reads_click_ids_from_cookies_and_the_landing_url_and_fbclid_builds_fbc(): void
    {
        $context = RequestContext::build(
            headers: [
                'host' => 'shop.example.com',
                'cookie' => '_fbp=fb.1.1700000000000.111; _gcl_aw=GCL.1700000000.CjwK.x; _clck=1wokzhv%5E2%5Eg9u; '
                    .'_uetmsclkid="_uetabc"; unrelated=1',
            ],
            url: '/?ttclid=T1&ScCid=S1&fbclid=FB1',
            now: self::now(),
        );

        $this->assertSame([
            'fbp' => 'fb.1.1700000000000.111',
            'msclkid' => '_uetabc',
            'gclid' => 'CjwK.x',
            'clarity_uid' => '1wokzhv',
            'ttclid' => 'T1',
            'scclid' => 'S1',
            'fbc' => 'fb.1.'.self::NOW.'.FB1',
        ], $context['clientIds']);
    }

    public function test_it_keeps_an_fbc_cookie_that_already_records_the_landing_fbclid(): void
    {
        $context = RequestContext::build(
            headers: ['host' => 'a.test', 'cookie' => '_fbc=fb.1.1600000000000.FB1'],
            url: '/?fbclid=FB1',
            now: self::now(),
        );

        $this->assertSame('fb.1.1600000000000.FB1', $context['clientIds']['fbc']);
    }

    public function test_page_url_overrides_the_request_url_and_without_it_landing_params_come_from_the_referrer(): void
    {
        $headers = ['host' => 'api.test', 'referer' => 'https://shop.test/l?gclid=R1'];

        $this->assertSame(['gclid' => 'P1'], RequestContext::build($headers, '/api/order', pageUrl: 'https://shop.test/thanks?gclid=P1')['clientIds']);
        $this->assertSame(['gclid' => 'R1'], RequestContext::build($headers, '/api/order')['clientIds']);
    }

    public function test_it_returns_an_empty_array_for_a_bare_request(): void
    {
        $this->assertSame([], RequestContext::build([], null));
    }

    public function test_it_joins_repeated_headers_and_survives_a_malformed_host(): void
    {
        $context = RequestContext::build(
            headers: ['x-forwarded-for' => ['203.0.113.8', '10.0.0.1'], 'host' => 'bad host'],
            url: '/',
        );

        $this->assertSame('203.0.113.8', $context['ip']);
        $this->assertArrayNotHasKey('page', $context);
    }

    public function test_it_keeps_raw_cookie_values_that_are_not_valid_percent_encoding(): void
    {
        $this->assertSame(['ttp' => '100%zz'], RequestContext::build(['cookie' => '_ttp=100%zz'], null)['clientIds']);
    }

    public function test_it_reads_an_absolute_request_url(): void
    {
        $context = RequestContext::build(
            headers: ['X-Forwarded-For' => '203.0.113.9', 'User-Agent' => 'UA/2', 'Cookie' => '_ttp=ttp-2'],
            url: 'https://shop.example.com/api/checkout?msclkid=M1',
        );

        $this->assertSame([
            'ip' => '203.0.113.9',
            'userAgent' => 'UA/2',
            'page' => [
                'url' => 'https://shop.example.com/api/checkout?msclkid=M1',
                'path' => '/api/checkout',
                'search' => '?msclkid=M1',
            ],
            'clientIds' => ['ttp' => 'ttp-2', 'msclkid' => 'M1'],
        ], $context);
    }

    public function test_a_page_without_a_query_has_an_empty_search_and_wildcard_locale_is_skipped(): void
    {
        $context = RequestContext::build(['host' => 'Shop.Example.com:443', 'accept-language' => '*'], '/thanks', secure: true);

        $this->assertSame(['page' => ['url' => 'https://shop.example.com/thanks', 'path' => '/thanks', 'search' => '']], $context);
    }

    public function test_from_request_reads_a_laravel_request(): void
    {
        $request = Request::create('https://shop.example.com/checkout?utm_source=ig&gclid=G1', 'GET', server: [
            'REMOTE_ADDR' => '10.0.0.2',
            'HTTP_X_FORWARDED_FOR' => '203.0.113.7, 10.0.0.2',
            'HTTP_USER_AGENT' => 'UA/3',
            'HTTP_COOKIE' => '_fbp=fb.1.1.222; ajs_anonymous_id=%22anon-1%22',
        ]);

        $this->assertSame([
            'ip' => '203.0.113.7',
            'userAgent' => 'UA/3',
            'locale' => 'en-us', // Request::create's default Accept-Language
            'page' => [
                'url' => 'https://shop.example.com/checkout?utm_source=ig&gclid=G1',
                'path' => '/checkout',
                'search' => '?utm_source=ig&gclid=G1',
            ],
            'campaign' => ['source' => 'ig'],
            'clientIds' => ['fbp' => 'fb.1.1.222', 'gclid' => 'G1'],
        ], RequestContext::fromRequest($request));
    }

    public function test_from_request_without_trust_proxy_uses_the_request_ip(): void
    {
        $request = Request::create('http://shop.example.com/', 'GET', server: [
            'REMOTE_ADDR' => '198.51.100.9',
            'HTTP_X_FORWARDED_FOR' => '6.6.6.6',
            'HTTP_X_FORWARDED_HOST' => 'evil.test',
        ]);

        $context = RequestContext::fromRequest($request, trustProxy: false);

        $this->assertSame('198.51.100.9', $context['ip']);
        $this->assertSame('http://shop.example.com/', $context['page']['url']);
    }

    public function test_from_request_falls_back_to_the_cookie_bag_without_a_cookie_header(): void
    {
        $request = Request::create('http://shop.example.com/', 'GET', cookies: ['_ttp' => 'bag-ttp']);

        $this->assertSame(['ttp' => 'bag-ttp'], RequestContext::fromRequest($request)['clientIds']);
    }

    public function test_anonymous_id_is_read_from_the_raw_cookie_and_unwrapped(): void
    {
        $quoted = Request::create('/', 'GET', server: ['HTTP_COOKIE' => 'ajs_anonymous_id=%22anon-1%22']);
        $plain = Request::create('/', 'GET', server: ['HTTP_COOKIE' => 'ajs_anonymous_id=anon-2']);
        $literal = Request::create('/', 'GET', server: ['HTTP_COOKIE' => 'ajs_anonymous_id="anon-3"']);
        $empty = Request::create('/', 'GET', server: ['HTTP_COOKIE' => 'ajs_anonymous_id=%22%22']);
        $none = Request::create('/', 'GET');

        $this->assertSame('anon-1', RequestContext::anonymousId($quoted, 'ajs_anonymous_id'));
        $this->assertSame('anon-2', RequestContext::anonymousId($plain, 'ajs_anonymous_id'));
        $this->assertSame('anon-3', RequestContext::anonymousId($literal, 'ajs_anonymous_id'));
        $this->assertNull(RequestContext::anonymousId($empty, 'ajs_anonymous_id'));
        $this->assertNull(RequestContext::anonymousId($none, 'ajs_anonymous_id'));
        $this->assertSame('x', RequestContext::anonymousId(Request::create('/', 'GET', server: ['HTTP_COOKIE' => 'my_anon=x']), 'my_anon'));
    }

    public function test_parse_cookies_keeps_the_first_occurrence(): void
    {
        $this->assertSame(['a' => '1', 'b' => 'two words'], RequestContext::parseCookies('a=1; a=2; =x; junk; b=two%20words'));
        $this->assertSame([], RequestContext::parseCookies(null));
    }
}
