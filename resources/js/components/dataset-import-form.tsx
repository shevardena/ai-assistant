import { Form } from '@inertiajs/react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { store } from '@/routes/datasets/imports';
import type { DatasetSourceFile } from '@/types';

type Props = {
    currentTeamSlug: string;
    datasetId: number;
    sourceFiles: DatasetSourceFile[];
};

function formatFileSize(sizeBytes: number | null): string {
    if (sizeBytes === null) {
        return '—';
    }

    if (sizeBytes < 1024) {
        return `${sizeBytes} B`;
    }

    if (sizeBytes < 1024 * 1024) {
        return `${(sizeBytes / 1024).toFixed(1)} KB`;
    }

    return `${(sizeBytes / (1024 * 1024)).toFixed(1)} MB`;
}

export default function DatasetImportForm({
    currentTeamSlug,
    datasetId,
    sourceFiles,
}: Props) {
    const availableFiles = sourceFiles;

    return (
        <Form
            {...store.form([currentTeamSlug, datasetId])}
            options={{ preserveScroll: true }}
            className="grid gap-4 rounded-lg border bg-muted/20 p-4 sm:grid-cols-[minmax(0,1fr)_auto] sm:items-end"
        >
            {({ errors, processing }) => (
                <>
                    <div className="grid gap-2">
                        <Label htmlFor="source_file_id">Source file</Label>
                        <select
                            id="source_file_id"
                            name="source_file_id"
                            required
                            disabled={availableFiles.length === 0}
                            defaultValue=""
                            className="flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 disabled:cursor-not-allowed disabled:opacity-50 dark:bg-input/30"
                        >
                            <option value="" disabled>
                                {availableFiles.length > 0
                                    ? 'Select an uploaded file'
                                    : 'Upload a source file first'}
                            </option>
                            {availableFiles.map((sourceFile) => (
                                <option
                                    key={sourceFile.id}
                                    value={sourceFile.id}
                                >
                                    {sourceFile.originalName} ·{' '}
                                    {formatFileSize(sourceFile.sizeBytes)}
                                </option>
                            ))}
                        </select>
                        <p className="text-sm text-muted-foreground">
                            Importing uses the configured field mappings and
                            does not modify the uploaded file.
                        </p>
                        <InputError message={errors.source_file_id} />
                    </div>
                    <Button
                        type="submit"
                        disabled={processing || availableFiles.length === 0}
                    >
                        {processing ? 'Importing...' : 'Run import'}
                    </Button>
                </>
            )}
        </Form>
    );
}
