import { beforeEach, describe, expect, it, vi } from 'vitest'
import { frame, load, payload } from './overlay.js'

beforeEach(() => {
  window.localStorage.clear()
})

describe('rendering', () => {
  it('mounts a console with a pill', async () => {
    const root = await load(payload({ queries: { count: 7, timeMs: 12.5, sources: {} } }))

    expect(root).not.toBeNull()
    expect(root.querySelector('.sonar-pill')).not.toBeNull()
    expect(root.querySelector('.sonar-toggle').textContent).toContain('7 SQL')
  })

  it('breaks the query count down per source', async () => {
    const root = await load(
      payload({
        queries: {
          count: 5,
          timeMs: 9,
          sources: { db: { count: 3, timeMs: 6 }, evo: { count: 2, timeMs: 3 } },
        },
      }),
    )

    expect(root.textContent).toContain('db 3')
    expect(root.textContent).toContain('evo 2')
  })

  it('counts a repeated statement instead of listing it twice', async () => {
    const root = await load(
      payload({
        statements: [{ sql: 'select * from `pages` where `id` = ?', source: 'db', count: 40, timeMs: 80, maxMs: 4 }],
      }),
    )

    expect(root.textContent).toContain('Repeated queries')
    expect(root.textContent).toContain('×40')
    expect(root.querySelectorAll('.sonar-sql')).toHaveLength(1)
  })

  it('reports a truncated statement list', async () => {
    const root = await load(
      payload({
        statements: [{ sql: 'select 1', source: 'db', count: 1, timeMs: 1, maxMs: 1 }],
        truncated: true,
      }),
    )

    expect(root.textContent).toContain('list truncated')
  })

  it('renders marks and metadata rows', async () => {
    const root = await load(payload({ marks: { ssr: 120 }, meta: { document: 42 } }))

    expect(root.textContent).toContain('ssr')
    expect(root.textContent).toContain('document')
    expect(root.textContent).toContain('42')
  })
})

describe('escaping', () => {
  it('does not let metadata inject markup', async () => {
    const root = await load(payload({ meta: { template: '<img src=x onerror=alert(1)>' } }))

    expect(root.querySelector('img')).toBeNull()
    expect(root.textContent).toContain('<img src=x onerror=alert(1)>')
  })

  it('does not let a statement inject markup', async () => {
    const root = await load(
      payload({
        statements: [{ sql: '<script>alert(1)</script>', source: 'db', count: 2, timeMs: 1, maxMs: 1 }],
      }),
    )

    expect(root.querySelector('script')).toBeNull()
    expect(root.textContent).toContain('<script>alert(1)</script>')
  })
})

describe('bad input', () => {
  it('still mounts when the payload is not valid json', async () => {
    const root = await load(null, { rawData: '{not json' })

    expect(root).not.toBeNull()
    expect(root.querySelector('.sonar-pill')).not.toBeNull()
  })

  it('still mounts when there is no payload element at all', async () => {
    const root = await load(null, { rawData: '' })

    expect(root).not.toBeNull()
  })
})

/** The overlay wraps whatever fetch it finds, so the stub goes in before it loads. */
function responds(headers = {}) {
  return vi.fn(async () => new Response('{}', { status: 200, headers }))
}

describe('request tracking', () => {
  it('records a fetch and reads the query count off the response', async () => {
    const root = await load(payload(), {
      fetch: responds({ 'X-Sonar-Queries': '12', 'X-Sonar-Query-Time': '30', 'X-Sonar-Time': '95' }),
    })

    await window.fetch('/api/offers')
    await frame()

    expect(window.__sonar.state.requests).toHaveLength(1)
    expect(window.__sonar.state.requests[0]).toMatchObject({ method: 'GET', url: '/api/offers', queries: 12 })
    expect(root.textContent).toContain('/api/offers')
  })

  it('lets the pill read the last request the application answered', async () => {
    const root = await load(payload({ queries: { count: 3, timeMs: 1, sources: {} } }), {
      fetch: responds({ 'X-Sonar-Queries': '9' }),
    })

    await window.fetch('/api/offers')
    await frame()

    expect(root.querySelector('.sonar-toggle').textContent).toContain('9 SQL')
  })

  it('ignores a third-party response when choosing what the pill reads', async () => {
    const root = await load(payload({ queries: { count: 3, timeMs: 1, sources: {} } }), {
      fetch: responds(),
    })

    await window.fetch('https://example.com/pixel')
    await frame()

    expect(root.querySelector('.sonar-toggle').textContent).toContain('3 SQL')
  })

  it('skips dev-server noise', async () => {
    await load(payload(), { fetch: responds() })

    await window.fetch('/@vite/client')
    await frame()

    expect(window.__sonar.state.requests).toHaveLength(0)
  })
})

describe('the open/closed state', () => {
  it('remembers that the panel was open', async () => {
    const root = await load(payload())

    root.querySelector('.sonar-toggle').click()
    await frame()

    expect(window.localStorage.getItem('sonar:open')).toBe('1')

    const reopened = await load(payload())

    expect(reopened.className).toBe('is-open')
  })

  it('hides the console until the page reloads', async () => {
    const root = await load(payload())

    root.querySelector('.sonar-close').click()

    expect(document.getElementById('sonar-console')).toBeNull()
  })
})
