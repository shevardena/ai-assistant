import type { Paginated } from './bots';
import type { TaskListItem } from './tasks';

export type CustomerStatus = 'new' | 'active' | 'qualified' | 'customer' | 'inactive';
export type CustomerCustomFieldType = 'text' | 'textarea' | 'number' | 'boolean' | 'date' | 'datetime' | 'select' | 'multi_select';

export type CustomerOption = { id: number; name: string };
export type CustomerCustomField = { id: number; key: string; label: string; type: CustomerCustomFieldType; required: boolean; active: boolean; sortOrder: number; options: string[] };
export type CustomerCustomFieldValue = { fieldId: number; key: string; label: string; type: CustomerCustomFieldType; value: string | number | boolean | string[] | null };
export type CustomerIdentity = { id: number; type: 'email' | 'phone' | 'channel_user'; value: string; provider: string | null; providerExternalId: string | null; isPrimary: boolean; isVerified: boolean };
export type CustomerFact = { id: number; key: string; value: string; valueType: string; source: string; lastConfirmedAt: string | null };
export type CustomerCounts = { conversations: number; leads: number; appointments: number; supportTickets: number; deals?: number; openDeals?: number; wonDeals?: number; lostDeals?: number; openTickets?: number; upcomingAppointments?: number };

export type CustomerListItem = { id: number; name: string; email: string | null; phone: string | null; company: string | null; status: CustomerStatus; statusLabel: string; owner: CustomerOption | null; lastActivityAt: string | null; updatedAt: string | null; counts: CustomerCounts };
export type CustomerFilters = { search: string | null; status: CustomerStatus | 'all'; ownerId: number | null; tag: number | null; segment: number | null };
export type CustomerPageProps = { filters: CustomerFilters; customers: Paginated<CustomerListItem>; statusOptions: Array<{ key: CustomerStatus; label: string }>; ownerOptions: CustomerOption[]; tagOptions: CustomerOption[]; segmentOptions: CustomerOption[] };

export type CustomerTimelineEvent = { type: string; title: string; description?: string | null; actor?: string | null; timestamp: string | null; url?: string | null };
export type CustomerProfile = { id: number; name: string; firstName: string | null; lastName: string | null; email: string | null; phone: string | null; company: string | null; status: CustomerStatus; statusLabel: string; source: string | null; owner: CustomerOption | null; tags: CustomerOption[]; identities: CustomerIdentity[]; customFields: CustomerCustomFieldValue[]; facts: CustomerFact[]; aiSummary: string | null; aiSummaryGeneratedAt: string | null; summaryStale: boolean; firstSeenAt: string | null; lastActivityAt: string | null; counts: CustomerCounts; deals: { id: number; title: string; status: string; valueAmount: string | null; currency: string; pipeline: { id: number; name: string }; stage: { id: number; name: string } }[]; notes: Array<{ id: number; body: string; author: string | null; createdAt: string | null }>; timeline: CustomerTimelineEvent[] };
export type CustomerDetailPageProps = { customer: CustomerProfile; tasks: TaskListItem[]; statusOptions: Array<{ key: CustomerStatus; label: string }>; ownerOptions: CustomerOption[]; tagOptions: CustomerOption[]; customFields: CustomerCustomField[]; segmentOptions: CustomerOption[] };

export type CustomerSegment = { id: number; name: string; description: string | null; filterDefinition: { filters: Array<{ field: string; key?: string; operator: string; value: string | number | boolean | string[] }> }; matchingCount: number };
export type CustomerSegmentPageProps = { segments: CustomerSegment[]; filterOptions: { statuses: Array<{ key: CustomerStatus; label: string }>; owners: CustomerOption[]; tags: CustomerOption[]; customFields: CustomerCustomField[] }; customers: Paginated<CustomerListItem> };
export type CustomerFieldPageProps = { fields: CustomerCustomField[]; types: Array<{ key: CustomerCustomFieldType; label: string }> };
export type CustomerMergePreview = { source: { id: number; name: string; email: string | null; phone: string | null }; destination: { id: number; name: string; email: string | null; phone: string | null }; conflicts: { identities: Array<{ type: string; value: string }>; customFields: Array<{ field: string | null; source: unknown; destination: unknown }>; facts: Array<{ key: string; source: string; destination: string }> }; blocked: boolean };
