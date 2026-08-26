import { Head, Link, router, usePage } from '@inertiajs/react';
import { ArrowLeft, ExternalLink } from 'lucide-react';
import { useState } from 'react';
import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { show as actionShow } from '@/routes/actions';
import {
    index as appointmentsIndex,
    update as appointmentsUpdate,
} from '@/routes/appointments';
import { show as conversationShow } from '@/routes/conversations';
import { show as customerShow } from '@/routes/customers';
import type { AppointmentDetailPageProps, AppointmentStatus } from '@/types';

function date(value: string | null): string {
    return value
        ? new Intl.DateTimeFormat(undefined, {
              dateStyle: 'full',
              timeStyle: 'short',
          }).format(new Date(value))
        : '—';
}
function variant(
    status: AppointmentStatus,
): 'default' | 'secondary' | 'destructive' | 'outline' {
    return status === 'completed'
        ? 'default'
        : status === 'cancelled' || status === 'no_show'
          ? 'destructive'
          : status === 'scheduled'
            ? 'secondary'
            : 'outline';
}

export default function AppointmentShow({
    appointment,
    statusOptions,
}: AppointmentDetailPageProps) {
    const { currentTeam } = usePage().props;
    const [status, setStatus] = useState<AppointmentStatus>(appointment.status);

    if (!currentTeam) {
        return null;
    }

    const save = () =>
        router.patch(
            appointmentsUpdate([currentTeam.slug, appointment.reference]).url,
            { status },
            { preserveScroll: true },
        );

    return (
        <>
            <Head
                title={`${appointment.customerName ?? 'Appointment'} · Appointments`}
            />
            <div className="flex flex-col gap-6 p-4 md:p-6">
                <Link
                    href={appointmentsIndex(currentTeam.slug).url}
                    className="flex w-fit items-center gap-2 text-sm text-muted-foreground hover:text-foreground"
                >
                    <ArrowLeft className="size-4" /> Back to Appointments
                </Link>
                <div className="flex flex-col justify-between gap-4 sm:flex-row sm:items-end">
                    <Heading
                        variant="small"
                        title={appointment.customerName ?? 'Appointment'}
                        description="Operational appointment details from a confirmed customer action."
                    />
                    <Badge variant={variant(appointment.status)}>
                        {appointment.statusLabel}
                    </Badge>
                </div>
                <div className="grid gap-6 xl:grid-cols-[minmax(0,1fr)_20rem]">
                    <div className="grid gap-6">
                        <Card>
                            <CardHeader className="border-b">
                                <CardTitle className="text-base">
                                    Appointment
                                </CardTitle>
                            </CardHeader>
                            <CardContent className="grid gap-4 p-4 md:grid-cols-2 md:p-6">
                                <div>
                                    <p className="text-sm text-muted-foreground">
                                        Starts
                                    </p>
                                    <p className="mt-1 font-medium">
                                        {date(appointment.startsAt)}
                                    </p>
                                </div>
                                {appointment.customer ? <div className="md:col-span-2"><p className="text-sm text-muted-foreground">Customer profile</p><Link className="mt-1 block font-medium hover:underline" href={customerShow([currentTeam.slug, appointment.customer.id]).url}>{appointment.customer.name}</Link></div> : null}
                                <div>
                                    <p className="text-sm text-muted-foreground">
                                        Timezone
                                    </p>
                                    <p className="mt-1 font-medium">
                                        {appointment.timezone ?? '—'}
                                    </p>
                                </div>
                                <div>
                                    <p className="text-sm text-muted-foreground">
                                        Name
                                    </p>
                                    <p className="mt-1 font-medium">
                                        {appointment.customerName ?? '—'}
                                    </p>
                                </div>
                                <div>
                                    <p className="text-sm text-muted-foreground">
                                        Email
                                    </p>
                                    <p className="mt-1 font-medium">
                                        {appointment.customerEmail ?? '—'}
                                    </p>
                                </div>
                                <div>
                                    <p className="text-sm text-muted-foreground">
                                        Phone
                                    </p>
                                    <p className="mt-1 font-medium">
                                        {appointment.customerPhone ?? '—'}
                                    </p>
                                </div>
                                <div>
                                    <p className="text-sm text-muted-foreground">
                                        Provider reference
                                    </p>
                                    <p className="mt-1 font-mono text-sm break-all">
                                        {appointment.providerReference ?? '—'}
                                    </p>
                                </div>
                                <div>
                                    <p className="text-sm text-muted-foreground">
                                        Created
                                    </p>
                                    <p className="mt-1 font-medium">
                                        {date(appointment.createdAt)}
                                    </p>
                                </div>
                            </CardContent>
                        </Card>
                    </div>
                    <div className="grid h-fit gap-6">
                        <Card>
                            <CardHeader className="border-b">
                                <CardTitle className="text-base">
                                    Internal status
                                </CardTitle>
                            </CardHeader>
                            <CardContent className="grid gap-3 p-4">
                                <select
                                    aria-label="Appointment status"
                                    value={status}
                                    onChange={(event) =>
                                        setStatus(
                                            event.target
                                                .value as AppointmentStatus,
                                        )
                                    }
                                    className="rounded-lg border bg-transparent px-3 py-2 text-sm"
                                >
                                    {statusOptions.map((option) => (
                                        <option
                                            key={option.key}
                                            value={option.key}
                                        >
                                            {option.label}
                                        </option>
                                    ))}
                                </select>
                                <Button onClick={save}>Save status</Button>
                            </CardContent>
                        </Card>
                        <Card>
                            <CardHeader className="border-b">
                                <CardTitle className="text-base">
                                    Related records
                                </CardTitle>
                            </CardHeader>
                            <CardContent className="grid gap-3 p-4 text-sm">
                                {appointment.conversation ? (
                                    <Link
                                        className="flex items-center justify-between hover:underline"
                                        href={
                                            conversationShow([
                                                currentTeam.slug,
                                                appointment.conversation
                                                    .reference,
                                            ]).url
                                        }
                                    >
                                        Conversation{' '}
                                        <ExternalLink className="size-4" />
                                    </Link>
                                ) : null}
                                {appointment.action ? (
                                    <Link
                                        className="flex items-center justify-between hover:underline"
                                        href={
                                            actionShow([
                                                currentTeam.slug,
                                                appointment.action.reference,
                                            ]).url
                                        }
                                    >
                                        Action history{' '}
                                        <ExternalLink className="size-4" />
                                    </Link>
                                ) : null}
                                {!appointment.conversation &&
                                !appointment.action ? (
                                    <p className="text-muted-foreground">
                                        No related records.
                                    </p>
                                ) : null}
                            </CardContent>
                        </Card>
                    </div>
                </div>
            </div>
        </>
    );
}
