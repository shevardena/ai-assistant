import { Head, Link, usePage } from '@inertiajs/react';
import { BookOpenText, FilePlus2, ExternalLink } from 'lucide-react';
import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { create as createRecord, edit as editRecord } from '@/routes/datasets/records';
import type { Paginated } from '@/types';

type KnowledgeDataset = {
    id: number;
    name: string;
    status: string;
    recordCount: number;
};

type KnowledgeRecord = {
    id: number;
    title: string;
    content: string;
    category: string | null;
    sourceUrl: string | null;
    language: string | null;
};

type Props = {
    dataset: KnowledgeDataset;
    records: Paginated<KnowledgeRecord>;
};

function excerpt(content: string): string {
    return content.length > 240 ? `${content.slice(0, 240)}…` : content;
}

export default function KnowledgeIndex({ dataset, records }: Props) {
    const { currentTeam } = usePage().props;

    if (!currentTeam) {
        return null;
    }

    return (
        <>
            <Head title="Company knowledge" />
            <div className="space-y-6 p-4 md:p-6">
                <div className="flex flex-col justify-between gap-4 sm:flex-row sm:items-start">
                    <div className="flex items-start gap-3">
                        <BookOpenText className="mt-1 size-5" />
                        <Heading
                            variant="small"
                            title="Company knowledge"
                            description="Private facts and policies the attached Bots may use to answer customer questions."
                        />
                    </div>
                    <Button asChild>
                        <Link href={createRecord([currentTeam.slug, dataset.id]).url}>
                            <FilePlus2 />
                            Add knowledge
                        </Link>
                    </Button>
                </div>

                <Card>
                    <CardHeader className="flex flex-row items-center justify-between gap-4">
                        <div>
                            <CardTitle>{dataset.name}</CardTitle>
                            <p className="text-sm text-muted-foreground">
                                {dataset.recordCount} active articles · indexed search
                            </p>
                        </div>
                        <Badge>{dataset.status}</Badge>
                    </CardHeader>
                    <CardContent className="space-y-4">
                        {records.data.length === 0 ? (
                            <div className="rounded-lg border border-dashed p-8 text-center text-sm text-muted-foreground">
                                No company knowledge yet. Add your first policy, FAQ, or business fact.
                            </div>
                        ) : (
                            records.data.map((record) => (
                                <div
                                    key={record.id}
                                    className="rounded-lg border p-4 transition-colors hover:bg-muted/30"
                                >
                                    <div className="flex items-start justify-between gap-4">
                                        <div className="min-w-0 space-y-2">
                                            <h2 className="font-medium">{record.title || 'Untitled knowledge'}</h2>
                                            <p className="whitespace-pre-wrap text-sm text-muted-foreground">
                                                {excerpt(record.content)}
                                            </p>
                                            <div className="flex flex-wrap gap-2 text-xs text-muted-foreground">
                                                {record.category ? <Badge variant="secondary">{record.category}</Badge> : null}
                                                {record.language ? <span>{record.language}</span> : null}
                                                {record.sourceUrl ? (
                                                    <a
                                                        href={record.sourceUrl}
                                                        target="_blank"
                                                        rel="noreferrer"
                                                        className="inline-flex items-center gap-1 underline"
                                                    >
                                                        Source <ExternalLink className="size-3" />
                                                    </a>
                                                ) : null}
                                            </div>
                                        </div>
                                        <Button variant="outline" size="sm" asChild>
                                            <Link href={editRecord([currentTeam.slug, dataset.id, record.id]).url}>
                                                Edit
                                            </Link>
                                        </Button>
                                    </div>
                                </div>
                            ))
                        )}

                        {records.last_page > 1 ? (
                            <div className="flex flex-wrap justify-center gap-2 pt-2">
                                {records.links.map((link, index) =>
                                    link.url ? (
                                        <Button key={`${link.label}-${index}`} variant={link.active ? 'default' : 'outline'} size="sm" asChild>
                                            <Link href={link.url}>{link.label.replace(/&laquo;|&raquo;/g, '')}</Link>
                                        </Button>
                                    ) : null,
                                )}
                            </div>
                        ) : null}
                    </CardContent>
                </Card>
            </div>
        </>
    );
}
