<?php

namespace Tests\Feature;

use App\Models\Municipality;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The server-rendered pages search engines read.
 */
class PublicPagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_home_page_is_set_up_for_search(): void
    {
        $response = $this->get(route('home'));

        $response->assertOk()
            ->assertSee('<html lang="en-ZA">', false)
            ->assertSee('<title>Dispute a wrong municipal bill | '.config('app.name').'</title>', false)
            ->assertSee('<link rel="canonical" href="'.route('home').'">', false)
            ->assertSee('"@type":"FAQPage"', false)
            ->assertSeeText('Wrong municipal bill? We find the error and get it fixed.')
            ->assertSee('Estimated meter readings')
            ->assertSee(route('metro.show', 'johannesburg'), false);

        // One H1, as in the heading outline.
        $this->assertSame(1, substr_count($response->getContent(), '<h1'));
    }

    public function test_the_home_page_quotes_pay_as_you_go_prices_and_every_municipality(): void
    {
        $this->get(route('home'))
            ->assertSee('Every municipality in South Africa')
            ->assertSee('R15')
            ->assertSee('R49')
            ->assertSee('No monthly or setup fees.')
            ->assertDontSee('R1,500')
            ->assertDontSee('R2,500')
            ->assertDontSee('flat monthly fee')
            ->assertSee('Check my bill')
            ->assertDontSee('Check my bill free')
            ->assertDontSee('success fee')
            ->assertDontSee('Free to check');

        // "Free" only appears when there is welcome credit.
        config(['auditor.prices.welcome_credit' => '30']);
        $this->get(route('home'))->assertSee('Check my bill free')->assertSee('R30 of credit');
    }

    public function test_unverified_figures_stay_off_the_public_page(): void
    {
        config(['auditor.show_unverified_claims' => false]);
        $this->get(route('home'))->assertDontSee('16,000');

        config(['auditor.show_unverified_claims' => true]);
        $this->get(route('home'))->assertSee('16,000');
    }

    public function test_each_live_metro_has_its_own_page(): void
    {
        $this->get(route('metro.show', 'tshwane'))
            ->assertOk()
            ->assertSeeText('Dispute a City of Tshwane bill')
            ->assertSee('Billing queries, City of Tshwane')
            ->assertSee('I will keep paying the undisputed amount of R1,982.85')
            ->assertSee('"@type":"BreadcrumbList"', false);

        Municipality::where('slug', 'tshwane')->update(['live' => false]);
        $this->get(route('metro.show', 'tshwane'))->assertNotFound();
        $this->get(route('metro.show', 'nowhere'))->assertNotFound();
    }

    public function test_the_privacy_notice_names_who_sees_a_bill(): void
    {
        $this->get(route('privacy'))->assertOk()->assertSee('Anthropic')->assertSee('Information Regulator');
    }

    public function test_the_sitemap_lists_the_public_pages(): void
    {
        $response = $this->get(route('sitemap'));

        $response->assertOk()->assertHeader('Content-Type', 'application/xml; charset=UTF-8');
        $xml = simplexml_load_string($response->getContent());
        $locations = array_map('strval', $xml->xpath('//*[local-name()="loc"]'));

        $this->assertSame([
            route('home'),
            route('metro.show', 'johannesburg'),
            route('metro.show', 'tshwane'),
            route('metro.show', 'ethekwini'),
            route('privacy'),
        ], $locations);
    }

    public function test_robots_keeps_non_production_copies_out_of_search(): void
    {
        $this->get('/robots.txt')->assertOk()->assertSee("User-agent: *\nDisallow: /", false);

        $this->app['env'] = 'production';
        $this->get('/robots.txt')->assertSee('Disallow: /bills')->assertSee('Sitemap: '.route('sitemap'));
    }
}
