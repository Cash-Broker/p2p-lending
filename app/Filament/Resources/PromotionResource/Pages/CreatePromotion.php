<?php

namespace App\Filament\Resources\PromotionResource\Pages;

use App\Filament\Resources\PromotionResource;
use App\Models\User;
use App\Notifications\PromoStartedNotification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

/**
 * The window starts at the CREATE click («прозорецът тръгва от момента на
 * създаване») — the duration ghost field becomes starts_at/ends_at here.
 * After the record commits, every verified investor gets the bell ping
 * (queued; a notification failure logs and never blocks the promo).
 */
class CreatePromotion extends CreateRecord
{
    protected static string $resource = PromotionResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $minutes = (int) ($this->data['duration_minutes'] ?? 60);
        if (! array_key_exists($minutes, PromotionResource::DURATION_OPTIONS)) {
            $minutes = 60;
        }

        $data['starts_at'] = now();
        $data['ends_at'] = now()->addMinutes($minutes);
        $data['created_by'] = auth()->id();

        return $data;
    }

    protected function afterCreate(): void
    {
        try {
            $investors = User::query()
                ->where('role', 'investor')
                ->whereNotNull('email_verified_at')
                ->get();

            Notification::send($investors, new PromoStartedNotification(
                (int) $this->record->loan_id,
                (string) $this->record->bonus_percent,
                $this->record->ends_at,
            ));
        } catch (\Throwable $e) {
            Log::error('Promo bell dispatch failed', [
                'promotion_id' => $this->record->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
