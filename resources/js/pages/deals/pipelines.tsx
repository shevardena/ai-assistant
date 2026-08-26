import { Head, router, usePage } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import {
    destroy as destroyStage,
    store as storeStage,
} from '@/routes/pipeline-stages';
import { defaultMethod, destroy } from '@/routes/pipelines';
import { store } from '@/routes/pipelines';

type Pipeline = {
    id: number;
    name: string;
    is_default: boolean;
    stages: {
        id: number;
        name: string;
        semantic_type: string;
        probability: string | null;
    }[];
};
export default function PipelinesPage({
    pipelines,
}: {
    pipelines: Pipeline[];
}) {
    const { currentTeam } = usePage().props;

    if (!currentTeam) {
        return null;
    }

    return (
        <>
            <Head title="Pipelines" />
            <div className="flex flex-col gap-6 p-4 md:p-6">
                <div className="flex items-end justify-between">
                    <div>
                        <h1 className="text-2xl font-semibold">Pipelines</h1>
                        <p className="text-sm text-muted-foreground">
                            Configure stages and their close semantics.
                        </p>
                    </div>
                    <form
                        onSubmit={(event) => {
                            event.preventDefault();
                            const data = new FormData(event.currentTarget);
                            router.post(store(currentTeam.slug).url, data);
                        }}
                        className="flex gap-2"
                    >
                        <input
                            name="name"
                            required
                            placeholder="New pipeline"
                            className="rounded-lg border bg-transparent px-3 py-2 text-sm"
                        />
                        <Button type="submit">Add pipeline</Button>
                    </form>
                </div>
                {pipelines.map((pipeline) => (
                    <section
                        key={pipeline.id}
                        className="rounded-xl border p-4"
                    >
                        <div className="flex items-center justify-between">
                            <h2 className="font-medium">
                                {pipeline.name}{' '}
                                {pipeline.is_default ? (
                                    <span className="text-sm text-primary">
                                        · Default
                                    </span>
                                ) : null}
                            </h2>
                            <div className="flex gap-2">
                                {!pipeline.is_default ? (
                                    <Button
                                        size="sm"
                                        variant="outline"
                                        onClick={() =>
                                            router.post(
                                                defaultMethod([
                                                    currentTeam.slug,
                                                    pipeline.id,
                                                ]).url,
                                            )
                                        }
                                    >
                                        Make default
                                    </Button>
                                ) : null}
                                <Button
                                    size="sm"
                                    variant="outline"
                                    onClick={() =>
                                        router.delete(
                                            destroy([
                                                currentTeam.slug,
                                                pipeline.id,
                                            ]).url,
                                        )
                                    }
                                >
                                    Delete
                                </Button>
                            </div>
                        </div>
                        <div className="mt-4 grid gap-2">
                            {pipeline.stages.map((stage) => (
                                <div
                                    key={stage.id}
                                    className="flex items-center justify-between rounded-lg bg-muted/40 px-3 py-2 text-sm"
                                >
                                    <span>{stage.name}</span>
                                    <span className="text-muted-foreground">
                                        {stage.semantic_type} ·{' '}
                                        {stage.probability ?? '—'}%
                                    </span>
                                    <Button
                                        size="sm"
                                        variant="ghost"
                                        onClick={() =>
                                            router.delete(
                                                destroyStage([
                                                    currentTeam.slug,
                                                    stage.id,
                                                ]).url,
                                            )
                                        }
                                    >
                                        Remove
                                    </Button>
                                </div>
                            ))}
                        </div>
                        <form
                            onSubmit={(event) => {
                                event.preventDefault();
                                const data = new FormData(event.currentTarget);
                                router.post(
                                    storeStage([currentTeam.slug, pipeline.id])
                                        .url,
                                    data,
                                );
                            }}
                            className="mt-4 flex flex-wrap gap-2"
                        >
                            <input
                                name="name"
                                required
                                placeholder="New stage"
                                className="rounded-lg border bg-transparent px-3 py-2 text-sm"
                            />
                            <select
                                name="semantic_type"
                                defaultValue="open"
                                className="rounded-lg border bg-transparent px-3 py-2 text-sm"
                            >
                                <option value="open">Open</option>
                                <option value="won">Won</option>
                                <option value="lost">Lost</option>
                            </select>
                            <input
                                name="probability"
                                type="number"
                                min="0"
                                max="100"
                                placeholder="Probability"
                                className="w-28 rounded-lg border bg-transparent px-3 py-2 text-sm"
                            />
                            <Button type="submit" size="sm">
                                Add stage
                            </Button>
                        </form>
                    </section>
                ))}
            </div>
        </>
    );
}
