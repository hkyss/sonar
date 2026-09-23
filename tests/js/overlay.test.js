import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { frame, load, payload } from './overlay.js'

let clock = 0

beforeEach(() => {
  clock = 0
  vi.spyOn(performance, 'now').mockImplementation(() => clock)
  window.localStorage.clear()
  window.sessionStorage.clear()
  window.history.replaceState(null, '', '/')
})

afterEach(() => {
  vi.restoreAllMocks()
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
function responds(headers = {}, took = () => 0) {
  return vi.fn(async (url) => {
    clock += took(url)

    return new Response('{}', { status: 200, headers })
  })
}

function page(queries) {
  return payload({ queries: { count: queries, timeMs: 1, sources: {} } })
}

describe('the pill', () => {
  it('reads the page alone until the page makes a request', async () => {
    const root = await load(page(3))

    expect(root.querySelector('.sonar-page').textContent).toContain('3 SQL')
    expect(root.querySelector('.sonar-last')).toBeNull()
  })

  it('keeps the page beside the last request the application answered', async () => {
    const root = await load(page(3), { fetch: responds({ 'X-Sonar-Queries': '9' }, () => 48) })

    await window.fetch('/api/offers')
    await frame()

    expect(root.querySelector('.sonar-page').textContent).toContain('3 SQL')
    expect(root.querySelector('.sonar-last').textContent).toContain('48 ms · 9 SQL · 1 req')
  })

  it('reads the last request rather than a sum of them', async () => {
    const queries = { '/api/cart': '9', '/api/offers': '4' }
    const root = await load(page(3), {
      fetch: vi.fn(async (url) => new Response('{}', { headers: { 'X-Sonar-Queries': queries[url] } })),
    })

    await window.fetch('/api/cart')
    await window.fetch('/api/offers')
    await frame()

    expect(root.querySelector('.sonar-last').textContent).toContain('4 SQL · 2 req')
  })

  it('counts a third-party response without letting it be the reading', async () => {
    const root = await load(page(3), { fetch: responds() })

    await window.fetch('https://example.com/pixel')
    await frame()

    expect(root.querySelector('.sonar-page').textContent).toContain('3 SQL')
    expect(root.querySelector('.sonar-last').textContent).not.toContain('SQL')
    expect(root.querySelector('.sonar-last').textContent).toContain('1 req')
  })
})

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

  it('splits the round trip into server, database and network time', async () => {
    const root = await load(payload(), {
      fetch: responds({ 'X-Sonar-Queries': '12', 'X-Sonar-Query-Time': '30', 'X-Sonar-Time': '95' }, () => 150),
    })

    await window.fetch('/api/offers')
    await frame()

    expect(root.querySelector('.sonar-split').textContent).toBe('server 95 ms · db 30 ms · net 55 ms')
  })

  it('gives a third-party response no split', async () => {
    const root = await load(payload(), { fetch: responds({}, () => 150) })

    await window.fetch('https://example.com/pixel')
    await frame()

    expect(root.querySelector('.sonar-split')).toBeNull()
  })

  it('summarises the requests and names the slowest', async () => {
    const took = { '/api/a': 100, '/api/b': 500, '/api/c': 300, '/api/d': 200, '/api/e': 400 }
    const root = await load(payload(), { fetch: responds({ 'X-Sonar-Queries': '1' }, (url) => took[url]) })

    for (const url of Object.keys(took)) {
      await window.fetch(url)
    }

    await frame()

    const [figures, slowest] = root.querySelectorAll('.sonar-summary')

    expect(figures.textContent).toBe('median 300 ms · p95 480 ms · max 500 ms')
    expect(slowest.textContent).toContain('/api/b')
  })

  it('leaves a single request unsummarised', async () => {
    const root = await load(payload(), { fetch: responds({ 'X-Sonar-Queries': '1' }) })

    await window.fetch('/api/offers')
    await frame()

    expect(root.querySelector('.sonar-summary')).toBeNull()
  })

  it('skips dev-server noise', async () => {
    await load(payload(), { fetch: responds() })

    await window.fetch('/@vite/client')
    await frame()

    expect(window.__sonar.state.requests).toHaveLength(0)
  })
})

function visit(url, server = {}) {
  window.history.replaceState(null, '', url)

  return load(payload(server))
}

function rows(root) {
  return Array.from(root.querySelectorAll('div.sonar-grid')).map((row) =>
    Array.from(row.children).map((cell) => cell.textContent),
  )
}

describe('page history', () => {
  it('lists the pages this tab has loaded, newest first', async () => {
    await visit('/katalog.html', { queries: { count: 61, timeMs: 1, sources: {} }, time: { totalMs: 98, phpMs: 97 } })
    const root = await visit('/korzina.html', { queries: { count: 48, timeMs: 1, sources: {} } })

    expect(rows(root).slice(0, 2)).toEqual([
      ['/korzina.html', '0 ms', '48', '—'],
      ['/katalog.html', '98 ms', '61', '—'],
    ])
    expect(root.querySelector('.sonar-here').textContent).toContain('/korzina.html')
  })

  it('takes the median across the pages', async () => {
    await visit('/a.html', { queries: { count: 10, timeMs: 1, sources: {} }, time: { totalMs: 100, phpMs: 99 } })
    await visit('/b.html', { queries: { count: 60, timeMs: 1, sources: {} }, time: { totalMs: 600, phpMs: 599 } })
    const root = await visit('/c.html', {
      queries: { count: 20, timeMs: 1, sources: {} },
      time: { totalMs: 200, phpMs: 199 },
    })

    expect(rows(root).pop()).toEqual(['median', '200 ms', '20', '—'])
  })

  it('writes the load time down once the browser reports it', async () => {
    vi.spyOn(performance, 'getEntriesByType').mockReturnValue([
      { responseStart: 40, domContentLoadedEventEnd: 120, loadEventEnd: 300 },
    ])

    const root = await visit('/katalog.html')

    expect(rows(root)[0]).toEqual(['/katalog.html', '0 ms', '0', '300 ms'])
    expect(JSON.parse(window.sessionStorage.getItem('sonar:pages'))[0]).toMatchObject({ loadMs: 300 })
  })

  it('forgets every page but this one on clear', async () => {
    await visit('/a.html')
    await visit('/b.html')
    const root = await visit('/c.html')

    root.querySelector('.sonar-clear').click()
    await frame()

    expect(rows(root)).toEqual([['/c.html', '0 ms', '0', '—']])
    expect(JSON.parse(window.sessionStorage.getItem('sonar:pages'))).toHaveLength(1)
  })

  it('keeps the last twenty', async () => {
    for (let i = 1; i <= 22; i++) {
      await visit('/page-' + i + '.html')
    }

    const stored = JSON.parse(window.sessionStorage.getItem('sonar:pages'))

    expect(stored).toHaveLength(20)
    expect(stored[0].url).toBe('/page-3.html')
  })

  it('does not let a stored page carry markup', async () => {
    window.sessionStorage.setItem(
      'sonar:pages',
      JSON.stringify([{ url: '<img src=x onerror=alert(1)>', serverMs: '<b>1</b>', queries: 1, loadMs: 1 }]),
    )

    const root = await visit('/katalog.html')

    expect(root.querySelector('img')).toBeNull()
    expect(root.querySelector('div.sonar-grid b')).toBeNull()
    expect(root.textContent).toContain('<img src=x onerror=alert(1)>')
  })

  it('starts over when what is stored is not a history', async () => {
    window.sessionStorage.setItem('sonar:pages', '{not json')

    const root = await visit('/katalog.html')

    expect(rows(root)).toEqual([['/katalog.html', '0 ms', '0', '—']])
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
