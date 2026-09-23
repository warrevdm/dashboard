// Isolated PHP 8.3 requests, temporary configuration and processing sentinels.
// Never connects to a live database, partner portal or production configuration.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const crypto = require('node:crypto');
const { PHP } = require('@php-wasm/universal');
const { loadNodeRuntime, createNodeFsMountHandler } = require('@php-wasm/node');
const root = path.resolve(__dirname, '..');
const temporary = fs.mkdtempSync(path.join(os.tmpdir(), 'lease-security-'));
const fixture = path.join(temporary, 'lease');
const configFile = path.join(temporary, 'auth.php');
const marker = path.join(temporary, 'processing-reached');
const key = 'synthetic-test-access-key-only';
const phpString = value => "'" + value.replaceAll('\\', '\\\\').replaceAll("'", "\\'") + "'";
let checks = 0;
let activePHP;
function check(condition, label) { assert.ok(condition, label); checks++; }
function files(dir) { return fs.readdirSync(dir, {withFileTypes: true}).flatMap(e => e.isDirectory() && e.name !== 'node_modules' ? files(path.join(dir, e.name)) : e.isFile() ? [path.join(dir, e.name)] : []); }
const postPages = [
  'archive-contract.php', 'restore-contract.php', 'update-contract.php',
  'delete-mapping-template.php', 'rollback-import.php', 'log-budget-conversion.php',
  'log-mail-action.php', 'import-preview.php', 'import-review.php', 'import-process.php',
  'import-source-o2o.php', 'import-source-joule.php', 'import-source-cyclobility.php',
];
async function newPHP() {
  const php = new PHP(await loadNodeRuntime('8.3', {emscriptenOptions: {processId: process.pid}}));
  activePHP = php;
  php.mkdirTree(temporary);
  await php.mount(temporary, createNodeFsMountHandler(temporary));
  return php;
}
(async () => {
  for (const folder of ['app', 'public', 'scripts']) fs.cpSync(path.join(root, folder), path.join(fixture, folder), {recursive: true});
  fs.mkdirSync(path.join(fixture, 'config'));
  fs.copyFileSync(path.join(root, 'config/auth.php'), path.join(fixture, 'config/auth.php'));
  fs.mkdirSync(path.join(temporary, 'sessions'));
  const php = await newPHP();
  const env = {AAB_LEASE_AUTH_FILE: configFile};
  const prelude = `ini_set('session.save_path', ${phpString(path.join(temporary, 'sessions'))});`;
  async function run(code, options = {}) {
    const response = await php.run({
      code: `<?php ${prelude}\n${code}`, env,
      $_SERVER: {REMOTE_ADDR: '192.0.2.1', HTTPS: 'on', SERVER_PORT: '443'},
      ...options,
    });
    assert.equal(response.errors, '', 'No PHP warning/fatal: ' + response.errors);
    return response;
  }
  const initial = await run(`echo json_encode(['hash'=>password_hash(${phpString(key)}, PASSWORD_DEFAULT), 'version'=>PHP_VERSION]);`);
  const {hash, version} = JSON.parse(initial.text);
  let secret = crypto.randomBytes(32).toString('hex');
  function writeConfig(currentHash = hash) {
    fs.writeFileSync(configFile, `<?php return ['access_key_hash'=>${phpString(currentHash)}, 'cookie_secret'=>${phpString(secret)}, 'auth_storage_dir'=>${phpString(path.join(temporary, 'auth-state'))}];`);
  }
  writeConfig();
  fs.writeFileSync(marker, '');
  // Parse every application PHP file, without executing it or reading private config.
  for (const file of files(root).filter(p => p.endsWith('.php') && !p.includes('/vendor/') && !p.endsWith('.local.php') && !p.endsWith('/config.production.php'))) {
    await run(`token_get_all(base64_decode('${fs.readFileSync(file).toString('base64')}'), TOKEN_PARSE);`);
    checks++;
    if (file.includes(path.sep + 'public' + path.sep)) {
      for (const form of fs.readFileSync(file, 'utf8').matchAll(/<form\b(?:[^>"']|"[^"]*"|'[^']*')*>[\s\S]*?<\/form>/gi)) {
        if (/method="POST"/i.test(form[0])) check(/csrf_token|Auth::csrfField\(\)/.test(form[0]), `Source POST form has a token: ${path.basename(file)}`);
      }
    }
  }
  const base = path.join(fixture, 'public');
  function cookieHeader(jar) { return Object.entries(jar).map(([k,v]) => `${k}=${v}`).join('; '); }
  async function request(page, {jar = {}, method = 'GET', data = {}, ip = '192.0.2.1', headers = {}} = {}) {
    const response = await run(`require ${phpString(path.join(base, page))};`, {
      method, relativeUri: `/lease/public/${page}`, protocol: 'https',
      body: method === 'POST' ? Buffer.from(new URLSearchParams(data).toString()) : undefined,
      headers: {'Content-Type': 'application/x-www-form-urlencoded', Cookie: cookieHeader(jar), ...headers},
      $_SERVER: {REMOTE_ADDR: ip, HTTPS: 'on', SERVER_PORT: '443', PHP_SELF: `/lease/public/${page}`},
    });
    for (const c of response.headers['set-cookie'] || []) {
      const [name, ...parts] = c.split(';')[0].split('=');
      if (parts.join('=') === 'deleted' || /max-age=0/i.test(c)) delete jar[name];
      else jar[name] = parts.join('=');
    }
    return response;
  }
  function token(response) {
    const match = response.text.match(/name="csrf_token" value="([a-f0-9]{64})"/);
    assert.ok(match, 'A rendered form includes a CSRF token');
    return match[1];
  }
  async function login({remember = false, accessKey = key, ip = '192.0.2.1', jar = {}} = {}) {
    const page = await request('login.php', {jar, ip});
    const before = jar.aab_lease_session;
    const response = await request('login.php', {jar, ip, method: 'POST', data: {
      csrf_token: token(page), access_key: accessKey, ...(remember ? {remember: '1'} : {}),
    }});
    return {jar, response, before};
  }
  const allProtected = fs.readdirSync(base).filter(p => p.endsWith('.php') && p !== 'login.php');
  for (const page of allProtected) {
    const response = await request(page);
    check(response.httpStatusCode === 302 && response.headers.location?.[0].startsWith('login.php'), `Anonymous GET blocked: ${page}`);
  }
  for (const page of [...postPages, 'update-connectors.php']) {
    const response = await request(page, {method: 'POST', data: {id: '1', uploaded_file: 'probe.csv'}});
    check(response.httpStatusCode === 302, `Anonymous POST blocked: ${page}`);
  }
  check(!fs.existsSync(path.join(fixture, 'storage')), 'Unauthenticated imports and updates created no storage');
  // Sentinels make any access beyond the guard visible, without external services.
  const sentinel = `file_put_contents(${phpString(marker)}, 'reached'); http_response_code(204); exit;`;
  fs.writeFileSync(path.join(fixture, 'app/Database.php'), `<?php require_once __DIR__.'/bootstrap.php'; class Database { public static function connect(): PDO { ${sentinel} } }`);
  fs.mkdirSync(path.join(fixture, 'vendor'));
  fs.writeFileSync(path.join(fixture, 'vendor/autoload.php'), `<?php ${sentinel}`);
  const signedIn = await login();
  check(signedIn.response.httpStatusCode === 303, 'Correct key logs in');
  check(signedIn.before !== signedIn.jar.aab_lease_session, 'Login rotates session ID');
  check((signedIn.response.headers['set-cookie'] || []).some(c => /aab_lease_session=/.test(c) && /secure/i.test(c) && /httponly/i.test(c) && /samesite=lax/i.test(c)), 'Session cookie has secure attributes');
  const jar = signedIn.jar;
  const upload = await request('upload.php', {jar});
  check(upload.httpStatusCode === 200 && upload.text.includes('import-preview.php'), 'Upload page remains usable after login');
  const csrf = token(upload);
  const loginJar = {};
  await request('login.php', {jar: loginJar});
  const forgedLogin = await request('login.php', {jar: loginJar, method: 'POST', data: {access_key: key}});
  check(forgedLogin.httpStatusCode === 200 && forgedLogin.text.includes('Je sessie is verlopen'), 'Login without CSRF cannot authenticate');
  check((await request('upload.php', {jar: loginJar})).httpStatusCode === 302, 'Forged login leaves browser unauthenticated');
  for (const page of [...postPages, 'update-connectors.php', 'logout.php']) {
    for (const data of [{}, {csrf_token: 'wrong'}, {'csrf_token[]': csrf}]) {
      fs.writeFileSync(marker, '');
      const response = await request(page, {jar, method: 'POST', data});
      check(response.httpStatusCode === 403 && fs.readFileSync(marker, 'utf8') === '', `CSRF rejected before processing: ${page}`);
    }
  }
  for (const page of [...postPages, 'logout.php']) {
    const response = await request(page, {jar});
    check(response.httpStatusCode === 405 && response.headers.allow?.[0] === 'POST', `GET cannot mutate: ${page}`);
  }
  check(!fs.existsSync(path.join(fixture, 'storage')), 'Invalid CSRF created no uploads or job directories');
  for (const page of postPages) {
    fs.writeFileSync(marker, '');
    const response = await request(page, {jar, method: 'POST', data: {csrf_token: csrf, id: '1'}});
    check(response.httpStatusCode === 204 && fs.readFileSync(marker, 'utf8') === 'reached', `Valid form reaches processing: ${page}`);
  }
  // Every real rendered POST form on upload + navigation carries the current token.
  for (const form of upload.text.matchAll(/<form\b[^>]*>[\s\S]*?<\/form>/gi)) {
    if (/method="POST"/i.test(form[0])) check(form[0].includes(`value="${csrf}"`), 'Rendered POST form includes session token');
  }
  for (const partner of ['o2o', 'joule', 'cyclobility']) {
    check(upload.text.includes(`method="POST" action="import-source-${partner}.php"`), `${partner} navigation submits a form`);
  }
  const signedRemember = await login({remember: true});
  const remembered = {...signedRemember.jar};
  delete remembered.aab_lease_session;
  check((await request('upload.php', {jar: remembered})).httpStatusCode === 200, 'Remember-cookie restores a session');
  const tampered = {aab_lease_remember: signedRemember.jar.aab_lease_remember.slice(0, -1) + 'z'};
  check((await request('upload.php', {jar: tampered})).httpStatusCode === 302, 'Tampered remember-cookie rejected');
  const expiry = Math.floor(Date.now()/1000) + 86400;
  const nonce = 'a'.repeat(32);
  const oldPayload = `${expiry}.${nonce}`;
  const legacy = {aab_lease_remember: oldPayload + '.' + crypto.createHmac('sha256', secret).update(oldPayload).digest('hex')};
  check((await request('upload.php', {jar: legacy})).httpStatusCode === 302, 'Signed legacy-format cookie rejected');
  const oldSession = {...signedRemember.jar};
  const oldRemember = {aab_lease_remember: signedRemember.jar.aab_lease_remember};
  secret = crypto.randomBytes(32).toString('hex'); writeConfig();
  check((await request('upload.php', {jar: oldSession})).httpStatusCode === 302, 'Cookie-secret rotation revokes existing session');
  check((await request('upload.php', {jar: oldRemember})).httpStatusCode === 302, 'Cookie-secret rotation revokes remember-cookie');
  const beforePasswordRotation = await login({remember: true});
  const newHash = JSON.parse((await run("echo json_encode(password_hash('different-synthetic-key', PASSWORD_DEFAULT));")).text);
  writeConfig(newHash);
  check((await request('upload.php', {jar: {...beforePasswordRotation.jar}})).httpStatusCode === 302, 'Access-key rotation revokes existing session');
  check((await request('upload.php', {jar: {aab_lease_remember: beforePasswordRotation.jar.aab_lease_remember}})).httpStatusCode === 302, 'Access-key rotation revokes remember-cookie');
  writeConfig();
  const beforeMissing = await login(); fs.renameSync(configFile, configFile + '.saved');
  check((await request('upload.php', {jar: beforeMissing.jar})).httpStatusCode === 302, 'Missing configuration fails closed with existing session');
  const noConfig = await request('login.php');
  check(noConfig.text.includes('nog niet geconfigureerd'), 'Missing configuration gives a useful login message');
  fs.renameSync(configFile + '.saved', configFile);
  // Seed authentic PHP sessions, never deployed with the application.
  fs.writeFileSync(path.join(base, 'expire-session.php'), `<?php require __DIR__.'/../app/Auth.php'; Auth::boot(); $_SESSION['aab_last_seen']=time()-30000;`);
  fs.writeFileSync(path.join(base, 'legacy-session.php'), `<?php require __DIR__.'/../app/Auth.php'; Auth::boot(); $_SESSION=['aab_authenticated'=>true,'aab_login_at'=>time()];`);
  const idle = await login(); await request('expire-session.php', {jar: idle.jar});
  check((await request('upload.php', {jar: idle.jar})).httpStatusCode === 302, 'Idle session expires without remember-cookie');
  fs.writeFileSync(path.join(base, 'absolute-expiry.php'), `<?php require __DIR__.'/../app/Auth.php'; Auth::boot(); $_SESSION['aab_login_at']=time()-90000; $_SESSION['aab_last_seen']=time();`);
  const absolute = await login(); await request('absolute-expiry.php', {jar: absolute.jar});
  check((await request('upload.php', {jar: absolute.jar})).httpStatusCode === 302, 'Absolute session lifetime is enforced even when recently active');
  const oldJar = {}; await request('legacy-session.php', {jar: oldJar});
  check((await request('upload.php', {jar: oldJar})).httpStatusCode === 302, 'Legacy authenticated session flag rejected');
  // Five fresh cookie jars share the server-side IP counter.
  for (let i = 0; i < 5; i++) await login({accessKey: 'wrong-key', ip: '192.0.2.25'});
  const locked = await login({ip: '192.0.2.25'});
  check(locked.response.httpStatusCode === 200 && locked.response.text.includes('Te veel mislukte pogingen'), 'New browser/session cannot bypass login throttling');
  check((await login({ip: '192.0.2.26'})).response.httpStatusCode === 303, 'Other client is not globally locked out');
  const lockedToken = token(await request('login.php', {jar: locked.jar, ip: '192.0.2.25'}));
  const forwarded = await request('login.php', {jar: locked.jar, ip: '192.0.2.25', method: 'POST', data: {csrf_token: lockedToken, access_key: key}, headers: {'X-Forwarded-For': '192.0.2.99'}});
  check(forwarded.text.includes('Te veel mislukte pogingen'), 'Spoofed forwarded IP cannot bypass throttling');
  const finalLogin = await login();
  const finalToken = token(await request('upload.php', {jar: finalLogin.jar}));
  check((await request('logout.php', {jar: finalLogin.jar, method: 'POST', data: {csrf_token: finalToken}})).httpStatusCode === 303, 'Valid logout works');
  check((await request('upload.php', {jar: finalLogin.jar})).httpStatusCode === 302, 'Logged-out browser loses access');
  const webSetup = await run(`require ${phpString(path.join(fixture, 'scripts/configure-auth.php'))};`);
  check(webSetup.httpStatusCode === 404, 'Credential generator cannot run through the web');
  php.exit();
  // Exercise the real installer through CLI; never print its generated credentials.
  const generated = path.join(temporary, 'generated.php');
  const installer = path.join(fixture, 'scripts/configure-auth.php');
  async function configure(extra = [], {output = generated, script = installer} = {}) {
    const cli = await newPHP();
    const result = await cli.cli(['php', script, `--output=${output}`, ...extra]);
    const response = {status: await result.exitCode, text: await result.stdoutText, errors: await result.stderrText};
    try { cli.exit(); } catch {}
    return response;
  }
  check((await configure()).status === 0 && fs.existsSync(generated), 'CLI generates private configuration');
  const first = fs.readFileSync(generated, 'utf8');
  check((await configure()).status !== 0 && fs.readFileSync(generated, 'utf8') === first, 'CLI refuses accidental overwrite');
  check((await configure(['--rotate'])).status === 0 && fs.readFileSync(generated, 'utf8') !== first, 'CLI explicitly rotates credentials');
  const spacedDirectory = path.join(temporary, 'OneDrive - Example Company', 'Lease configuration');
  fs.mkdirSync(spacedDirectory, {recursive: true});
  const spacedOutput = path.join(spacedDirectory, 'auth.local.php');
  const spacedResult = await configure([], {output: spacedOutput});
  check(spacedResult.status === 0 && spacedResult.errors === '' && fs.existsSync(spacedOutput), 'CLI accepts a destination with spaces');
  const missingResult = await configure([], {output: path.join(temporary, 'missing-directory', 'auth.local.php')});
  check(missingResult.status === 1 && missingResult.text === '' && missingResult.errors.includes('De doelmap bestaat niet'), 'Missing destination is diagnosed without exposing credentials');
  // Override filesystem calls only in isolated copies, to simulate platform metadata and I/O failures.
  function simulatedInstaller(name, functions) {
    const script = path.join(fixture, 'scripts', name + '.php');
    fs.writeFileSync(script, '<?php namespace AuthSetupFixture; ' + functions + '\n?>\n' + fs.readFileSync(installer, 'utf8'));
    return script;
  }
  const metadataScript = simulatedInstaller('readonly-metadata', 'function is_writable($path) { return false; }');
  const metadataOutput = path.join(spacedDirectory, 'metadata.php');
  const metadataResult = await configure([], {output: metadataOutput, script: metadataScript});
  check(metadataResult.status === 0 && metadataResult.errors === '' && fs.existsSync(metadataOutput), 'Actual file creation succeeds despite read-only directory metadata');
  const deniedScript = simulatedInstaller('denied-write', 'function fopen($path, $mode) { return false; }');
  const deniedOutput = path.join(spacedDirectory, 'denied.php');
  const deniedResult = await configure([], {output: deniedOutput, script: deniedScript});
  check(deniedResult.status === 1 && deniedResult.text === '' && deniedResult.errors.includes(spacedDirectory) && !fs.existsSync(deniedOutput), 'Actual write denial fails closed and identifies the directory');
  const renameScript = simulatedInstaller('failed-rename', 'function rename($from, $to) { return false; }');
  const beforeFailedRotation = fs.readFileSync(generated, 'utf8');
  const failedRotation = await configure(['--rotate'], {script: renameScript});
  check(failedRotation.status === 1 && failedRotation.text === '' && failedRotation.errors.includes(generated), 'Failed installation does not disclose a new key');
  check(fs.readFileSync(generated, 'utf8') === beforeFailedRotation, 'Failed rotation preserves the previous private configuration');
  check(!fs.readdirSync(temporary).some(name => name.startsWith('.lease-auth-')), 'Failed installation cleans up the temporary configuration');
  console.log(`PASS: ${checks} checks using PHP ${version}. No live services used.`);
})().catch(error => { console.error(error); process.exitCode = 1; }).finally(() => {
  try { activePHP?.exit(); } catch {}
  fs.rmSync(temporary, {recursive: true, force: true});
  // CLI Wasm instances may retain lock-manager workers after PHP shutdown.
  process.exit(process.exitCode || 0);
});
