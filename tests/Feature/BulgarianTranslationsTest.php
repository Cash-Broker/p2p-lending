<?php

namespace Tests\Feature;

use App\Filament\Resources\UserResource\Pages\ListUsers;
use App\Filament\Resources\UserResource\Pages\ViewUser;
use App\Models\Originator;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Livewire\DatabaseNotifications;
use Filament\Notifications\Livewire\Notifications;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The admin and the investor SPA are Bulgarian-only. Until 2026-10-08
 * lang/bg/validation.php held Laravel's English templates («The върната
 * главница (€) field is required.»), and a few Filament strings had no bg
 * line at all, so with APP_FALLBACK_LOCALE=bg they rendered as raw keys.
 */
class BulgarianTranslationsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Pinned rather than read from .env (local runs bg/bg, .env.example
        // ships en/en). A bg fallback turns a missing line into the raw key
        // instead of letting an English fallback hide it.
        app()->setLocale('bg');
        app('translator')->setFallback('bg');
    }

    /**
     * Only the lines this app patches in lang/vendor — NOT a sweep of every
     * Filament key, so a Filament bump that adds keys fails nothing here.
     *
     * @return array<string, array{string, string}>
     */
    public static function patchedFilamentLines(): array
    {
        return [
            'select: empty dropdown' => ['filament-forms::components.select.no_options_message', 'Няма налични опции.'],
            'text input: show password' => ['filament-forms::components.text_input.actions.show_password.label', 'Покажи паролата'],
            'text input: hide password' => ['filament-forms::components.text_input.actions.hide_password.label', 'Скрий паролата'],
            'text input: copy' => ['filament-forms::components.text_input.actions.copy.label', 'Копирай'],
            'text input: copied' => ['filament-forms::components.text_input.actions.copy.message', 'Копирано'],
            'select: search box' => ['filament-forms::components.select.search_label', 'Търсене'],
            'select: clear' => ['filament-forms::components.select.actions.clear.label', 'Изчисти избора'],
            'select: remove option' => ['filament-forms::components.select.actions.remove_option.label', 'Премахни :label'],
            'select: create option' => ['filament-forms::components.select.actions.create_option.label', 'Създай'],
            'file upload: download' => ['filament-forms::components.file_upload.actions.download.label', 'Изтегли'],
            'file upload: open' => ['filament-forms::components.file_upload.actions.open.label', 'Отвори в нов раздел'],
            'table: loading live region' => ['filament-tables::table.loading', 'Зареждане...'],
            'table: apply columns' => ['filament-tables::table.column_manager.actions.apply.label', 'Приложи колоните'],
            'table: reset columns' => ['filament-tables::table.column_manager.actions.reset.label', 'Нулирай'],
            'table: record actions header' => ['filament-tables::table.columns.actions.label', 'Действие|Действия'],
            'table: boolean icon true' => ['filament-tables::table.columns.icon.boolean.true', 'Да'],
            'table: boolean icon false' => ['filament-tables::table.columns.icon.boolean.false', 'Не'],
            'panel: skip link' => ['filament-panels::layout.skip_to_content.label', 'Към съдържанието'],
            'panel: sidebar landmark' => ['filament-panels::layout.navigation.label', 'Странична навигация'],
            'panel: topbar landmark' => ['filament-panels::layout.topbar.label', 'Горна лента'],
            'panel: avatar alt' => ['filament-panels::layout.avatar.alt', 'Аватар на :name'],
            'panel: theme switcher' => ['filament-panels::layout.actions.theme_switcher.label', 'Тема'],
            'panel: bell with unread' => ['filament-panels::layout.actions.open_database_notifications.label_with_unread_count', '{1} Известия, :count непрочетено известие|[2,*] Известия, :count непрочетени известия'],
            'panel: error toast title' => ['filament-panels::error-notifications.title', 'Грешка при зареждане на страницата'],
            'panel: error toast body' => ['filament-panels::error-notifications.body', 'Възникна грешка при зареждането на страницата. Моля, опитайте отново по-късно.'],
            'notifications: close toast' => ['filament-notifications::notification.actions.close.label', 'Затвори известието'],
            'notifications: unread marker' => ['filament-notifications::database.modal.unread_label', 'Непрочетено известие'],
            'support: breadcrumbs' => ['filament::components/breadcrumbs.label', 'Навигационна пътека'],
            'support: loading section' => ['filament::components/loading-section.label', 'Зареждане...'],
        ];
    }

    #[DataProvider('patchedFilamentLines')]
    public function test_patched_filament_line_is_bulgarian(string $key, string $expected): void
    {
        $this->assertSame($expected, __($key));
    }

    public function test_patch_keeps_the_vendor_bg_lines_of_the_same_group(): void
    {
        // The override is merged key-by-key; a neighbour from the package's
        // own bg file must survive it.
        $this->assertSame('Търси', __('filament-tables::table.fields.search.label'));
        $this->assertSame('Зареждане...', __('filament-forms::components.select.loading_message'));
        $this->assertSame('Създаване на опция', __('filament-forms::components.select.actions.create_option.modal.heading'));
        $this->assertSame('Колони', __('filament-tables::table.column_manager.heading'));
        $this->assertSame('Включи тъмна тема', __('filament-panels::layout.actions.theme_switcher.dark.label'));
        $this->assertSame('Изход', __('filament-panels::layout.actions.logout.label'));
        $this->assertSame('Известия', __('filament-panels::layout.actions.open_database_notifications.label'));
        $this->assertSame('Маркирай всички като прочетени', __('filament-notifications::database.modal.actions.mark_all_as_read.label'));
    }

    public function test_bell_unread_count_is_pluralised_in_bulgarian(): void
    {
        $key = 'filament-panels::layout.actions.open_database_notifications.label_with_unread_count';

        $this->assertSame('Известия, 1 непрочетено известие', trans_choice($key, 1, ['count' => 1]));
        $this->assertSame('Известия, 2 непрочетени известия', trans_choice($key, 2, ['count' => 2]));
        $this->assertSame('Известия, 15 непрочетени известия', trans_choice($key, 15, ['count' => 15]));
    }

    public function test_record_actions_header_label_is_pluralised_in_bulgarian(): void
    {
        $key = 'filament-tables::table.columns.actions.label';

        $this->assertSame('Действие', trans_choice($key, 1));
        $this->assertSame('Действия', trans_choice($key, 3));
    }

    public function test_table_result_count_is_pluralised_in_bulgarian(): void
    {
        $key = 'filament-tables::table.result_count';

        $this->assertSame('Няма резултати', trans_choice($key, 0, ['count' => 0]));
        $this->assertSame('1 резултат', trans_choice($key, 1, ['count' => 1]));
        $this->assertSame('2 резултата', trans_choice($key, 2, ['count' => 2]));
        $this->assertSame('37 резултата', trans_choice($key, 37, ['count' => 37]));
    }

    public function test_admin_login_renders_the_password_reveal_buttons_in_bulgarian(): void
    {
        $this->get('/admin/login')
            ->assertOk()
            ->assertSee('Покажи паролата')
            ->assertSee('Скрий паролата')
            ->assertDontSee('filament-forms::components.text_input');
    }

    public function test_admin_table_live_regions_render_in_bulgarian(): void
    {
        $admin = User::factory()->admin()->create(['email_verified_at' => now()]);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($admin);
        User::factory()->count(2)->create(['email_verified_at' => now()]);

        $html = Livewire::test(ListUsers::class)->html();

        $this->assertStringNotContainsString('filament-tables::table.result_count', $html);
        $this->assertStringNotContainsString('filament-tables::table.loading', $html);
        $this->assertMatchesRegularExpression('/\d+ резултата?|Няма резултати/u', $html);
    }

    public function test_admin_pages_render_no_patched_key_raw(): void
    {
        // The error-toast lines are embedded only while debug is off (prod).
        config(['app.debug' => false]);

        $admin = User::factory()->admin()->create(['email_verified_at' => now(), 'name' => 'Мария Иванова']);
        User::factory()->create(['email_verified_at' => now()]);
        Originator::factory()->create(['buyback' => true]);
        Originator::factory()->create(['buyback' => false]);

        // These pages render the panel shell (every page), the column manager
        // and record-actions header (lists), both boolean icon states
        // (originators), the searchable selects with an inline create button
        // (loan form) and the file upload (originator logo). The bell, toasts
        // and password reveal have their own render tests; no admin form uses
        // a copyable TextInput, so text_input.actions.copy is covered by the
        // data provider only.
        $html = collect(['/admin', '/admin/users', '/admin/originators', '/admin/originators/create', '/admin/loans/create'])
            ->map(fn (string $page): string => $this->actingAs($admin)->get($page)->assertOk()->getContent())
            ->implode("\n");

        foreach (self::patchedFilamentLines() as [$key]) {
            $this->assertStringNotContainsString($key, $html);
        }

        foreach (['Към съдържанието', 'Странична навигация', 'Горна лента', 'Аватар на Мария Иванова', 'Навигационна пътека', 'Приложи колоните'] as $bulgarian) {
            $this->assertStringContainsString($bulgarian, $html);
        }

        $this->assertStringContainsString('window.filamentErrorNotifications', $html);
    }

    public function test_admin_notification_chrome_renders_in_bulgarian(): void
    {
        $admin = User::factory()->admin()->create(['email_verified_at' => now()]);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($admin);

        FilamentNotification::make()->title('Нов инвеститор')->sendToDatabase($admin);

        // The bell's lazy placeholder carries the unread count (aria-label + tooltip).
        $this->get('/admin')
            ->assertOk()
            ->assertSee('Известия, 1 непрочетено известие')
            ->assertDontSee('filament-panels::layout.actions.open_database_notifications');

        // The slide-over list marks each unread item for screen readers.
        $slideOver = Livewire::test(DatabaseNotifications::class)->html();

        $this->assertStringContainsString('Непрочетено известие', $slideOver);
        $this->assertStringNotContainsString('filament-notifications::', $slideOver);

        // Every toast's close button (tooltip + aria-label).
        FilamentNotification::make()->title('Записано')->success()->send();
        $toasts = Livewire::test(Notifications::class)->html();

        $this->assertStringContainsString('Затвори известието', $toasts);
        $this->assertStringNotContainsString('filament-notifications::', $toasts);
    }

    public function test_every_framework_validation_rule_has_a_bulgarian_message_with_the_same_placeholders(): void
    {
        $english = Arr::dot(Arr::except(
            require base_path('vendor/laravel/framework/src/Illuminate/Translation/lang/en/validation.php'),
            ['custom', 'attributes'],
        ));
        $bulgarian = Arr::dot(Arr::except(require lang_path('bg/validation.php'), ['custom', 'attributes']));

        // A rule the framework knows but bg does not would render as the raw
        // «validation.<rule>» key here (fallback bg) — add its Bulgarian line.
        $this->assertSame([], array_keys(array_diff_key($english, $bulgarian)));

        $placeholders = function (string $message): array {
            preg_match_all('/:[a-z_]+/', $message, $matches);
            $found = array_values(array_unique($matches[0]));
            sort($found);

            return $found;
        };

        foreach ($bulgarian as $rule => $message) {
            $this->assertMatchesRegularExpression('/\p{Cyrillic}/u', $message, "validation.{$rule} is not Bulgarian");
            $this->assertDoesNotMatchRegularExpression('/\b(The|field|must)\b/', $message, "validation.{$rule} still has English in it");

            if (isset($english[$rule])) {
                $this->assertSame($placeholders($english[$rule]), $placeholders($message), "validation.{$rule} lost or gained a placeholder");
            }
        }
    }

    public function test_filament_form_validation_error_renders_in_bulgarian(): void
    {
        $admin = User::factory()->admin()->create(['email_verified_at' => now()]);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($admin);
        $investor = User::factory()->create(['email_verified_at' => now(), 'phone' => '+359888123456']);

        $component = Livewire::test(ViewUser::class, ['record' => $investor->id])
            ->callAction('edit_phone', data: ['phone' => ''])
            ->assertHasActionErrors(['phone' => 'required']);

        // Filament passes the field's label (lcfirst) as the attribute.
        $messages = collect($component->errors()->toArray())
            ->first(fn (array $messages, string $key): bool => str_ends_with($key, '.phone'));

        $this->assertSame(['Полето „телефон“ е задължително.'], $messages);
    }

    public function test_api_validation_error_reaches_the_spa_in_bulgarian(): void
    {
        // The SPA renders errors.<field>[0] verbatim, so the attribute must be
        // translated too — «Полето „email“ …» would still be half English.
        $this->postJson('/api/forgot-password', ['email' => 'not-an-email'])
            ->assertStatus(422)
            ->assertJsonPath('errors.email.0', 'Полето „имейл“ трябва да бъде валиден имейл адрес.');
    }
}
