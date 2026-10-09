// Run the real diagnostic helpers and routes in isolated PHP/Wasm, without a database.
// Only copied application code and synthetic sessions are used; no live HTTP or mail.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { createRequire } = require('node:module');
const runtimeRequire = createRequire(path.resolve(__dirname, '../../lease/tests/package.json'));
const { PHP, FileLockManagerInMemory } = runtimeRequire('@php-wasm/universal');
const { loadNodeRuntime } = runtimeRequire('@php-wasm/node');
const root = path.resolve(__dirname, '..');
let php;
(async () => {
  php = new PHP(await loadNodeRuntime('8.3', {
    fileLockManager: new FileLockManagerInMemory(),
    emscriptenOptions: { processId: process.pid },
  }));
  const fixtureRoot = '/rental-diagnostics';
  for (const directory of ['app', 'public', 'storage', 'sessions']) php.mkdirTree(`${fixtureRoot}/${directory}`);
  for (const name of fs.readdirSync(path.join(root, 'app')).filter(name => name.endsWith('.php'))) {
    php.writeFile(`${fixtureRoot}/app/${name}`, fs.readFileSync(path.join(root, 'app', name)));
  }
  for (const relative of ['public/system-check.php', 'system-check.php']) {
    php.writeFile(`${fixtureRoot}/${relative}`, fs.readFileSync(path.join(root, relative)));
  }
  // This directory deliberately does not exist. Any unexpected DB access must fail.
  const env = {
    DB_PATH: `${fixtureRoot}/prohibited/database.sqlite`, APP_ENV: 'test', APP_DEBUG: '1',
    MAIL_TRANSPORT: 'log', SMTP_PASSWORD: 'synthetic-do-not-export-smtp-secret',
  };
  let checks = 0;
  function check(condition, label) { assert.ok(condition, label); checks++; }
  function equal(actual, expected, label) { assert.deepEqual(actual, expected, label); checks++; }
  async function run(code, options = {}) {
    const response = await php.run({
      code: `<?php ini_set('session.save_path','${fixtureRoot}/sessions');${code}`, env,
      $_SERVER: { SCRIPT_NAME: '/huur-module/system-check.php', REMOTE_ADDR: '192.0.2.1', HTTPS: 'on' },
      ...options,
    });
    assert.equal(response.errors, '', response.errors);
    assert.equal(response.exitCode, 0, response.text);
    return response;
  }
  async function helper(expression) {
    return JSON.parse((await run(`require '${fixtureRoot}/app/diagnostics.php'; echo json_encode(${expression}, JSON_THROW_ON_ERROR);`)).text);
  }
  function cookies(response, jar) {
    for (const cookie of response.headers['set-cookie'] || []) {
      const [name, ...value] = cookie.split(';')[0].split('=');
      jar[name] = value.join('=');
    }
  }
  async function session(role) {
    const response = await run(`require '${fixtureRoot}/app/bootstrap.php';
      $_SESSION['user']=['id'=>101,'name'=>'Synthetic administrator','email'=>'private-user@example.test','role'=>'${role}'];
      $_SESSION['private_marker']='synthetic-do-not-export-session-secret';
      $_SESSION['flash']=[['type'=>'success','message'=>'Synthetic single-use flash']];`);
    const jar = {};
    cookies(response, jar);
    return jar;
  }
  async function request({ jar = {}, method = 'GET', format = '', wrapper = false } = {}) {
    const response = await run(`require '${fixtureRoot}/${wrapper ? '' : 'public/'}system-check.php';`, {
      method, relativeUri: `/huur-module/system-check.php${format ? '?format=' + encodeURIComponent(format) : ''}`,
      protocol: 'https',
      $_SERVER: { SCRIPT_NAME: '/huur-module/system-check.php', REMOTE_ADDR: '192.0.2.1', HTTPS: 'on' },
      headers: { Cookie: Object.entries(jar).map(([key, value]) => `${key}=${value}`).join('; ') },
    });
    cookies(response, jar);
    check((response.headers['cache-control'] || []).some(value => /\bno-store\b/.test(value) && /\bprivate\b/.test(value)), `${method} ${format || 'html'} is private and never cached (${response.httpStatusCode})`);
    check((response.headers.pragma || []).includes('no-cache'), 'Legacy caches also receive no-cache');
    check(!php.fileExists(`${fixtureRoot}/prohibited/database.sqlite`) && !php.fileExists(`${fixtureRoot}/storage/database.sqlite`), 'Diagnostic requests do not create or migrate a database');
    return response;
  }

  equal(await helper("array_map('hosting_diagnostics_configured_enabled', ['1','ON','true',' yes ','0','OFF','false','no','',false,null,1,'unexpected'])"),
    [true, true, true, true, false, false, false, false, false, null, null, null, null],
    'Configuration distinguishes on, off and unreadable values');
  for (const parameters of ['false,false,null', 'true,false,null', 'true,true,false', 'true,true,null', 'true,true,[],true', 'true,true,[\'opcache_enabled\'=>true],true']) {
    const summary = await helper(`hosting_diagnostics_opcache_summary(${parameters})`);
    check(summary.enabled === null && summary.status === 'unavailable', `Missing or restricted status stays unknown (${parameters})`);
    check(summary.memory.used_mib === null && summary.statistics.hit_rate_percent === null, 'Unreadable counters remain unknown');
  }
  const configured = await helper('hosting_diagnostics_opcache_summary(true,true,false,false,true)');
  check(configured.configured_enabled === true && configured.enabled === null && configured.status_readable === false, 'Configured on is separate from unknown runtime status');
  const disabled = await helper("hosting_diagnostics_opcache_summary(true,true,['opcache_enabled'=>false],false,false)");
  check(disabled.enabled === false && disabled.status === 'disabled' && disabled.status_readable === true, 'Only explicit readable runtime false reports disabled');
  const measured = await helper(`hosting_diagnostics_opcache_summary(true,true,[
    'opcache_enabled'=>true,'cache_full'=>false,'restart_pending'=>true,'restart_in_progress'=>false,
    'memory_usage'=>['used_memory'=>1048576,'free_memory'=>2097152,'wasted_memory'=>0,'current_wasted_percentage'=>0],
    'opcache_statistics'=>['opcache_hit_rate'=>99.125,'num_cached_scripts'=>12,'hits'=>500,'misses'=>5,'oom_restarts'=>0,'hash_restarts'=>2,'manual_restarts'=>1],
    'scripts'=>['/private/account/passwords.php'=>['full_path'=>'/private/account/passwords.php']],
    'configuration'=>['password'=>'synthetic-do-not-export-opcache-secret'],
    'untrusted'=>'<script>alert(1)</script>'
  ],false,true)`);
  equal(measured.memory, { used_mib: 1, free_mib: 2, wasted_mib: 0, wasted_percent: 0 }, 'Cache memory uses MiB and preserves valid zero');
  equal(measured.statistics, { hit_rate_percent: 99.125, cached_scripts: 12, hits: 500, misses: 5, oom_restarts: 0, hash_restarts: 2, manual_restarts: 1 }, 'Useful cumulative counters remain available');
  check(measured.enabled === true && measured.cache_full === false && measured.restart_pending === true, 'Readable runtime booleans retain their meaning');
  check(!JSON.stringify(measured).match(/private\/account|synthetic-do-not-export|<script>|full_path|configuration/), 'Allowlist excludes script paths, credentials and unexpected raw fields');
  const invalid = await helper(`hosting_diagnostics_opcache_summary(true,true,[
    'opcache_enabled'=>'yes','cache_full'=>'false','restart_pending'=>1,
    'memory_usage'=>['used_memory'=>-1,'free_memory'=>'2097152','wasted_memory'=>INF,'current_wasted_percentage'=>101],
    'opcache_statistics'=>['opcache_hit_rate'=>NAN,'num_cached_scripts'=>-2,'hits'=>'9','misses'=>false]
  ])`);
  check(invalid.enabled === null && invalid.cache_full === null && invalid.restart_pending === null, 'Nonboolean status values are not coerced');
  check(Object.values(invalid.memory).every(value => value === null) && Object.values(invalid.statistics).every(value => value === null), 'Malformed, negative and nonfinite measurements cannot enter the report');
  equal(await helper('hosting_diagnostics_load_summary([0,0.25,1.75])'), {
    available: true, scope: 'host_or_container_not_hosting_account',
    averages: { one_minute: 0, five_minutes: 0.25, fifteen_minutes: 1.75 },
  }, 'Host load preserves zero without claiming account CPU percentages');
  for (const value of ['false', 'null', '[]', '[0,1]', '[0,1,2,3]', '[0,-1,2]', '[0,INF,2]', "['0',1,2]"]) {
    const load = await helper(`hosting_diagnostics_load_summary(${value})`);
    check(load.available === false && Object.values(load.averages).every(value => value === null), `Invalid or unavailable load stays unknown (${value})`);
  }
  const safeRead = JSON.parse((await run(`require '${fixtureRoot}/app/diagnostics.php';
    $outerWarnings=0; set_error_handler(function() use (&$outerWarnings) { $outerWarnings++; return true; });
    ob_start();
    $warning=hosting_diagnostics_read(function(){ trigger_error('/private/path/secret.php', E_USER_WARNING); return 'sensitive-value'; });
    $exception=hosting_diagnostics_read(function(){ throw new RuntimeException('synthetic-do-not-export-exception-secret'); });
    $output=ob_get_clean();
    trigger_error('outer handler check', E_USER_WARNING); restore_error_handler();
    echo json_encode([$warning,$exception,$output,$outerWarnings], JSON_THROW_ON_ERROR);`)).text);
  equal(safeRead, [{ ok: false, value: null }, { ok: false, value: null }, '', 1], 'Warnings and exceptions leak nothing and restore the original error handler');
  const collected = await helper('hosting_diagnostics_collect(microtime(true)-0.05,1.25)');
  check(collected.schema_version === 1 && collected.request.bootstrap_and_session_ms === 1.25 && collected.request.endpoint_until_sample_ms >= 49, 'Collector reports its real endpoint interval and supplied bootstrap interval');
  check(collected.limitations.some(text => text.includes('PHP-FPM')) && collected.host_load.scope === 'host_or_container_not_hosting_account', 'Report explains queue and shared-host limitations');
  check(!JSON.stringify(collected).includes('synthetic-do-not-export') && !JSON.stringify(collected).includes(fixtureRoot), 'Collector exports neither environment secrets nor filesystem paths');

  const admin = await session('admin');
  const staff = await session('staff');
  const finance = await session('finance');
  for (const method of ['GET', 'POST']) {
    const anonymous = await request({ method, format: 'json' });
    check(anonymous.httpStatusCode === 302 && anonymous.headers.location?.[0] === 'index.php?route=login' && anonymous.text === '', 'Anonymous requests require login before diagnostics');
    const denied = await request({ jar: staff, method, format: 'json' });
    check(denied.httpStatusCode === 403 && !denied.text.includes('schema_version'), 'Staff cannot receive diagnostics');
    const redirected = await request({ jar: finance, method, format: 'json' });
    check(redirected.httpStatusCode === 302 && redirected.headers.location?.[0] === 'cashbook.php' && redirected.text === '', 'Finance is redirected before diagnostics');
  }
  const report = await request({ jar: admin, format: 'json', wrapper: true });
  check(report.httpStatusCode === 200 && report.headers['content-type']?.[0].startsWith('application/json'), 'Admin JSON route works through the hosting wrapper');
  check(report.headers['content-disposition']?.[0] === 'attachment; filename="aab-hostingcontrole.json"', 'JSON is downloaded under a predictable safe filename');
  check((report.headers['server-timing'] || []).some(value => /^php;dur=\d+(\.\d+)?;desc="PHP endpoint until sample"$/.test(value)), 'Server-Timing labels the sampled endpoint interval');
  const data = JSON.parse(report.text);
  check(data.php.version.startsWith('8.3') && data.request.memory_used_mib > 0, 'Authenticated JSON includes actual PHP measurements');
  check(!report.text.match(/synthetic-do-not-export|private-user@example\.test|Synthetic administrator|csrf_token|rental-diagnostics|Synthetic single-use flash/), 'JSON excludes session, identity, paths, CSRF and environment secrets');
  const html = await request({ jar: admin });
  check(html.httpStatusCode === 200 && html.headers['content-type']?.[0].startsWith('text/html'), 'Admin HTML route renders');
  check(html.text.includes('Hostingcontrole') && html.text.includes('opcache.enable') && html.text.includes('Download meetrapport'), 'HTML exposes the OPcache setting and downloadable measurement');
  check(html.text.includes('geen CPU-percentages') && html.text.includes('PHP-FPM-wachtrijen'), 'HTML does not equate host load with account CPU or queue measurements');
  check(html.text.includes('Synthetic single-use flash'), 'Rendering preserves normal flash behavior before closing the session');
  const persisted = await run(`require '${fixtureRoot}/app/bootstrap.php'; echo json_encode(['token'=>$_SESSION['csrf_token']??null,'flash'=>$_SESSION['flash']??null]);`, {
    headers: { Cookie: Object.entries(admin).map(([key, value]) => `${key}=${value}`).join('; ') },
  });
  const savedSession = JSON.parse(persisted.text);
  check(typeof savedSession.token === 'string' && savedSession.token.length === 64 && savedSession.flash === null, 'Header logout token persists and flashes are consumed before session unlock');
  for (const format of ['', 'json']) {
    const head = await request({ jar: admin, method: 'HEAD', format });
    check(head.httpStatusCode === 200 && head.text === '', `Admin HEAD ${format || 'html'} has no response body`);
  }
  for (const method of ['POST', 'PUT', 'DELETE', 'PATCH']) {
    const denied = await request({ jar: admin, method, format: 'json' });
    check(denied.httpStatusCode === 405 && denied.headers.allow?.[0] === 'GET, HEAD' && !denied.text.includes('schema_version'), `${method} is rejected without a measurement payload`);
  }
  check(!php.fileExists(`${fixtureRoot}/storage/private/mail`), 'Diagnostics never sends or generates mail');
  console.log(`PASS: ${checks} hosting diagnostic checks for unknown states, redaction, admin access, cache headers, sessions and read-only routes using PHP 8.3.`);
})().catch(error => { console.error(error); process.exitCode = 1; }).finally(() => {
  try { php?.exit(); } catch {}
  process.exit(process.exitCode || 0);
});
