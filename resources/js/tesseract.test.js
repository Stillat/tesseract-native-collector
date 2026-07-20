import { afterEach, expect, it, vi } from 'vitest';

import { ingest } from './tesseract.js';

afterEach(() => {
    vi.unstubAllGlobals();
});

it('sends telemetry through the NativePHP bridge contract', async () => {
    const fetch = vi.fn().mockResolvedValue(
        new Response(JSON.stringify({ ok: true }), {
            status: 200,
            headers: { 'Content-Type': 'application/json' },
        }),
    );
    vi.stubGlobal('fetch', fetch);

    const envelopes = [{ kind: 'log', payload: { message: 'hello' } }];

    await expect(ingest(envelopes)).resolves.toEqual({ ok: true });
    expect(fetch).toHaveBeenCalledWith('/_native/api/call', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            method: 'Tesseract.Ingest',
            params: { envelopes },
        }),
    });
});
