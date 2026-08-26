<?php

use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * @param  array<string, mixed>  $translations
 * @return list<string>
 */
function localeTranslationKeys(array $translations, string $prefix = ''): array
{
    $keys = [];

    foreach ($translations as $key => $value) {
        $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;

        if (is_array($value)) {
            $keys = [...$keys, ...localeTranslationKeys($value, $path)];

            continue;
        }

        $keys[] = $path;
    }

    sort($keys);

    return $keys;
}

test('locale configuration exposes the supported dashboard locales', function () {
    expect(array_keys(config('locales.supported')))->toBe([
        'en',
        'ka',
        'ru',
        'uk',
        'pl',
        'de',
        'es',
        'pt',
    ])->and(config('locales.default'))->toBe('en');
});

test('users can persist a supported dashboard locale', function () {
    $user = User::factory()->create(['locale' => 'en']);

    $this->actingAs($user)
        ->patch(route('locale.update'), ['locale' => 'ka'])
        ->assertRedirect();

    expect($user->fresh()->locale)->toBe('ka');
});

test('unsupported dashboard locales are rejected and do not change the user preference', function () {
    $user = User::factory()->create(['locale' => 'en']);

    $this->actingAs($user)
        ->patch(route('locale.update'), ['locale' => 'ge'])
        ->assertSessionHasErrors('locale');

    expect($user->fresh()->locale)->toBe('en');
});

test('locale preferences remain user-owned when users share and switch teams', function () {
    $englishUser = User::factory()->create(['locale' => 'en']);
    $georgianUser = User::factory()->create(['locale' => 'ka']);
    $team = Team::factory()->create();

    $team->members()->attach($englishUser, ['role' => TeamRole::Member->value]);
    $team->members()->attach($georgianUser, ['role' => TeamRole::Member->value]);

    $englishUser->switchTeam($team);
    $georgianUser->switchTeam($team);

    expect($englishUser->fresh()->locale)->toBe('en')
        ->and($georgianUser->fresh()->locale)->toBe('ka')
        ->and($englishUser->fresh()->current_team_id)->toBe($team->id)
        ->and($georgianUser->fresh()->current_team_id)->toBe($team->id);
});

test('the middleware applies the user locale and shares the locale registry with Inertia', function () {
    $user = User::factory()->create(['locale' => 'ru']);

    $this->actingAs($user)
        ->get(route('profile.edit'))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->component('settings/profile')
            ->where('locale', 'ru')
            ->has('supportedLocales', 8)
            ->where('supportedLocales.0.code', 'en')
            ->where('supportedLocales.1.code', 'ka')
            ->where('supportedLocales.1.nativeName', 'ქართული'));

    expect(app()->getLocale())->toBe('ru');
});

test('every dashboard dictionary has the same complete key structure as English', function () {
    $english = json_decode(
        file_get_contents(base_path('resources/js/locales/en.json')),
        true,
        flags: JSON_THROW_ON_ERROR,
    );
    $englishKeys = localeTranslationKeys($english);

    foreach (array_keys(config('locales.supported')) as $locale) {
        $translations = json_decode(
            file_get_contents(base_path("resources/js/locales/{$locale}.json")),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        expect(localeTranslationKeys($translations))->toBe($englishKeys);
    }
});

test('dashboard dictionaries include i18next plural forms and status labels', function () {
    $english = json_decode(
        file_get_contents(base_path('resources/js/locales/en.json')),
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    expect($english['common'])->toHaveKeys([
        'conversation_count_one',
        'conversation_count_few',
        'conversation_count_many',
        'conversation_count_other',
    ])->and($english['status'])->toHaveKeys([
        'active',
        'completed',
        'failed',
        'pending',
    ]);
});
