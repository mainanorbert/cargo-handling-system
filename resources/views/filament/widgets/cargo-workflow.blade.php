<x-filament-widgets::widget>
    <section class="cargo-workflow" aria-labelledby="cargo-workflow-heading">
        <div class="cargo-workflow__intro">
            <div>
                <p class="cargo-workflow__eyebrow">Operations dashboard</p>
                <h2 id="cargo-workflow-heading">Follow the cargo journey</h2>
                <p>Start at the point of origin, then record each movement as cargo arrives, is stored, and leaves by truck.</p>
            </div>
            <span class="cargo-workflow__tag">Origin to delivery</span>
        </div>

        <div class="cargo-workflow__steps">
            <article class="cargo-workflow__step">
                <span class="cargo-workflow__number">01 <span>Origin</span></span>
                <h3>Register shipment</h3>
                <p>Capture the vessel, commodity, origin and expected quantity.</p>
                <a href="{{ $shipmentUrl }}">Register shipment <span aria-hidden="true">→</span></a>
            </article>
            <article class="cargo-workflow__step">
                <span class="cargo-workflow__number">02 <span>Arrival</span></span>
                <h3>Receive cargo</h3>
                <p>Record the vessel discharge into a shore tank.</p>
                <a href="{{ $dischargeUrl }}">Record discharge <span aria-hidden="true">→</span></a>
            </article>
            <article class="cargo-workflow__step">
                <span class="cargo-workflow__number">03 <span>Storage</span></span>
                <h3>Monitor tanks</h3>
                <p>Check stock, commodity and available tank capacity.</p>
                <a href="{{ $tankUrl }}">View tank balances <span aria-hidden="true">→</span></a>
            </article>
            <article class="cargo-workflow__step">
                <span class="cargo-workflow__number">04 <span>Dispatch</span></span>
                <h3>Deliver by truck</h3>
                <p>Record cargo leaving the tank on a truck.</p>
                <a href="{{ $deliveryUrl }}">Record delivery <span aria-hidden="true">→</span></a>
            </article>
        </div>
    </section>
</x-filament-widgets::widget>
