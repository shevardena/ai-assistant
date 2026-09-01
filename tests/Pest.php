<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

pest()->extend(TestCase::class)
    ->in('Unit/SpeechToTextProviderTest.php');

pest()->extend(TestCase::class)
    ->in('Unit/AssemblyAiSpeechToTextProviderTest.php');

pest()->extend(TestCase::class)
    ->in('Unit/WidgetMessageRequestTest.php', 'Unit/WidgetImageAttachmentServiceTest.php');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}

/**
 * Assert the OpenAI strict-function object invariants recursively.
 *
 * @param  array<string, mixed>  $schema
 */
function assertStrictAiObjectSchema(array $schema, string $path = 'schema'): void
{
    $schemaType = $schema['type'] ?? null;
    $isObject = $schemaType === 'object'
        || (is_array($schemaType) && in_array('object', $schemaType, true));

    if ($isObject && array_key_exists('properties', $schema)) {
        if (($schema['additionalProperties'] ?? null) !== false) {
            throw new RuntimeException("{$path} must set additionalProperties=false.");
        }

        $properties = is_array($schema['properties']) ? array_keys($schema['properties']) : [];
        $required = is_array($schema['required'] ?? null) ? $schema['required'] : [];

        if (array_diff($properties, $required) !== [] || array_diff($required, $properties) !== []) {
            throw new RuntimeException("{$path} must require every declared property.");
        }
    }

    foreach ($schema as $key => $value) {
        if (is_array($value)) {
            assertStrictAiObjectSchema($value, "{$path}.{$key}");
        }
    }
}
