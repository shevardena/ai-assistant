<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreSourceFileRequest;
use App\Models\DataSource;
use App\Models\SourceFile;
use App\Models\Team;
use App\Services\ResourceStatusService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Inertia;
use RuntimeException;
use Throwable;

class SourceFileController extends Controller
{
    public function __construct(private readonly ResourceStatusService $resourceStatusService) {}

    /**
     * Store a source file for a file data source.
     */
    public function store(StoreSourceFileRequest $request, Team $currentTeam, DataSource $dataSource): RedirectResponse
    {
        Gate::authorize('update', $dataSource);

        $team = $this->currentTeam($request);
        $uploadedFile = $request->file('file');
        $disk = (string) config('source-files.disk', 'local');
        $extension = strtolower($uploadedFile->guessExtension() ?: $uploadedFile->getClientOriginalExtension());
        $directory = "source-files/{$team->id}/{$dataSource->id}";
        $storedPath = null;
        $checksum = hash_file('sha256', $uploadedFile->getPathname());

        if (! is_string($checksum)) {
            throw new RuntimeException('The uploaded file checksum could not be calculated.');
        }

        try {
            $storedPath = $uploadedFile->storeAs($directory, Str::uuid()->toString().'.'.$extension, $disk);

            if (! is_string($storedPath)) {
                throw new RuntimeException('The uploaded file could not be stored.');
            }

            $dataSource->files()->create([
                'uploaded_by' => $request->user()?->id,
                'disk' => $disk,
                'path' => $storedPath,
                'original_name' => $uploadedFile->getClientOriginalName(),
                'mime_type' => $uploadedFile->getMimeType(),
                'size_bytes' => $uploadedFile->getSize(),
                'checksum' => $checksum,
                'status' => 'uploaded',
                'metadata' => [
                    'extension' => $extension,
                ],
            ]);
            $this->resourceStatusService->markDataSourceReady($dataSource);
        } catch (Throwable $exception) {
            if (is_string($storedPath)) {
                Storage::disk($disk)->delete($storedPath);
            }

            throw $exception;
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Source file uploaded.')]);

        return to_route('data-sources.show', [
            'current_team' => $team->slug,
            'data_source' => $dataSource,
        ]);
    }

    /**
     * Delete a source file and its private physical file.
     */
    public function destroy(Request $request, Team $currentTeam, DataSource $dataSource, SourceFile $file): RedirectResponse
    {
        Gate::authorize('update', $dataSource);

        abort_unless($file->data_source_id === $dataSource->id, 404);

        $disk = Storage::disk($file->disk);

        if ($disk->exists($file->path) && ! $disk->delete($file->path)) {
            throw new RuntimeException('The source file could not be removed from storage.');
        }

        $file->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Source file deleted.')]);

        return to_route('data-sources.show', [
            'current_team' => $this->currentTeam($request)->slug,
            'data_source' => $dataSource,
        ]);
    }

    /**
     * Get the authenticated user's current team.
     */
    private function currentTeam(Request $request): Team
    {
        $team = $request->user()?->currentTeam;

        abort_if(! $team, 403);

        return $team;
    }
}
