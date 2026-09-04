// The overlay ships as a classic inline script, so it is loaded as text and
// evaluated rather than imported as a module.
import source from '../../src/Assets/overlay.js?raw'

const pristine = {
  fetch: globalThis.fetch,
  open: XMLHttpRequest.prototype.open,
  send: XMLHttpRequest.prototype.send,
}

/**
 * The script wraps fetch and XHR on the global object and refuses to run twice, so every load
 * starts from the untouched originals.
 */
export async function load(payload, { rawData, fetch } = {}) {
  globalThis.fetch = fetch ?? pristine.fetch
  XMLHttpRequest.prototype.open = pristine.open
  XMLHttpRequest.prototype.send = pristine.send
  delete window.__sonar

  const data = rawData ?? encode(payload)

  document.body.innerHTML =
    '<script type="application/json" id="sonar-data">' + data + '<\/script>'

  new Function(source)()

  await frame()

  return document.getElementById('sonar-console')
}

/**
 * Overlay::html() encodes the payload with JSON_HEX_TAG, so the harness has to do the same or
 * it tests its own escaping rather than the overlay's.
 */
function encode(payload) {
  return JSON.stringify(payload).replace(/</g, '\\u003C').replace(/>/g, '\\u003E')
}

/** The overlay renders inside requestAnimationFrame; wait for that to land. */
export function frame() {
  return new Promise((resolve) => requestAnimationFrame(() => setTimeout(resolve, 0)))
}

export function snapshot(overrides = {}) {
  return {
    queries: { count: 0, timeMs: 0, sources: {} },
    statements: [],
    time: { totalMs: 0, phpMs: 0 },
    memory: { peakMb: 0 },
    marks: {},
    meta: {},
    truncated: false,
    ...overrides,
  }
}

export function payload(server = {}, headerPrefix = 'X-Sonar-') {
  return { server: snapshot(server), headerPrefix }
}
