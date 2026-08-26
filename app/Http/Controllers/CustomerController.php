<?php

namespace App\Http\Controllers;

use App\Enums\CustomerCustomFieldType;
use App\Enums\CustomerStatus;
use App\Http\Requests\CustomerIndexRequest;
use App\Http\Requests\MergeCustomerRequest;
use App\Http\Requests\StoreCustomerCustomFieldRequest;
use App\Http\Requests\StoreCustomerFactRequest;
use App\Http\Requests\StoreCustomerIdentityRequest;
use App\Http\Requests\StoreCustomerNoteRequest;
use App\Http\Requests\StoreCustomerRequest;
use App\Http\Requests\StoreCustomerSegmentRequest;
use App\Http\Requests\StoreCustomerTagRequest;
use App\Http\Requests\UpdateCustomerCustomFieldRequest;
use App\Http\Requests\UpdateCustomerRequest;
use App\Http\Requests\UpdateCustomerSegmentRequest;
use App\Models\Customer;
use App\Models\CustomerCustomField;
use App\Models\CustomerFact;
use App\Models\CustomerIdentity;
use App\Models\CustomerSegment;
use App\Models\CustomerTag;
use App\Models\Team;
use App\Models\User;
use App\Services\Ai\AiException;
use App\Services\Customers\CustomerCustomFieldService;
use App\Services\Customers\CustomerFactService;
use App\Services\Customers\CustomerIdentityConflict;
use App\Services\Customers\CustomerIdentityService;
use App\Services\Customers\CustomerMergeService;
use App\Services\Customers\CustomerSegmentService;
use App\Services\Customers\CustomerService;
use App\Services\Customers\CustomerSummaryService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class CustomerController extends Controller
{
    public function __construct(
        private readonly CustomerService $customers,
        private readonly CustomerIdentityService $identities,
        private readonly CustomerFactService $facts,
        private readonly CustomerCustomFieldService $customFields,
        private readonly CustomerSegmentService $segments,
        private readonly CustomerMergeService $merges,
        private readonly CustomerSummaryService $summaries,
    ) {}

    public function index(CustomerIndexRequest $request, Team $currentTeam): Response
    {
        return Inertia::render('customers/index', $this->customers->index($currentTeam, $request->validated()));
    }

    public function show(Team $currentTeam, Customer $customer): Response|RedirectResponse
    {
        Gate::authorize('view', $customer);

        if ($customer->merged_into_customer_id !== null) {
            $destination = $customer->mergedInto()->firstOrFail();

            return redirect()->route('customers.show', [$currentTeam->slug, $destination]);
        }

        return Inertia::render('customers/show', $this->customers->detail($currentTeam, $customer));
    }

    public function store(StoreCustomerRequest $request, Team $currentTeam): RedirectResponse
    {
        try {
            $customer = $this->customers->create($currentTeam, $request->validated());
        } catch (CustomerIdentityConflict $exception) {
            throw ValidationException::withMessages([$exception->field => $exception->getMessage()]);
        }

        return redirect()->route('customers.show', [$currentTeam->slug, $customer]);
    }

    public function update(UpdateCustomerRequest $request, Team $currentTeam, Customer $customer): RedirectResponse
    {
        try {
            $this->customers->update($currentTeam, $customer, $request->validated());
        } catch (CustomerIdentityConflict $exception) {
            throw ValidationException::withMessages([$exception->field => $exception->getMessage()]);
        }

        return back()->with('success', 'Customer updated.');
    }

    public function note(StoreCustomerNoteRequest $request, Team $currentTeam, Customer $customer): RedirectResponse
    {
        $this->customers->addNote($currentTeam, $customer, $request->user(), (string) $request->validated('body'));

        return back()->with('success', 'Note added.');
    }

    public function tag(StoreCustomerTagRequest $request, Team $currentTeam): RedirectResponse
    {
        $this->customers->createTag($currentTeam, (string) $request->validated('name'));

        return back()->with('success', 'Tag created.');
    }

    public function identity(StoreCustomerIdentityRequest $request, Team $currentTeam, Customer $customer): RedirectResponse
    {
        $this->identities->add($currentTeam, $customer, $request->validated(), $request->user());

        return back()->with('success', 'Identity added.');
    }

    public function identityPrimary(Team $currentTeam, Customer $customer, CustomerIdentity $identity): RedirectResponse
    {
        $this->identities->setPrimary($currentTeam, $customer, $identity, request()->user());

        return back()->with('success', 'Primary identity updated.');
    }

    public function identityDestroy(Team $currentTeam, Customer $customer, CustomerIdentity $identity): RedirectResponse
    {
        $this->identities->remove($currentTeam, $customer, $identity, request()->user());

        return back()->with('success', 'Identity removed.');
    }

    public function fact(StoreCustomerFactRequest $request, Team $currentTeam, Customer $customer): RedirectResponse
    {
        $this->facts->save($currentTeam, $customer, $request->validated(), $request->user());

        return back()->with('success', 'Fact saved.');
    }

    public function factDestroy(Team $currentTeam, Customer $customer, CustomerFact $fact): RedirectResponse
    {
        $this->facts->delete($currentTeam, $customer, $fact, request()->user());

        return back()->with('success', 'Fact removed.');
    }

    public function summary(Team $currentTeam, Customer $customer): RedirectResponse
    {
        try {
            $this->summaries->generate($currentTeam, $customer, request()->user());
        } catch (AiException $exception) {
            throw ValidationException::withMessages(['summary' => $exception->getMessage()]);
        }

        return back()->with('success', 'Customer summary generated.');
    }

    public function fields(Team $currentTeam): Response
    {
        Gate::authorize('viewAny', Customer::class);

        return Inertia::render('customers/fields', ['fields' => $this->fieldPayload($currentTeam), 'types' => array_map(fn ($type): array => ['key' => $type->value, 'label' => ucfirst(str_replace('_', ' ', $type->value))], CustomerCustomFieldType::cases())]);
    }

    public function fieldStore(StoreCustomerCustomFieldRequest $request, Team $currentTeam): RedirectResponse
    {
        $this->customFields->create($currentTeam, $request->validated(), $request->user());

        return back()->with('success', 'Custom field created.');
    }

    public function fieldUpdate(UpdateCustomerCustomFieldRequest $request, Team $currentTeam, CustomerCustomField $field): RedirectResponse
    {
        $this->customFields->update($currentTeam, $field, $request->validated());

        return back()->with('success', 'Custom field updated.');
    }

    public function fieldStatus(Team $currentTeam, CustomerCustomField $field): RedirectResponse
    {
        $this->customFields->setActive($currentTeam, $field, ! $field->active);

        return back()->with('success', 'Custom field status updated.');
    }

    public function segments(Team $currentTeam): Response
    {
        Gate::authorize('viewAny', Customer::class);

        $segments = collect($this->segments->index($currentTeam))->map(fn (CustomerSegment $segment): array => ['id' => $segment->id, 'name' => $segment->name, 'description' => $segment->description, 'filterDefinition' => $segment->filter_definition, 'matchingCount' => (int) $segment->getAttribute('matching_count')])->values()->all();

        return Inertia::render('customers/segments', ['segments' => $segments, 'customers' => $this->customers->index($currentTeam, [])['customers'], 'filterOptions' => $this->segmentFilterOptions($currentTeam)]);
    }

    public function segmentStore(StoreCustomerSegmentRequest $request, Team $currentTeam): RedirectResponse
    {
        $this->segments->create($currentTeam, $request->validated(), $request->user());

        return back()->with('success', 'Segment created.');
    }

    public function segmentUpdate(UpdateCustomerSegmentRequest $request, Team $currentTeam, CustomerSegment $segment): RedirectResponse
    {
        $this->segments->update($currentTeam, $segment, $request->validated());

        return back()->with('success', 'Segment updated.');
    }

    public function segmentDestroy(Team $currentTeam, CustomerSegment $segment): RedirectResponse
    {
        $this->segments->delete($currentTeam, $segment);

        return back()->with('success', 'Segment deleted.');
    }

    public function mergePreview(Team $currentTeam, Customer $customer, Customer $destination): Response
    {
        Gate::authorize('update', $customer);

        return Inertia::render('customers/merge', ['preview' => $this->merges->preview($currentTeam, $customer, $destination)]);
    }

    public function merge(MergeCustomerRequest $request, Team $currentTeam, Customer $customer): RedirectResponse
    {
        $destination = Customer::query()->findOrFail((int) $request->validated('destination_id'));
        $merged = $this->merges->merge($currentTeam, $customer, $destination, $request->user());

        return redirect()->route('customers.show', [$currentTeam->slug, $merged])->with('success', 'Customers merged.');
    }

    /** @return array<string, mixed> */
    private function segmentFilterOptions(Team $team): array
    {
        return ['statuses' => array_map(fn ($status): array => ['key' => $status->value, 'label' => $status->label()], CustomerStatus::cases()), 'owners' => $team->members()->select('users.id', 'users.name')->orderBy('name')->get()->map(fn (User $user): array => ['id' => $user->id, 'name' => $user->name])->values()->all(), 'tags' => $team->customerTags()->select('id', 'name')->orderBy('name')->get()->map(fn (CustomerTag $tag): array => ['id' => $tag->id, 'name' => $tag->name])->values()->all(), 'customFields' => $this->fieldPayload($team, true)];
    }

    /** @return list<array<string, mixed>> */
    private function fieldPayload(Team $team, bool $activeOnly = false): array
    {
        return collect($this->customFields->fields($team, $activeOnly))->map(fn (CustomerCustomField $field): array => ['id' => $field->id, 'key' => $field->key, 'label' => $field->label, 'type' => $field->type, 'required' => $field->required, 'active' => $field->active, 'sortOrder' => $field->sort_order, 'options' => $field->options ?? []])->values()->all();
    }
}
