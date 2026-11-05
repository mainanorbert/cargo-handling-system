<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Outbound cargo report | Bulkstream</title>
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; background: #f4f6f6; color: #263238; font: 14px/1.5 Arial, sans-serif; }
        .toolbar { max-width: 1020px; margin: 20px auto; text-align: right; padding: 0 24px; }
        button { background: #004d4d; color: white; border: 0; border-radius: 5px; padding: 11px 18px; font: inherit; cursor: pointer; }
        main { max-width: 1020px; margin: auto auto 30px; padding: 45px; background: white; box-shadow: 0 4px 24px #26323818; }
        header { border-bottom: 3px solid #008080; padding-bottom: 20px; display: flex; justify-content: space-between; gap: 20px; }
        .brand { color: #004d4d; font-size: 22px; font-weight: bold; letter-spacing: 1px; }
        .eyebrow { color: #008080; text-transform: uppercase; font-size: 11px; font-weight: bold; letter-spacing: 1.2px; }
        h1 { color: #004d4d; font-size: 28px; margin: 22px 0 4px; }
        h2 { color: #004d4d; font-size: 19px; margin: 0 0 12px; }
        p { margin: 4px 0; } .muted { color: #596c6c; } .meta { text-align: right; }
        section { margin-top: 30px; break-inside: avoid; }
        .summary { background: #f4f6f6; padding: 16px; display: flex; flex-wrap: wrap; gap: 18px 35px; }
        .summary div { min-width: 130px; } .summary strong { display: block; font-size: 16px; color: #004d4d; }
        table { border-collapse: collapse; width: 100%; margin-top: 15px; font-size: 12px; }
        th { background: #004d4d; color: white; text-align: left; } th, td { padding: 9px 8px; border-bottom: 1px solid #dbe3e3; vertical-align: top; }
        .num { text-align: right; white-space: nowrap; } .footnote { margin-top: 30px; border-top: 1px solid #dbe3e3; padding-top: 12px; font-size: 11px; }
        @media (max-width: 650px) { main { padding: 22px; } header { display: block; } .meta { text-align: left; margin-top: 12px; } .table-wrap { overflow-x: auto; } }
        @media print { body { background: white; } .toolbar { display: none; } main { box-shadow: none; max-width: none; margin: 0; padding: 0; } @page { margin: 14mm; } }
    </style>
</head>
<body>
<div class="toolbar"><button type="button" onclick="window.print()">Print / Save as PDF</button></div>
<main>
    <header><div><div class="brand">BULKSTREAM</div><div class="eyebrow">Cargo operations</div></div><div class="meta muted">Outbound cargo report<br>Generated {{ now()->utc()->format('d M Y H:i') }} UTC</div></header>
    <h1>Outbound delivery report</h1>
    <p class="muted">{{ $movements->count() }} delivery event(s) · Quantities in litres</p>
    <div class="summary">
        <div><span class="muted">Applied deliveries</span><strong>{{ $movements->where('status', 'applied')->count() }}</strong></div>
        <div><span class="muted">Total delivered</span><strong>{{ \App\Filament\Resources\Reports\ReportResource::litres((int) $movements->where('status', 'applied')->sum('quantity_ml')) }} L</strong></div>
    </div>
    <div class="table-wrap"><table><thead><tr><th>Event time (UTC)</th><th>Event reference</th><th>Truck</th><th>Commodity</th><th>Tank</th><th>Status</th><th class="num">Delivered (L)</th></tr></thead><tbody>
        @forelse ($movements as $movement)
            <tr><td>{{ $movement->occurred_at->utc()->format('d M Y H:i:s') }}</td><td>{{ $movement->event_reference }}</td><td>{{ $movement->truck?->can_plate ?? '—' }}</td><td>{{ $movement->commodity?->code ?? '—' }}</td><td>{{ $movement->tank?->name ?? '—' }}</td><td>{{ ucfirst($movement->status) }}</td><td class="num">{{ \App\Filament\Resources\Reports\ReportResource::litres((int) $movement->quantity_ml) }}</td></tr>
        @empty
            <tr><td colspan="7">No deliveries match the selected filters.</td></tr>
        @endforelse
    </tbody></table></div>
    <p class="footnote muted">The total includes applied deliveries only. Each row displays its recorded processing status.</p>
</main>
</body>
</html>
