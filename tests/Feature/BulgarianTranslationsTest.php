<?php

namespace Tests\Feature;

use App\Filament\Resources\UserResource\Pages\ListUsers;
use App\Filament\Resources\UserResource\Pages\ViewUser;
use App\Models\User;
use Filament\Facades\Filament;
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
            'table: loading live region' => ['filament-tables::table.loading', 'Зареждане...'],
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
