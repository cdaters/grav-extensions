<?php

declare(strict_types=1);

namespace GravJarvisCredentialContract;

use Grav\Plugin\GravJarvis\Contracts\Exception\MalformedCredentialException;
use Grav\Plugin\GravJarvis\Contracts\Exception\MissingCredentialException;
use Grav\Plugin\GravJarvis\Security\CompositeCredentialResolver;
use Grav\Plugin\GravJarvis\Security\CredentialManager;
use Grav\Plugin\GravJarvis\Security\EncryptedCredentialStore;
use Grav\Plugin\GravJarvis\Security\MasterKeyManager;
use Grav\Plugin\GravJarvis\Security\OpenSslAeadBackend;
use Grav\Plugin\GravJarvis\Security\SodiumAeadBackend;
use RuntimeException;
use Throwable;

$pluginDirectory = rtrim((string) (getenv('GRAV_JARVIS_PLUGIN_DIR') ?: dirname(__DIR__, 2) . '/plugins/grav-jarvis'), '/');
spl_autoload_register(static function (string $class) use ($pluginDirectory): void {
    $prefix = 'Grav\\Plugin\\GravJarvis\\';
    if (str_starts_with($class, $prefix)) {
        $file = $pluginDirectory . '/classes/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($file)) require_once $file;
    }
});

function expect(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

function same(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) throw new RuntimeException($message . ' expected=' . json_encode($expected) . ' actual=' . json_encode($actual));
}

function throws(callable $callback, string $message): Throwable
{
    try { $callback(); } catch (Throwable $error) { return $error; }
    throw new RuntimeException($message);
}

function removeTree(string $directory): void
{
    if (!is_dir($directory) || is_link($directory)) return;
    foreach (scandir($directory) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') continue;
        $path = $directory . '/' . $entry;
        if (is_dir($path) && !is_link($path)) removeTree($path); else @unlink($path);
    }
    @rmdir($directory);
}

function store(string $directory, array $backends): EncryptedCredentialStore
{
    return new EncryptedCredentialStore($directory, new MasterKeyManager($directory), $backends);
}

$root = sys_get_temp_dir() . '/grav-jarvis-credentials-' . bin2hex(random_bytes(6));
$environment = 'GRAV_JARVIS_OPENAI_API_KEY';
$secretOne = 'sk-test-jarvis-one-123456789';
$secretTwo = 'sk-test-jarvis-two-987654321';
putenv($environment);
putenv(MasterKeyManager::ENVIRONMENT_VARIABLE);

try {
    foreach ([
        'sodium' => [new SodiumAeadBackend(true), new OpenSslAeadBackend(false)],
        'openssl' => [new SodiumAeadBackend(false), new OpenSslAeadBackend(true)],
    ] as $name => $backends) {
        $directory = $root . '/' . $name;
        $store = store($directory, $backends);
        $saved = $store->save('openai', $secretOne);
        same($name, $saved['backend'], "{$name} backend was not selected.");
        same($secretOne, $store->reveal('openai'), "{$name} credential did not decrypt.");
        same('local', $saved['master_key_source'], "{$name} local master key source changed.");
        same(0600, fileperms($directory . '/master.key') & 0777, "{$name} master key permissions changed.");
        same(0600, fileperms($directory . '/openai.credential.json') & 0777, "{$name} record permissions changed.");
        $raw = (string) file_get_contents($directory . '/openai.credential.json');
        expect(!str_contains($raw, $secretOne), "{$name} record contains plaintext.");
        $store->save('openai', $secretTwo);
        same($secretTwo, $store->reveal('openai'), "{$name} replacement failed.");

        $record = json_decode((string) file_get_contents($directory . '/openai.credential.json'), true, 32, JSON_THROW_ON_ERROR);
        $field = $name === 'openssl' ? 'tag' : 'ciphertext';
        $record[$field] = base64_encode(str_repeat("\0", strlen(base64_decode($record[$field], true))));
        file_put_contents($directory . '/openai.credential.json', json_encode($record, JSON_THROW_ON_ERROR));
        throws(fn () => $store->reveal('openai'), "{$name} tampering was accepted.");
        expect($store->remove('openai'), "{$name} credential removal failed.");
        expect(!$store->exists('openai'), "{$name} credential remains after removal.");
    }
    echo "PASS: Sodium and OpenSSL stores save, decrypt, replace, authenticate, and remove\n";

    $wrongKeyDirectory = $root . '/wrong-key';
    $wrongKeyStore = store($wrongKeyDirectory, [new SodiumAeadBackend(true), new OpenSslAeadBackend(false)]);
    $wrongKeyStore->save('openai', $secretOne);
    file_put_contents($wrongKeyDirectory . '/master.key', "JMK1\0" . random_bytes(32));
    throws(fn () => $wrongKeyStore->reveal('openai'), 'Wrong master key was accepted.');
    file_put_contents($wrongKeyDirectory . '/openai.credential.json', '{malformed');
    throws(fn () => $wrongKeyStore->reveal('openai'), 'Malformed credential record was accepted.');
    file_put_contents($wrongKeyDirectory . '/openai.credential.json', json_encode(['format_version' => 99], JSON_THROW_ON_ERROR));
    throws(fn () => $wrongKeyStore->reveal('openai'), 'Unsupported record version was accepted.');
    @unlink($wrongKeyDirectory . '/master.key');
    same(false, $wrongKeyStore->readiness()['local_storage_available'], 'Missing master key for existing records was reported ready.');
    file_put_contents($wrongKeyDirectory . '/master.key', 'corrupt-master-key');
    same(false, $wrongKeyStore->readiness()['local_storage_available'], 'Corrupt master key was reported ready.');
    echo "PASS: wrong keys, malformed records, and unknown versions fail safely\n";

    $externalDirectory = $root . '/external';
    $externalKey = random_bytes(32);
    putenv(MasterKeyManager::ENVIRONMENT_VARIABLE . '=base64:' . base64_encode($externalKey));
    SodiumAeadBackend::zero($externalKey);
    $externalStore = store($externalDirectory, [new SodiumAeadBackend(true), new OpenSslAeadBackend(false)]);
    same('external', $externalStore->save('openai', $secretOne)['master_key_source'], 'External key was not preferred for a new record.');
    expect(!is_file($externalDirectory . '/master.key'), 'External-key storage created a local master key.');
    putenv(MasterKeyManager::ENVIRONMENT_VARIABLE . '=base64:' . base64_encode(random_bytes(32)));
    throws(fn () => $externalStore->reveal('openai'), 'Wrong external master key was accepted.');
    putenv(MasterKeyManager::ENVIRONMENT_VARIABLE);
    throws(fn () => $externalStore->reveal('openai'), 'Missing external master key was accepted.');
    echo "PASS: external random master keys are preferred and wrong/missing values fail closed\n";

    $resolutionDirectory = $root . '/resolution';
    $resolutionStore = store($resolutionDirectory, [new SodiumAeadBackend(true), new OpenSslAeadBackend(false)]);
    $resolutionStore->save('openai', $secretOne);
    $manager = new CredentialManager($resolutionStore, ['openai' => $environment]);
    $resolver = $manager->resolver('openai');
    same($secretOne, $resolver->resolve($environment)->reveal(), 'Encrypted local resolution failed.');
    putenv($environment . '=sk-env-priority-123456');
    same('sk-env-priority-123456', $resolver->resolve($environment)->reveal(), 'Environment credential did not override local storage.');
    same('environment', $manager->status('openai')['source'], 'Effective environment source was not reported.');
    putenv($environment);
    same($secretOne, $resolver->resolve($environment)->reveal(), 'Removing the environment credential did not reveal the local credential.');
    $manager->remove('openai');
    expect(throws(fn () => $resolver->resolve($environment), 'Missing sources were accepted.') instanceof MissingCredentialException, 'Missing source exception changed.');
    putenv($environment . '= malformed');
    expect(throws(fn () => $resolver->resolve($environment), 'Malformed environment override fell through.') instanceof MalformedCredentialException, 'Malformed environment must fail closed.');
    putenv($environment);
    echo "PASS: credential priority is environment, encrypted local, then missing\n";

    $hostDirectory = $root . '/host';
    $both = store($hostDirectory, [new SodiumAeadBackend(true), new OpenSslAeadBackend(true)])->readiness();
    same('sodium', $both['best_backend'], 'Sodium was not preferred on a capable host.');
    $fallback = store($hostDirectory, [new SodiumAeadBackend(false), new OpenSslAeadBackend(true)])->readiness();
    same('openssl', $fallback['best_backend'], 'OpenSSL fallback was not selected without Sodium.');
    $sodiumOnly = store($hostDirectory, [new SodiumAeadBackend(true), new OpenSslAeadBackend(false)])->readiness();
    same('sodium', $sodiumOnly['best_backend'], 'Sodium-only host was not ready.');
    $neither = store($hostDirectory, [new SodiumAeadBackend(false), new OpenSslAeadBackend(false)])->readiness();
    same(null, $neither['best_backend'], 'Unsupported host claimed an encryption backend.');
    same(false, $neither['local_storage_available'], 'Unsupported host enabled Admin credential entry.');
    $unwritable = store('/proc/grav-jarvis-unwritable-fixture', [new SodiumAeadBackend(true), new OpenSslAeadBackend(false)])->readiness();
    same(false, $unwritable['local_storage_available'], 'Unwritable credential path was reported ready.');
    putenv(MasterKeyManager::ENVIRONMENT_VARIABLE . '=not-a-random-key');
    same(false, store($hostDirectory, [new SodiumAeadBackend(true)])->readiness()['local_storage_available'], 'Invalid external master key was ignored.');
    putenv(MasterKeyManager::ENVIRONMENT_VARIABLE);
    echo "PASS: readiness covers preferred, fallback, partial, unsupported, and external-key states\n";

    $symlinkTarget = $root . '/symlink-target';
    mkdir($symlinkTarget, 0700, true);
    $symlinkPath = $root . '/symlink-store';
    if (function_exists('symlink') && @symlink($symlinkTarget, $symlinkPath)) {
        throws(fn () => store($symlinkPath, [new SodiumAeadBackend(true)])->save('openai', $secretOne), 'Credential-directory symlink was accepted.');
    }
    echo "PASS: protected paths reject symbolic-link substitution\n";
} finally {
    putenv($environment);
    putenv(MasterKeyManager::ENVIRONMENT_VARIABLE);
    removeTree($root);
}

echo "Jarvis credential and readiness contract passed (6 checks).\n";
