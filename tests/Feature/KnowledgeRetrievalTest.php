<?php

use App\Models\Dataset;
use App\Models\DatasetRecord;
use App\Services\Knowledge\KnowledgeSearchService;

function knowledgeRetrievalDataset(): Dataset
{
    return Dataset::factory()->ready()->create([
        'entity_type' => 'knowledge',
    ]);
}

function knowledgeArticle(Dataset $dataset, string $externalId, string $title, string $content, ?string $language = null): DatasetRecord
{
    $payload = [
        'title' => $title,
        'content' => $content,
        'category' => 'policy',
    ];

    if ($language !== null) {
        $payload['language'] = $language;
    }

    return DatasetRecord::factory()->create([
        'dataset_id' => $dataset->id,
        'external_id' => $externalId,
        'payload' => $payload,
        'searchable_text' => implode(' ', array_filter($payload)),
    ]);
}

test('retrieves Georgian return policy across inflected natural queries', function (string $query) {
    $dataset = knowledgeRetrievalDataset();
    $returnPolicy = knowledgeArticle(
        $dataset,
        'returns-ka',
        'ნივთების დაბრუნების პოლიტიკა',
        'მომხმარებელს შეუძლია ნივთის დაბრუნება შეძენიდან 14 დღის განმავლობაში.',
        'ka',
    );
    knowledgeArticle(
        $dataset,
        'shipping-ka',
        'მიწოდების პირობები',
        'მიწოდება ხორციელდება სამუშაო დღეებში.',
        'ka',
    );

    $result = app(KnowledgeSearchService::class)->search($dataset, $query, 5);

    expect($result->records[0]->is($returnPolicy))->toBeTrue();
})->with([
    'ნივთის დაბრუნება',
    'დაბრუნების პირობები',
    'როგორ დავაბრუნო ნივთი',
    'პროდუქტის უკან დაბრუნება შეიძლება?',
]);

test('supports English, Russian, and mixed-language knowledge queries', function (string $title, string $content, string $query) {
    $dataset = knowledgeRetrievalDataset();
    $article = knowledgeArticle($dataset, 'article', $title, $content);

    $result = app(KnowledgeSearchService::class)->search($dataset, $query, 5);

    expect($result->records[0]->is($article))->toBeTrue();
})->with([
    'english' => ['Return Policy', 'Products can be returned within 30 days.', 'How do I return an item?'],
    'russian' => ['Политика возврата', 'Товар можно вернуть в течение 30 дней.', 'Как вернуть товар?'],
    'mixed' => ['CAMRY warranty', 'გარანტია მოქმედებს ერთწლიანი ვადით.', 'CAMRY გარანტია'],
]);

test('rejects a weak generic match instead of returning an unrelated policy', function () {
    $dataset = knowledgeRetrievalDataset();
    knowledgeArticle(
        $dataset,
        'shipping-ka',
        'მიწოდების პირობები',
        'მიწოდება ხორციელდება სამუშაო დღეებში.',
        'ka',
    );

    $result = app(KnowledgeSearchService::class)->search($dataset, 'ნივთების დაბრუნება', 5);

    expect($result->records)->toBe([]);
});
