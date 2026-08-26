/**
 * Being a classic script injected inline at the end of the document, it runs
 * before any deferred module bundle, which is what lets it wrap fetch/XHR in time.
 */
(function () {
  'use strict';

  if (window.__sonar) {
    return;
  }

  var STORAGE_KEY = 'sonar:open';
  var MAX_REQUESTS = 100;
  var IGNORED = /(@vite|@react-refresh|__vite|\.hot-update\.|\/node_modules\/)/;

  var payload = {};

  try {
    payload = JSON.parse(document.getElementById('sonar-data').textContent) || {};
  } catch (error) {
    payload = {};
  }

  var server = payload.server || {};
  var prefix = payload.headerPrefix || 'X-Sonar-';

  var state = { requests: [], client: {}, open: false };

  try {
    state.open = window.localStorage.getItem(STORAGE_KEY) === '1';
  } catch (error) {
    state.open = false;
  }

  function ms(value) {
    if (value === null || value === undefined || isNaN(value)) {
      return '—';
    }

    return value >= 1000 ? (value / 1000).toFixed(2) + ' s' : Math.round(value) + ' ms';
  }

  function grade(value, warn, bad) {
    if (value === null || value === undefined || isNaN(value)) {
      return 'sonar-dim';
    }

    return value >= bad ? 'sonar-bad' : value >= warn ? 'sonar-warn' : 'sonar-good';
  }

  function escapeHtml(value) {
    return String(value).replace(/[&<>"]/g, function (character) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[character];
    });
  }

  function shortUrl(url) {
    try {
      var parsed = new URL(url, window.location.href);

      return parsed.pathname + (parsed.search.length > 40 ? parsed.search.slice(0, 40) + '…' : parsed.search);
    } catch (error) {
      return String(url);
    }
  }

  function number(value) {
    var parsed = parseFloat(value);

    return isNaN(parsed) ? null : parsed;
  }

  function track(method, url, status, duration, header) {
    if (IGNORED.test(String(url))) {
      return;
    }

    state.requests.push({
      method: String(method || 'GET').toUpperCase(),
      url: shortUrl(url),
      status: status,
      ms: duration,
      queries: number(header(prefix + 'Queries')),
      dbMs: number(header(prefix + 'Query-Time')),
      serverMs: number(header(prefix + 'Time')),
    });

    if (state.requests.length > MAX_REQUESTS) {
      state.requests.shift();
    }

    render();
  }

  var nativeFetch = window.fetch;

  if (nativeFetch) {
    window.fetch = function (input, init) {
      var startedAt = performance.now();
      var method = (init && init.method) || (input && input.method) || 'GET';
      var url = typeof input === 'string' ? input : (input && input.url) || String(input);

      return nativeFetch.apply(this, arguments).then(
        function (response) {
          track(method, url, response.status, performance.now() - startedAt, function (name) {
            try {
              return response.headers.get(name);
            } catch (error) {
              return null;
            }
          });

          return response;
        },
        function (error) {
          track(method, url, 0, performance.now() - startedAt, function () {
            return null;
          });

          throw error;
        }
      );
    };
  }

  if (window.XMLHttpRequest) {
    var open = XMLHttpRequest.prototype.open;
    var send = XMLHttpRequest.prototype.send;

    XMLHttpRequest.prototype.open = function (method, url) {
      this.__sonar = { method: method, url: url };

      return open.apply(this, arguments);
    };

    XMLHttpRequest.prototype.send = function () {
      var xhr = this;
      var info = this.__sonar;

      if (info) {
        info.startedAt = performance.now();

        this.addEventListener('loadend', function () {
          track(info.method, info.url, xhr.status, performance.now() - info.startedAt, function (name) {
            try {
              return xhr.getResponseHeader(name);
            } catch (error) {
              return null;
            }
          });
        });
      }

      return send.apply(this, arguments);
    };
  }

  function readNavigation() {
    var entry = (performance.getEntriesByType('navigation') || [])[0];

    if (!entry) {
      return;
    }

    state.client.ttfb = entry.responseStart;
    state.client.dom = entry.domContentLoadedEventEnd || null;
    state.client.load = entry.loadEventEnd || null;
    render();
  }

  function observe(type, key) {
    try {
      new PerformanceObserver(function (list) {
        var entries = list.getEntries();

        if (entries.length) {
          state.client[key] = entries[entries.length - 1].startTime;
          render();
        }
      }).observe({ type: type, buffered: true });
    } catch (error) {
      return;
    }
  }

  observe('paint', 'fcp');
  observe('largest-contentful-paint', 'lcp');
  window.addEventListener('load', function () {
    setTimeout(readNavigation, 0);
  });
  readNavigation();

  var root = document.createElement('div');

  root.id = 'sonar-console';
  root.className = state.open ? 'is-open' : '';
  document.body.appendChild(root);

  function row(label, value, className) {
    return (
      '<div class="sonar-row"><span>' +
      escapeHtml(label) +
      '</span><span class="' +
      (className || '') +
      '">' +
      value +
      '</span></div>'
    );
  }

  function totals() {
    return state.requests.reduce(
      function (carry, request) {
        carry.ms += request.ms || 0;
        carry.queries += request.queries || 0;

        return carry;
      },
      { ms: 0, queries: 0 }
    );
  }

  /**
   * The most recent request this application answered, or null if there has
   * not been one.
   *
   * Only requests that came back with the server's own headers count. A call
   * to a third party reports no query count, and letting one of those be the
   * pill's reading would blank a number that was there a moment ago.
   */
  function lastAnswered() {
    for (var i = state.requests.length - 1; i >= 0; i--) {
      if (state.requests[i].queries !== null) {
        return state.requests[i];
      }
    }

    return null;
  }

  /**
   * What the pill reads: the last request the application answered once there
   * has been one, and the document itself until then.
   *
   * It used to read the document plus the sum of every request since, which is
   * right for a page that loads once and then calls an API in the background —
   * the totals are what you want there. It is wrong for a page that navigates
   * by fetch, where each request replaces what is on screen: the number climbs
   * with every step and never comes down, and a reading that only ever goes up
   * reads as a leak rather than as a cost per screen.
   *
   * The totals have not gone anywhere. They head the Requests section in the
   * panel, which is where a running total belongs — a glance at the corner of
   * the screen wants to know what the last thing cost.
   */
  function renderPill() {
    var recent = lastAnswered();
    var queries = recent ? recent.queries : (server.queries || {}).count || 0;
    var wall = recent ? recent.ms : state.client.load || state.client.dom || (server.time || {}).totalMs;

    return (
      '<div class="sonar-pill">' +
      '<button type="button" class="sonar-toggle" title="' +
      (recent ? 'Sonar — last request' : 'Sonar — this page') +
      '">' +
      '⚡ <b class="' +
      grade(wall, 1500, 3000) +
      '">' +
      ms(wall) +
      '</b> · <b class="' +
      grade(queries, 40, 100) +
      '">' +
      queries +
      ' SQL</b>' +
      (state.requests.length ? ' · <span class="sonar-dim">' + state.requests.length + ' req</span>' : '') +
      '</button>' +
      '<button type="button" class="sonar-close" title="Hide until reload">×</button>' +
      '</div>'
    );
  }

  function renderServer() {
    var queries = server.queries || {};
    var time = server.time || {};
    var sources = queries.sources || {};
    var marks = server.marks || {};
    var meta = server.meta || {};
    var breakdown = Object.keys(sources)
      .map(function (name) {
        return name + ' ' + sources[name].count;
      })
      .join(' · ');
    var html = '<h4>Server</h4>';

    html += row(
      'Queries',
      (queries.count || 0) + (breakdown ? ' <span class="sonar-dim">(' + escapeHtml(breakdown) + ')</span>' : ''),
      grade(queries.count, 40, 100)
    );
    html += row('Database', ms(queries.timeMs), grade(queries.timeMs, 100, 300));
    html += row('PHP', ms(time.phpMs), grade(time.phpMs, 300, 800));
    html += row('Total', ms(time.totalMs), grade(time.totalMs, 400, 1000));
    html += row('Memory', ((server.memory || {}).peakMb || 0) + ' MB');

    Object.keys(marks).forEach(function (name) {
      html += row(name, ms(marks[name]), grade(marks[name], 150, 400));
    });

    Object.keys(meta).forEach(function (name) {
      html += row(name, escapeHtml(String(meta[name])), 'sonar-dim');
    });

    return html;
  }

  function renderClient() {
    var html = '<h4>Browser</h4>';

    html += row('TTFB', ms(state.client.ttfb), grade(state.client.ttfb, 300, 800));
    html += row('DOMContentLoaded', ms(state.client.dom), grade(state.client.dom, 1200, 2500));
    html += row('Load', ms(state.client.load), grade(state.client.load, 1500, 3000));
    html += row('FCP', ms(state.client.fcp), grade(state.client.fcp, 1000, 2500));
    html += row('LCP', ms(state.client.lcp), grade(state.client.lcp, 2500, 4000));

    return html;
  }

  function renderRequests() {
    var api = totals();
    var html = '<h4>Requests (' + state.requests.length + ') · ' + api.queries + ' SQL · ' + ms(api.ms) + '</h4>';

    if (!state.requests.length) {
      return html + '<div class="sonar-empty">nothing yet</div>';
    }

    state.requests
      .slice()
      .reverse()
      .forEach(function (request) {
        html +=
          '<div class="sonar-req">' +
          '<span class="sonar-dim">' +
          escapeHtml(request.method) +
          '</span>' +
          '<span class="sonar-req-url" title="' +
          escapeHtml(request.url) +
          '">' +
          escapeHtml(request.url) +
          '</span>' +
          '<span class="' +
          (!request.status || request.status >= 400 ? 'sonar-bad' : 'sonar-dim') +
          '">' +
          (request.status || 'err') +
          '</span>' +
          '<span class="' +
          grade(request.ms, 300, 800) +
          '">' +
          ms(request.ms) +
          '</span>' +
          (request.queries === null
            ? ''
            : '<span class="' + grade(request.queries, 30, 80) + '">' + request.queries + ' SQL</span>') +
          '</div>';
      });

    return html;
  }

  function renderStatements() {
    var statements = server.statements || [];

    if (!statements.length) {
      return '';
    }

    var repeated = statements.filter(function (statement) {
      return statement.count > 1;
    });
    var shown = (repeated.length ? repeated : statements).slice(0, 8);
    var html = '<h4>' + (repeated.length ? 'Repeated queries' : 'Queries') + '</h4>';

    shown.forEach(function (query) {
      html +=
        '<div class="sonar-sql">' +
        '<span class="' +
        (query.count > 5 ? 'sonar-bad' : query.count > 1 ? 'sonar-warn' : 'sonar-dim') +
        '">×' +
        query.count +
        '</span> <span class="' +
        grade(query.timeMs, 50, 150) +
        '">' +
        ms(query.timeMs) +
        '</span> ' +
        escapeHtml(query.sql) +
        '</div>';
    });

    if (server.truncated) {
      html += '<div class="sonar-empty">list truncated (max_queries)</div>';
    }

    return html;
  }

  var frame = null;

  function render() {
    if (frame) {
      return;
    }

    frame = requestAnimationFrame(function () {
      frame = null;
      root.innerHTML =
        '<div class="sonar-panel">' +
        renderServer() +
        renderClient() +
        renderRequests() +
        renderStatements() +
        '</div>' +
        renderPill();

      root.querySelector('.sonar-toggle').addEventListener('click', function () {
        state.open = !state.open;
        root.className = state.open ? 'is-open' : '';

        try {
          window.localStorage.setItem(STORAGE_KEY, state.open ? '1' : '0');
        } catch (error) {
          return;
        }
      });

      root.querySelector('.sonar-close').addEventListener('click', function () {
        root.remove();
      });
    });
  }

  render();

  window.__sonar = { server: server, state: state, render: render };
})();
