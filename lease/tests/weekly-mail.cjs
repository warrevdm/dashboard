const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const {PHP, FileLockManagerInMemory} = require('@php-wasm/universal');
const {loadNodeRuntime, createNodeFsMountHandler} = require('@php-wasm/node');
const root = path.resolve(__dirname, '..');
const temp = fs.mkdtempSync(path.join(os.tmpdir(), 'lease-weekly-runtime-'));
let active;
async function runtime() {
    active = new PHP(await loadNodeRuntime('8.3', {fileLockManager: new FileLockManagerInMemory(), emscriptenOptions: {processId: process.pid}}));
    active.mkdirTree('/test');
    await active.mount('/test', createNodeFsMountHandler(temp));
    return active;
}
async function cli(script, args = []) {
    const php = await runtime();
    const result = await php.cli(['php', '/test/lease/' + script, ...args], {env: {TMPDIR: '/test'}});
    const output = {code: await result.exitCode, text: await result.stdoutText, errors: await result.stderrText};
    try { php.exit(); } catch {}
    return output;
}
(async () => {
    // Copy only source files, never real private configuration or stored customer data.
    for (const folder of ['app', 'scripts', 'tests']) {
        fs.cpSync(path.join(root, folder), path.join(temp, 'lease', folder), {
            recursive: true, filter: name => !name.split(path.sep).includes('node_modules'),
        });
    }
    fs.mkdirSync(path.join(temp, 'lease/config'));
    fs.copyFileSync(path.join(root, 'config/weekly-mail.php'), path.join(temp, 'lease/config/weekly-mail.php'));
    const result = await cli('tests/weekly-mail.php');
    assert.equal(result.code, 0, result.text + result.errors);
    assert.equal(result.errors, '', result.errors);
    console.log(result.text.trim());
    const disabled = await cli('scripts/send-weekly-mail.php');
    assert.equal(disabled.code, 0, disabled.errors);
    assert.equal(JSON.parse(disabled.text).status, 'disabled');
    assert.ok(!fs.existsSync(path.join(temp, 'lease/storage')), 'Disabled CLI has no side effects');
    const help = await cli('scripts/send-weekly-mail.php', ['--help']);
    assert.equal(help.code, 0);
    assert.ok(help.text.includes('--dry-run'));
    assert.equal((await cli('scripts/send-weekly-mail.php', ['--dryrun'])).code, 2, 'Mistyped dry-run option cannot send');
    assert.equal((await cli('scripts/send-weekly-mail.php', ['--dry-run', '--retry-failed'])).code, 2, 'Conflicting modes cannot send');
    const dryRun = await cli('scripts/send-weekly-mail.php', ['--dry-run']);
    assert.equal(dryRun.code, 1, 'Dry run needs an explicitly available database');
    assert.ok(!fs.existsSync(path.join(temp, 'lease/storage')), 'Dry run does not modify delivery state');
    const php = await runtime();
    const web = await php.run({scriptPath: '/test/lease/scripts/send-weekly-mail.php'});
    assert.equal(web.httpStatusCode, 404, 'No publicly callable cron endpoint');
    assert.equal(web.errors, '');
    php.exit();
    console.log('PASS: CLI defaults, dry-run safety and HTTP cron protection. No SMTP connections made.');
})().catch(error => { console.error(error); process.exitCode = 1; }).finally(() => {
    try { active?.exit(); } catch {}
    fs.rmSync(temp, {recursive: true, force: true});
    process.exit(process.exitCode || 0);
});
