<?php

namespace Sfere\Tests\Feature\Middleware;

use Illuminate\Foundation\Auth\User;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use RuntimeException;
use Sfere\Facades\Sfere;
use Sfere\Http\Middleware\TrackPageViews;
use Sfere\Support\Settings;
use Sfere\Tests\TestCase;
use Symfony\Component\HttpFoundation\Response;

final class TrackPageViewsTest extends TestCase
{
    protected function defineRoutes($router): void
    {
        /** @var Router $router */
        $router->middleware(TrackPageViews::class)->group(function (Router $router): void {
            $router->get('/pricing', fn () => response('<html><body>Pricing</body></html>'));
            $router->post('/pricing', fn () => response('<html></html>'));
            $router->get('/api/data', fn () => response()->json(['ok' => true]));
            $router->get('/horizon/dashboard', fn () => response('<html></html>'));
            $router->get('/missing', fn () => response('<html>Not found</html>', 404));
            $router->get('/redirect', fn () => redirect('/pricing'));
        });
    }

    public function test_it_sends_a_page_event_for_an_html_get(): void
    {
        Sfere::fake();

        $this->actingAs($this->user())
            ->get('/pricing?utm_source=newsletter', ['Referer' => 'https://google.com/'])
            ->assertOk();

        Sfere::assertSentCount(1);
        Sfere::assertPaged(null, function (array $event): bool {
            return $event['userId'] === '42'
                && $event['properties']['path'] === '/pricing'
                && $event['properties']['search'] === '?utm_source=newsletter'
                && $event['properties']['referrer'] === 'https://google.com/'
                && str_starts_with($event['properties']['url'], 'http://localhost/pricing');
        });
    }

    public function test_it_skips_non_html_non_get_excluded_and_unsuccessful_responses(): void
    {
        Sfere::fake();
        $this->actingAs($this->user());

        $this->get('/api/data')->assertOk();
        $this->post('/pricing')->assertOk();
        $this->get('/horizon/dashboard')->assertOk();
        $this->get('/missing')->assertNotFound();
        $this->get('/redirect')->assertRedirect();

        Sfere::assertNothingSent();
    }

    public function test_it_skips_visitors_it_cannot_attribute(): void
    {
        Sfere::fake();

        $this->get('/pricing')->assertOk();

        Sfere::assertNothingSent();
    }

    public function test_a_failing_client_never_breaks_the_page(): void
    {
        $fake = Sfere::fake();
        $this->app->bind(TrackPageViews::class, fn () => new class($fake, $this->app->make(Settings::class)) extends TrackPageViews
        {
            protected function shouldTrack(Request $request, Response $response): bool
            {
                throw new RuntimeException('boom');
            }
        });

        $this->actingAs($this->user())->get('/pricing')->assertOk()->assertSee('Pricing');
        Sfere::assertNothingSent();
    }

    private function user(): User
    {
        $user = new User;
        $user->forceFill(['id' => 42]);

        return $user;
    }
}
