<?php

namespace Modules\Vehicles;

use App\Http\Controllers\Controller;
use App\Support\Tenancy\CurrentFamily;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * V1.1 Phase 1: SERVICE PROVIDERS / GARAGES (spec §12) — a simple family-scoped manage screen (list +
 * add/edit) plus a provider detail showing the service it has done. Providers are referenced from
 * maintenance history and repairs instead of a free-text garage name (the free text stays as a fallback).
 *
 * Collaborative family data, like the vehicles themselves: any member may manage the shared list (no admin
 * gate). Route-model binding resolves {provider} before the tenant is set, so writes guard the family
 * explicitly via authorizeProvider().
 */
class ServiceProvidersController extends Controller
{
    /** Manage screen: every provider, each with how many services it has on record. */
    public function index(Request $request): Response
    {
        $providers = ServiceProvider::query()
            ->withCount(['maintenanceHistory', 'repairs'])
            ->orderBy('name')
            ->get()
            ->map(fn (ServiceProvider $p) => [
                ...$p->toPayload(),
                'serviceCount' => $p->maintenance_history_count + $p->repairs_count,
            ])->values();

        return Inertia::render('vehicles/providers/index', [
            'providers' => $providers,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validateProvider($request);
        $data['created_by'] = $request->user()->id;

        ServiceProvider::create($data);

        return back();
    }

    /** Provider detail: its contact card + the service history (maintenance + repairs) done there. */
    public function show(Request $request, ServiceProvider $provider): Response
    {
        $this->authorizeProvider($provider);

        // Vehicle names for labelling each service row (which car it was on). One query for the family.
        $vehicleNames = Vehicle::query()->get()
            ->mapWithKeys(fn (Vehicle $v) => [$v->id => trim("{$v->make} {$v->model}")])->all();

        // Merge the maintenance + repair history into one newest-first feed.
        $history = collect();
        foreach ($provider->maintenanceHistory()->get() as $h) {
            $history->push([
                'id' => 'maintenance-'.$h->id,
                'type' => 'maintenance',
                'date' => optional($h->performed_at)->toDateString(),
                'title' => $h->name,
                'vehicle' => $vehicleNames[$h->vehicle_id] ?? null,
                'mileage' => $h->mileage,
                'cost' => $h->cost,
            ]);
        }
        foreach ($provider->repairs()->get() as $r) {
            $history->push([
                'id' => 'repair-'.$r->id,
                'type' => 'repair',
                'date' => optional($r->occurred_on)->toDateString(),
                'title' => $r->title,
                'vehicle' => $vehicleNames[$r->vehicle_id] ?? null,
                'mileage' => $r->mileage,
                'cost' => $r->cost,
            ]);
        }

        $history = $history->sortByDesc('date')->values();

        return Inertia::render('vehicles/providers/show', [
            'provider' => $provider->toPayload(),
            'history' => $history->all(),
            'lastService' => $history->first()['date'] ?? null,
        ]);
    }

    public function update(Request $request, ServiceProvider $provider): RedirectResponse
    {
        $this->authorizeProvider($provider);
        $provider->update($this->validateProvider($request));

        return back();
    }

    /** Delete a provider. Its references on history/repairs null out (FK), so those rows keep their record. */
    public function destroy(Request $request, ServiceProvider $provider): RedirectResponse
    {
        $this->authorizeProvider($provider);
        $provider->delete();

        return redirect()->route('vehicles.providers.index');
    }

    // ---- helpers ---------------------------------------------------------------------------------------

    /** @return array<string, mixed> */
    private function validateProvider(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'address' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:40'],
            'website' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);
    }

    private function authorizeProvider(ServiceProvider $provider): void
    {
        abort_unless((int) $provider->family_id === (int) CurrentFamily::id(), 404);
    }
}
