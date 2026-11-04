<?php

namespace App\Providers\Filament;

use App\Filament\Resources\Movements\MovementResource;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationItem;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\View\PanelsRenderHook;
use Filament\Widgets\AccountWidget;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\HtmlString;
use Illuminate\View\Middleware\ShareErrorsFromSession;

use function Filament\Support\original_request;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login()
            ->brandName('Bulkstream')
            ->darkMode(false)
            ->renderHook(PanelsRenderHook::HEAD_END, fn (): HtmlString => new HtmlString('<link rel="stylesheet" href="'.asset('css/bulkstream-panel.css').'">'))
            ->colors([
                'primary' => Color::hex('#008080'),
                'gray' => Color::hex('#263238'),
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->navigationItems([
                NavigationItem::make('Vessel Discharge (Inbound Cargo)')
                    ->group('Cargo workflow')
                    ->icon('heroicon-o-inbox-arrow-down')
                    ->sort(4)
                    ->isActiveWhen(fn (): bool => original_request()->routeIs(MovementResource::getRouteBaseName().'.vessel-discharge'))
                    ->url(fn (): string => MovementResource::getUrl('vessel-discharge')),
                NavigationItem::make('Truck Delivery (Outbound Cargo)')
                    ->group('Cargo workflow')
                    ->icon('heroicon-o-truck')
                    ->sort(5)
                    ->isActiveWhen(fn (): bool => original_request()->routeIs(MovementResource::getRouteBaseName().'.truck-delivery'))
                    ->url(fn (): string => MovementResource::getUrl('truck-delivery')),
            ])
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->widgets([
                AccountWidget::class,

            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
