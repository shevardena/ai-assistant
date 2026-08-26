import { useCallback, useEffect, useRef, useState } from 'react';

export type VoiceRecorderState =
    | 'idle'
    | 'requesting_permission'
    | 'recording'
    | 'uploading'
    | 'transcribing'
    | 'error';

type UseVoiceRecorderOptions = {
    transcribe: (blob: Blob, signal: AbortSignal) => Promise<string>;
    onTranscript: (text: string, revision: number) => void;
    composerRevision: number;
    maxDurationSeconds?: number;
};

const supportedMimeTypes = [
    'audio/webm;codecs=opus',
    'audio/webm',
    'audio/ogg;codecs=opus',
    'audio/ogg',
    'audio/mp4',
];

export function useVoiceRecorder({
    transcribe,
    onTranscript,
    composerRevision,
    maxDurationSeconds = 60,
}: UseVoiceRecorderOptions) {
    const [state, setState] = useState<VoiceRecorderState>('idle');
    const [elapsedSeconds, setElapsedSeconds] = useState(0);
    const [error, setError] = useState<string | null>(null);
    const recorderRef = useRef<MediaRecorder | null>(null);
    const streamRef = useRef<MediaStream | null>(null);
    const chunksRef = useRef<Blob[]>([]);
    const requestIdRef = useRef(0);
    const abortRef = useRef<AbortController | null>(null);
    const startedAtRef = useRef(0);
    const revisionRef = useRef(composerRevision);
    const onTranscriptRef = useRef(onTranscript);
    const transcribeRef = useRef(transcribe);

    useEffect(() => {
        revisionRef.current = composerRevision;
        onTranscriptRef.current = onTranscript;
        transcribeRef.current = transcribe;
    }, [composerRevision, onTranscript, transcribe]);

    const releaseMedia = useCallback(() => {
        recorderRef.current = null;
        streamRef.current?.getTracks().forEach((track) => track.stop());
        streamRef.current = null;
        chunksRef.current = [];
    }, []);

    const cancel = useCallback(() => {
        requestIdRef.current += 1;
        abortRef.current?.abort();
        abortRef.current = null;
        recorderRef.current?.stop();
        releaseMedia();
        setElapsedSeconds(0);
        setError(null);
        setState('idle');
    }, [releaseMedia]);

    const transcribeBlob = useCallback(
        async (blob: Blob, requestId: number, revision: number) => {
            const controller = new AbortController();
            abortRef.current = controller;
            setState('uploading');

            try {
                setState('transcribing');

                const text = await transcribeRef.current(
                    blob,
                    controller.signal,
                );

                if (requestId !== requestIdRef.current) {
                    return;
                }

                onTranscriptRef.current(text, revision);
                setError(null);
                setState('idle');
            } catch (exception) {
                if (
                    requestId !== requestIdRef.current ||
                    controller.signal.aborted
                ) {
                    return;
                }

                setError(
                    exception instanceof Error
                        ? exception.message
                        : 'We could not transcribe that recording. Please try again or type your message.',
                );
                setState('error');
            } finally {
                if (abortRef.current === controller) {
                    abortRef.current = null;
                }
            }
        },
        [],
    );

    const stop = useCallback(() => {
        const recorder = recorderRef.current;

        if (!recorder || recorder.state !== 'recording') {
            return;
        }

        const requestId = requestIdRef.current;
        const revision = revisionRef.current;
        recorder.onstop = () => {
            const chunks = chunksRef.current;
            releaseMedia();
            const blob = new Blob(chunks, {
                type: recorder.mimeType || 'audio/webm',
            });
            chunksRef.current = [];

            if (blob.size === 0) {
                setError('No audio was recorded. Please try again.');
                setState('error');

                return;
            }

            void transcribeBlob(blob, requestId, revision);
        };
        recorder.stop();
    }, [releaseMedia, transcribeBlob]);

    const start = useCallback(async () => {
        if (state !== 'idle' && state !== 'error') {
            return;
        }

        if (!navigator.mediaDevices?.getUserMedia || !window.MediaRecorder) {
            setError('Voice recording is not available in this browser.');
            setState('error');

            return;
        }

        setError(null);
        setState('requesting_permission');

        try {
            const stream = await navigator.mediaDevices.getUserMedia({
                audio: true,
            });
            const mimeType = supportedMimeTypes.find((type) =>
                MediaRecorder.isTypeSupported(type),
            );

            if (!mimeType) {
                stream.getTracks().forEach((track) => track.stop());

                throw new Error(
                    'Voice recording is not available in this browser.',
                );
            }

            const recorder = new MediaRecorder(stream, { mimeType });
            streamRef.current = stream;
            recorderRef.current = recorder;
            chunksRef.current = [];
            startedAtRef.current = Date.now();
            recorder.ondataavailable = (event) => {
                if (event.data.size > 0) {
                    chunksRef.current.push(event.data);
                }
            };
            recorder.start();
            setElapsedSeconds(0);
            setState('recording');
        } catch (exception) {
            releaseMedia();
            setError(
                exception instanceof DOMException &&
                    exception.name === 'NotAllowedError'
                    ? 'Microphone permission was denied. You can still type your message.'
                    : 'Voice recording is not available. You can still type your message.',
            );
            setState('error');
        }
    }, [releaseMedia, state]);

    useEffect(() => {
        if (state !== 'recording') {
            return;
        }

        const timer = window.setInterval(() => {
            const elapsed = Math.floor(
                (Date.now() - startedAtRef.current) / 1000,
            );

            setElapsedSeconds(elapsed);

            if (elapsed >= maxDurationSeconds) {
                stop();
            }
        }, 250);

        return () => window.clearInterval(timer);
    }, [maxDurationSeconds, state, stop]);

    useEffect(
        () => () => {
            requestIdRef.current += 1;
            abortRef.current?.abort();
            recorderRef.current?.stop();
            releaseMedia();
        },
        [releaseMedia],
    );

    return {
        state,
        elapsedSeconds,
        error,
        start,
        stop,
        cancel,
    };
}
