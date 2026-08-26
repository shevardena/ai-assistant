<?php

/**
 * @param  array<string, mixed>  $translations
 * @return list<string>
 */
function translationDictionaryKeys(array $translations, string $prefix = ''): array
{
    $keys = [];

    foreach ($translations as $key => $value) {
        $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;

        if (is_array($value)) {
            $keys = [...$keys, ...translationDictionaryKeys($value, $path)];

            continue;
        }

        $keys[] = $path;
    }

    sort($keys);

    return $keys;
}

/**
 * @param  array<string, mixed>  $translations
 */
function assertTranslationValuesAreNotEmpty(array $translations): void
{
    foreach ($translations as $value) {
        if (is_array($value)) {
            assertTranslationValuesAreNotEmpty($value);

            continue;
        }

        expect($value)->toBeString()->not->toBe('');
    }
}

test('all supported locale dictionaries match English and contain no empty values', function () {
    $resourceRoot = dirname(__DIR__, 2).'/resources/js/locales';
    $localeConfig = require dirname(__DIR__, 2).'/config/locales.php';
    $english = json_decode(
        file_get_contents($resourceRoot.'/en.json'),
        true,
        flags: JSON_THROW_ON_ERROR,
    );
    $englishKeys = translationDictionaryKeys($english);

    foreach (array_keys($localeConfig['supported']) as $locale) {
        $translations = json_decode(
            file_get_contents("{$resourceRoot}/{$locale}.json"),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        expect(translationDictionaryKeys($translations))->toBe($englishKeys);
        assertTranslationValuesAreNotEmpty($translations);
    }
});

test('english provides representative plural forms for supported complex plural locales', function () {
    $english = json_decode(
        file_get_contents(dirname(__DIR__, 2).'/resources/js/locales/en.json'),
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    expect($english['common'])->toHaveKeys([
        'conversation_count_one',
        'conversation_count_few',
        'conversation_count_many',
        'conversation_count_other',
    ]);
});

test('non-English locales translate representative dashboard copy', function () {
    $resourceRoot = dirname(__DIR__, 2).'/resources/js/locales';
    $english = json_decode(
        file_get_contents($resourceRoot.'/en.json'),
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    foreach (['ka', 'ru', 'uk', 'pl', 'de', 'es', 'pt'] as $locale) {
        $translations = json_decode(
            file_get_contents("{$resourceRoot}/{$locale}.json"),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        expect($translations['common']['save'])
            ->not->toBe($english['common']['save'])
            ->and($translations['navigation']['analytics'])
            ->not->toBe($english['navigation']['analytics'])
            ->and($translations['common']['no_datasets'])
            ->not->toBe($english['common']['no_datasets']);
    }
});
