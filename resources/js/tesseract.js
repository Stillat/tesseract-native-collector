const bridgeUrl = '/_native/api/call';

async function bridgeCall(method, params = {}) {
    const response = await fetch(bridgeUrl, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ method, params }),
    });

    if (!response.ok) {
        throw new Error(`NativePHP bridge call failed with status ${response.status}.`);
    }

    return response.json();
}

export function connect(config = {}) {
    return bridgeCall('Tesseract.Connect', config);
}

export function ingest(envelopes = []) {
    return bridgeCall('Tesseract.Ingest', { envelopes });
}

export function status() {
    return bridgeCall('Tesseract.Status');
}

export function takeCommands() {
    return bridgeCall('Tesseract.TakeCommands');
}

export function respond(commandId, statusValue, detail = null, kind = null) {
    return bridgeCall('Tesseract.Respond', {
        commandId,
        kind,
        status: statusValue,
        detail,
    });
}
