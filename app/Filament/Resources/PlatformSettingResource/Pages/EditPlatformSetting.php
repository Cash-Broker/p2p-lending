<?php

namespace App\Filament\Resources\PlatformSettingResource\Pages;

use App\Filament\Resources\PlatformSettingResource;
use Filament\Resources\Pages\EditRecord;

/**
 * Edit page for platform settings.
 *
 * The form has type-specific input fields (`value_bool`, `value_int`,
 * `value_float`, `value_text`) that are only visible when the row's
 * `type` matches. They are NOT dehydrated — instead, before-save we
 * pick the right one and write it into the actual `value` column.
 *
 * Why this shape: a single `value` field can't render natively as both
 * a Toggle and a numeric input. The "ghost field" pattern keeps the DB
 * schema simple (single string column) while the UI stays type-aware.
 */
class EditPlatformSetting extends EditRecord
{
    protected static string $resource = PlatformSettingResource::class;

    /**
     * Pick the right ghost field based on the setting's `type` and copy
     * it into the real `value` column. The ghost fields themselves are
     * `dehydrated(false)` so they never reach the DB directly.
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $type = $this->record->type;

        $value = match ($type) {
            'bool'  => $this->data['value_bool'] ? 'true' : 'false',
            'int'   => (string) (int) $this->data['value_int'],
            'float' => (string) (float) $this->data['value_float'],
            // Enum-like strings come from the Select ghost field; fall back to
            // the current value so a missing state can never blank the setting.
            default => isset(PlatformSettingResource::STRING_CHOICES[$this->record->key])
                ? (string) ($this->data['value_choice'] ?? $this->record->value)
                : (string) ($this->data['value_text'] ?? ''),
        };

        $data['value'] = $value;
        return $data;
    }
}
