<x-filament-widgets::widget>
    <section class="cargo-dashboard" aria-labelledby="cargo-dashboard-heading">
        <div class="cargo-dashboard__intro">
            <div>
                <h2 id="cargo-dashboard-heading">Cargo operations</h2>
                <p>Live tank stock, available capacity and recent activity.</p>
            </div>
            <a href="{{ $movementUrl }}">View movements <span aria-hidden="true">→</span></a>
        </div>

        <dl class="cargo-dashboard__summary" aria-label="Operational summary">
            <div><dt>Commodities</dt><dd>{{ $commodityCount }}</dd></div>
            <div><dt>Inbound shipments</dt><dd>{{ $inboundShipmentCount }}</dd></div>
            <div>
                <dt>Discharge standard met</dt>
                <dd>{{ number_format($shipmentStandardMetPercentageBasisPoints / 100, 2) }}%</dd>
                <span>{{ $shipmentsMeetingStandardCount }} of {{ $inboundShipmentCount }} shipments</span>
            </div>
            <div><dt>Tanks</dt><dd>{{ $tankCount }}</dd></div>
            <div><dt>Trucks</dt><dd>{{ $truckCount }}</dd></div>
            <div><dt>Tank stock</dt><dd>{{ number_format($balanceMl / 1000, 3) }} L</dd></div>
            <div><dt>Available capacity</dt><dd>{{ number_format($availableMl / 1000, 3) }} L</dd></div>
            <div><dt>Exceptions</dt><dd>{{ $exceptionsNeedingReview }}</dd></div>
        </dl>

        <div class="cargo-dashboard__grid">
            <section class="cargo-dashboard__section" aria-labelledby="tank-status-heading">
                <div class="cargo-dashboard__section-heading">
                    <h3 id="tank-status-heading">Tank status</h3>
                    <span>Current utilisation</span>
                </div>
                <div class="cargo-dashboard__list">
                    @forelse ($tanks as $tank)
                        <article class="cargo-dashboard__row">
                            <div><strong>{{ $tank->name }}</strong><span>{{ $tank->commodity?->code ?? 'Empty' }}</span></div>
                            <div class="cargo-dashboard__quantity">{{ number_format($tank->balance_ml / 1000, 3) }} / {{ number_format($tank->capacity_ml / 1000, 3) }} L</div>
                            <div class="cargo-dashboard__utilisation" aria-label="{{ number_format($tank->balance_ml * 100 / $tank->capacity_ml, 1) }} percent utilised"><span style="width: {{ number_format($tank->balance_ml * 100 / $tank->capacity_ml, 1, '.', '') }}%"></span></div>
                        </article>
                    @empty
                        <p class="cargo-dashboard__empty">No tanks have been registered.</p>
                    @endforelse
                </div>
            </section>

            <section class="cargo-dashboard__section" aria-labelledby="recent-movements-heading">
                <div class="cargo-dashboard__section-heading">
                    <h3 id="recent-movements-heading">Recent movements</h3>
                    <span>Most recent event time first</span>
                </div>
                <div class="cargo-dashboard__list">
                    @forelse ($recentMovements as $movement)
                        <article class="cargo-dashboard__row">
                            <div><strong>{{ $movement->type === 'inbound' ? 'Inbound discharge' : 'Truck delivery' }}</strong><span>{{ $movement->shipment?->reference ?? $movement->truck?->can_plate }}</span></div>
                            <div class="cargo-dashboard__quantity">{{ number_format($movement->quantity_ml / 1000, 3) }} L</div>
                            <time datetime="{{ $movement->occurred_at }}">{{ $movement->occurred_at->format('d M H:i') }}</time>
                        </article>
                    @empty
                        <p class="cargo-dashboard__empty">No cargo movements have been recorded.</p>
                    @endforelse
                </div>
            </section>
        </div>
    </section>
</x-filament-widgets::widget>
