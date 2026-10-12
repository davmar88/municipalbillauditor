<?php

namespace App\Http\Controllers\Staff;

use App\Audit\Enums\Service;
use App\Audit\Enums\TariffCategory;
use App\Http\Controllers\Controller;
use App\Models\Municipality;
use App\Models\Outage;
use App\Models\TariffRate;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * What the checks rely on and staff keep up to date: each metro's dispute
 * rules, reported outages, and tariff rates.
 */
class ReferenceDataController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('staff/reference', [
            'municipalities' => Municipality::query()->withCount('properties')->orderBy('sort')->orderBy('name')->get()->map(fn (Municipality $m) => [
                'id' => $m->id,
                'code' => $m->code,
                'name' => $m->name,
                'shortName' => $m->short_name,
                'province' => $m->province,
                'category' => $m->category,
                'aliases' => $m->aliases ?? [],
                'propertyCount' => $m->properties_count,
                'billingAddressee' => $m->billing_addressee,
                'billingEmail' => $m->billing_email,
                'billingEmailSource' => $m->billing_email_source,
                'billingEmailVerified' => $m->billing_email_verified_at !== null,
                'escalationEmail' => $m->escalation_email,
                'escalationEmailVerified' => $m->escalation_email_verified_at !== null,
                'disputeWindowDays' => $m->dispute_window_days,
                'replyWindowDays' => $m->reply_window_days,
                'councillorWindowDays' => $m->councillor_window_days,
                'rulesVerified' => $m->rules_verified,
                'rulesNotes' => $m->rules_notes,
                'letterParagraph' => $m->letter_paragraph,
                'live' => $m->live,
            ])->values(),
            'outages' => Outage::query()->with('municipality')->latest('starts_on')->limit(100)->get()->map(fn (Outage $o) => [
                'id' => $o->id,
                'municipality' => $o->municipality->short_name,
                'suburb' => $o->suburb,
                'service' => $o->service->label(),
                'startsOn' => $o->starts_on->format('j M Y'),
                'endsOn' => $o->ends_on->format('j M Y'),
                'source' => $o->source,
            ])->values(),
            'tariffs' => TariffRate::query()->with('municipality')->orderBy('municipality_id')->orderBy('category')->get()->map(fn (TariffRate $t) => [
                'id' => $t->id,
                'municipality' => $t->municipality->short_name,
                'category' => $t->category->label(),
                'service' => $t->service->label(),
                'rate' => 'R'.$t->rate.' per '.$t->unit,
                'effectiveFrom' => $t->effective_from?->format('j M Y'),
                'source' => $t->source,
                'verified' => $t->verified,
            ])->values(),
            'services' => [['value' => 'water', 'label' => 'Water'], ['value' => 'electricity', 'label' => 'Electricity']],
            'categories' => array_map(fn (TariffCategory $c) => ['value' => $c->value, 'label' => $c->label()], [TariffCategory::Residential, TariffCategory::BulkResidential, TariffCategory::Business]),
        ]);
    }

    public function updateMunicipality(Request $request, Municipality $municipality): RedirectResponse
    {
        $data = $request->validate([
            'billing_addressee' => ['required', 'string', 'max:255'],
            'billing_email' => ['nullable', 'email', 'max:255'],
            'billing_email_verified' => ['boolean'],
            'escalation_email' => ['nullable', 'email', 'max:255'],
            'escalation_email_verified' => ['boolean'],
            'dispute_window_days' => ['required', 'integer', 'between:1,365'],
            'reply_window_days' => ['required', 'integer', 'between:1,365'],
            'councillor_window_days' => ['required', 'integer', 'between:1,365'],
            'rules_verified' => ['boolean'],
            'rules_notes' => ['nullable', 'string', 'max:2000'],
            'letter_paragraph' => ['nullable', 'string', 'max:2000'],
            'live' => ['boolean'],
        ]);

        // Ticking "confirmed" stamps the address, so the directory import
        // never overwrites it. Changing an address unconfirms it unless the
        // box stays ticked.
        foreach (['billing_email', 'escalation_email'] as $field) {
            $verified = (bool) ($data["{$field}_verified"] ?? false) && filled($data[$field] ?? null);
            $changed = ($data[$field] ?? null) !== $municipality->{$field};
            $data["{$field}_verified_at"] = match (true) {
                ! $verified => null,
                $changed || $municipality->{"{$field}_verified_at"} === null => now(),
                default => $municipality->{"{$field}_verified_at"},
            };

            if ($changed) {
                $data["{$field}_source"] = filled($data[$field] ?? null) ? 'Entered by staff' : null;
            }

            unset($data["{$field}_verified"]);
        }

        $municipality->update($data);
        Inertia::flash('toast', ['type' => 'success', 'message' => "{$municipality->short_name} saved."]);

        return back();
    }

    public function storeOutage(Request $request): RedirectResponse
    {
        Outage::create($request->validate([
            'municipality_id' => ['required', Rule::exists('municipalities', 'id')],
            'suburb' => ['required', 'string', 'max:255'],
            'service' => ['required', Rule::in([Service::Water->value, Service::Electricity->value])],
            'starts_on' => ['required', 'date'],
            'ends_on' => ['required', 'date', 'after_or_equal:starts_on'],
            'source' => ['nullable', 'string', 'max:255'],
        ]));
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Outage added.']);

        return back();
    }

    public function destroyOutage(Outage $outage): RedirectResponse
    {
        $outage->delete();

        return back();
    }

    public function storeTariff(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'municipality_id' => ['required', Rule::exists('municipalities', 'id')],
            'category' => ['required', Rule::in([TariffCategory::Residential->value, TariffCategory::BulkResidential->value, TariffCategory::Business->value])],
            'service' => ['required', Rule::in([Service::Water->value, Service::Electricity->value])],
            'rate' => ['required', 'decimal:0,2', 'gt:0'],
            'effective_from' => ['nullable', 'date'],
            'source' => ['nullable', 'string', 'max:255'],
            'verified' => ['boolean'],
        ]);

        TariffRate::create([...$data, 'unit' => Service::from($data['service'])->defaultUnit()]);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Rate added.']);

        return back();
    }

    public function destroyTariff(TariffRate $tariffRate): RedirectResponse
    {
        $tariffRate->delete();

        return back();
    }
}
