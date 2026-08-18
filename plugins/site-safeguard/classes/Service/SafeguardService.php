<?php

declare(strict_types=1);

namespace Grav\Plugin\SiteSafeguard\Service;

use Grav\Common\Grav;
use Grav\Plugin\Api\Exceptions\ForbiddenException;
use Grav\Plugin\Api\Exceptions\NotFoundException;
use Grav\Plugin\Api\Exceptions\ValidationException;
use Psr\Http\Message\UploadedFileInterface;
use RuntimeException;
use ZipArchive;

class SafeguardService
{
    private const MANIFEST_PATH = '_site-safeguard/manifest.json';
    private const CHECKSUMS_PATH = '_site-safeguard/checksums.json';
    private const SCHEMA = 1;
    private const VERSION = '0.2.2';

    private Grav $grav;
    private array $config;
    private string $root;

    public function __construct()
    {
        $this->grav = Grav::instance();
        $this->config = (array) $this->grav['config']->get('plugins.site-safeguard', []);
        $root = realpath(GRAV_ROOT);
        if ($root === false) {
            throw new RuntimeException('Unable to resolve the Grav root.');
        }
        $this->root = rtrim(str_replace('\\', '/', $root), '/');
    }

    public function status(): array
    {
        $packages = $this->packages();
        $stages = $this->stages();

        return [
            'version' => self::VERSION,
            'zip_available' => class_exists(ZipArchive::class),
            'package_path' => $this->packageDirectory(),
            'stage_path' => $this->stageDirectory(),
            'packages' => $packages,
            'stages' => $stages,
            'profiles' => array_values($this->profiles()),
            'package_count' => count($packages),
            'stage_count' => count($stages),
            'stored_bytes' => array_sum(array_column($packages, 'size')),
            'allow_uploads' => (bool) ($this->config['allow_uploads'] ?? true),
            'restore_enabled' => (bool) ($this->config['restore_enabled'] ?? false),
            'restore_confirmation' => 'RESTORE THIS SITE',
            'restore_preserve_paths' => $this->restorePreservePaths(),
            'restore_history' => $this->restoreHistory(),
            'promotion_available' => false,
            'safety_message' => 'Restore is rollback-first and CLI-only: Site Safeguard verifies the source, creates and stages a rollback package, preserves host-local paths, and verifies the restored site in a fresh process.',
        ];
    }

    public function profiles(): array
    {
        $configured = $this->config['profiles'] ?? [];
        if (!is_array($configured) || $configured === []) {
            throw new ValidationException('No Site Safeguard package profiles are configured.');
        }

        $result = [];
        foreach ($configured as $key => $profile) {
            if (!is_array($profile) || !preg_match('/^[a-z0-9][a-z0-9_-]*$/', (string) $key)) {
                continue;
            }
            $includes = $this->normaliseRelativePaths((array) ($profile['include_paths'] ?? []), true);
            if ($includes === []) {
                continue;
            }
            $result[(string) $key] = [
                'key' => (string) $key,
                'label' => trim((string) ($profile['label'] ?? $key)) ?: (string) $key,
                'description' => trim((string) ($profile['description'] ?? '')),
                'deployable' => (bool) ($profile['deployable'] ?? false),
                'include_paths' => $includes,
                'exclude_paths' => $this->normaliseRelativePaths((array) ($profile['exclude_paths'] ?? []), false),
            ];
        }

        if ($result === []) {
            throw new ValidationException('No valid Site Safeguard package profiles are configured.');
        }
        return $result;
    }

    public function createPackage(string $profileKey = 'portable_site', string $note = ''): array
    {
        $this->assertZipAvailable();
        $this->assertPackageCapacity();
        $profiles = $this->profiles();
        if (!isset($profiles[$profileKey])) {
            throw new ValidationException('Unknown package profile: ' . $profileKey);
        }
        $profile = $profiles[$profileKey];

        $directory = $this->packageDirectory();
        $host = $this->safeHost();
        $stamp = gmdate('Ymd-His');
        $random = bin2hex(random_bytes(3));
        $name = sprintf('safeguard-%s-%s-%s-%s.zip', $host, $profileKey, $stamp, $random);
        $final = $directory . '/' . $name;
        $partial = $directory . '/.' . $name . '.partial';

        $zip = new ZipArchive();
        if ($zip->open($partial, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new ValidationException('Unable to create the package archive.');
        }

        $checksums = [];
        $stats = ['files' => 0, 'directories' => 0, 'bytes' => 0, 'excluded' => 0, 'symlinks_skipped' => 0];
        $warnings = [];

        $zipOpen = true;
        try {
            foreach ($profile['include_paths'] as $relative) {
                $absolute = $relative === '.' ? $this->root : $this->root . '/' . $relative;
                if (!file_exists($absolute) && !is_link($absolute)) {
                    $warnings[] = 'Included path does not exist: ' . $relative;
                    continue;
                }
                $this->addPath($zip, $absolute, $relative === '.' ? '' : $relative, $profile, $checksums, $stats, $warnings);
            }

            $manifest = [
                'schema' => self::SCHEMA,
                'generator' => 'site-safeguard',
                'generator_version' => self::VERSION,
                'created_at' => gmdate('c'),
                'site_host' => $host,
                'grav_version' => defined('GRAV_VERSION') ? GRAV_VERSION : null,
                'php_version' => PHP_VERSION,
                'profile' => $profile,
                'note' => function_exists('mb_substr') ? mb_substr(trim($note), 0, 1000) : substr(trim($note), 0, 1000),
                'stats' => $stats,
                'warnings' => $warnings,
                'sensitive_archive' => true,
                'promotion_ready' => false,
            ];

            $zip->addFromString(self::MANIFEST_PATH, $this->json($manifest));
            $zip->addFromString(self::CHECKSUMS_PATH, $this->json([
                'algorithm' => 'sha256',
                'files' => $checksums,
            ]));

            if (!$zip->close()) {
                throw new ValidationException('Unable to finish the package archive.');
            }
            $zipOpen = false;
            if ((int) filesize($partial) > $this->maxPackageBytes()) {
                throw new ValidationException('The completed package exceeds the configured size limit.');
            }
            if (!rename($partial, $final)) {
                throw new ValidationException('Unable to publish the completed package.');
            }
            @chmod($final, 0600);
        } catch (\Throwable $e) {
            if ($zipOpen) {
                $zip->close();
            }
            if (is_file($partial)) {
                @unlink($partial);
            }
            throw $e;
        }

        $this->grav['log']->notice('[Site Safeguard] Package created: ' . $name);
        return $this->packageInfo($final);
    }

    public function importPackage(UploadedFileInterface $upload): array
    {
        if (!($this->config['allow_uploads'] ?? true)) {
            throw new ForbiddenException('Package imports are disabled.');
        }
        $this->assertPackageCapacity();
        if ($upload->getError() !== UPLOAD_ERR_OK) {
            throw new ValidationException('The package upload failed.');
        }
        $size = (int) ($upload->getSize() ?? 0);
        if ($size <= 0 || $size > $this->maxPackageBytes()) {
            throw new ValidationException('The uploaded package is empty or exceeds the configured size limit.');
        }

        $clientName = basename((string) ($upload->getClientFilename() ?? 'package.zip'));
        if (!preg_match('/\.zip$/i', $clientName)) {
            throw new ValidationException('Site Safeguard accepts ZIP packages only.');
        }

        $directory = $this->packageDirectory();
        $nameBase = preg_replace('/[^A-Za-z0-9._-]+/', '-', pathinfo($clientName, PATHINFO_FILENAME)) ?: 'imported-package';
        $name = 'import-' . trim($nameBase, '.-_') . '-' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(3)) . '.zip';
        $partial = $directory . '/.' . $name . '.upload';
        $final = $directory . '/' . $name;

        $upload->moveTo($partial);
        @chmod($partial, 0600);
        try {
            $inspection = $this->inspectPath($partial);
            if (!$inspection['valid']) {
                throw new ValidationException('Package validation failed: ' . implode(' ', array_slice($inspection['errors'], 0, 3)));
            }
            if (!rename($partial, $final)) {
                throw new ValidationException('Unable to retain the validated package.');
            }
        } catch (\Throwable $e) {
            if (is_file($partial)) {
                @unlink($partial);
            }
            throw $e;
        }

        $this->grav['log']->notice('[Site Safeguard] Package imported: ' . $name);
        return $this->packageInfo($final);
    }

    public function packages(): array
    {
        $files = glob($this->packageDirectory() . '/*.zip') ?: [];
        $items = array_map(fn (string $file): array => $this->packageInfo($file), $files);
        usort($items, static fn (array $a, array $b): int => $b['modified'] <=> $a['modified']);
        return $items;
    }

    public function inspectPackage(string $name): array
    {
        return $this->inspectPath($this->packagePath($name));
    }

    public function stagePackage(string $name): array
    {
        $path = $this->packagePath($name);
        $inspection = $this->inspectPath($path, true);
        if (!$inspection['valid']) {
            throw new ValidationException('Only a valid package can be staged.');
        }
        $manifest = (array) ($inspection['manifest'] ?? []);
        $profile = (array) ($manifest['profile'] ?? []);
        if (!($profile['deployable'] ?? false)) {
            throw new ValidationException('This profile is a partial backup and cannot be staged as a complete site.');
        }

        $stageRoot = $this->stageDirectory();
        $idBase = preg_replace('/[^A-Za-z0-9._-]+/', '-', pathinfo($name, PATHINFO_FILENAME)) ?: 'stage';
        $id = trim($idBase, '.-_') . '-' . bin2hex(random_bytes(3));
        $partial = $stageRoot . '/.' . $id . '.partial';
        $final = $stageRoot . '/' . $id;
        $this->ensureDirectory($partial);

        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            $this->removeTree($partial, $stageRoot);
            throw new ValidationException('Unable to reopen the validated package.');
        }

        $zipOpen = true;
        try {
            $checksums = (array) ($inspection['checksums']['files'] ?? []);
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $entry = (string) $zip->getNameIndex($index);
                if (!str_starts_with($entry, 'site/')) {
                    continue;
                }
                $relative = substr($entry, 5);
                if ($relative === '') {
                    continue;
                }
                $this->assertSafeArchiveEntry($entry);
                $destination = $this->safeChildPath($partial, $relative);
                if (str_ends_with($entry, '/')) {
                    $this->ensureDirectory($destination);
                    continue;
                }
                $this->ensureDirectory(dirname($destination));
                $input = $zip->getStream($entry);
                if (!is_resource($input)) {
                    throw new ValidationException('Unable to read staged entry: ' . $entry);
                }
                $output = fopen($destination, 'xb');
                if ($output === false) {
                    fclose($input);
                    throw new ValidationException('Unable to create staged file: ' . $relative);
                }
                if (stream_copy_to_stream($input, $output) === false) {
                    fclose($input);
                    fclose($output);
                    throw new ValidationException('Unable to extract staged file: ' . $relative);
                }
                fclose($input);
                fclose($output);
                $mode = (int) ($checksums[$entry]['mode'] ?? (str_starts_with($entry, 'site/bin/') ? 0755 : 0644));
                @chmod($destination, $mode & 0777);
                if (isset($checksums[$entry]['modified'])) {
                    @touch($destination, (int) $checksums[$entry]['modified']);
                }
            }
            $zip->close();
            $zipOpen = false;

            $this->verifyStagedFiles($partial, $checksums);
            $stageRecord = file_put_contents($partial . '/.site-safeguard-stage.json', $this->json([
                'schema' => 1,
                'id' => $id,
                'package' => $name,
                'package_sha256' => $inspection['package_sha256'],
                'created_at' => gmdate('c'),
                'verified' => true,
                'promotion_ready' => false,
            ]), LOCK_EX);
            if ($stageRecord === false) {
                throw new ValidationException('Unable to write the stage verification record.');
            }
            if (!rename($partial, $final)) {
                throw new ValidationException('Unable to publish the verified stage.');
            }
        } catch (\Throwable $e) {
            if ($zipOpen) {
                $zip->close();
            }
            if (is_dir($partial)) {
                $this->removeTree($partial, $stageRoot);
            }
            throw $e;
        }

        $this->grav['log']->notice('[Site Safeguard] Package staged outside the site root: ' . $id);
        return $this->stageInfo($final);
    }

    public function stages(): array
    {
        $root = $this->stageDirectory();
        $paths = glob($root . '/*', GLOB_ONLYDIR) ?: [];
        $items = array_map(fn (string $path): array => $this->stageInfo($path), $paths);
        usort($items, static fn (array $a, array $b): int => $b['modified'] <=> $a['modified']);
        return $items;
    }

    public function restoreStage(string $id, string $confirmation): array
    {
        if (PHP_SAPI !== 'cli') {
            throw new ForbiddenException('Full-site restore must run from PHP CLI, outside the initiating web request.');
        }
        if (!($this->config['restore_enabled'] ?? false)) {
            throw new ForbiddenException('Full-site restore is disabled in Site Safeguard configuration.');
        }
        if (!hash_equals('RESTORE THIS SITE', trim($confirmation))) {
            throw new ValidationException('The restore confirmation phrase did not match.');
        }
        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }

        $stage = $this->stagePath($id);
        $stageInfo = $this->stageInfo($stage);
        if (!$stageInfo['verified'] || ($stageInfo['record']['promotion_ready'] ?? false) !== false) {
            throw new ValidationException('Only a verified, unpromoted Site Safeguard stage can be restored.');
        }
        $packageName = (string) ($stageInfo['record']['package'] ?? '');
        $packagePath = $this->packagePath($packageName);
        $inspection = $this->inspectPath($packagePath, true);
        if (!$inspection['valid'] || !(bool) ($inspection['manifest']['profile']['deployable'] ?? false)) {
            throw new ValidationException('The stage source is no longer a valid deployable package.');
        }
        if (!hash_equals((string) ($stageInfo['record']['package_sha256'] ?? ''), (string) $inspection['package_sha256'])) {
            throw new ValidationException('The package no longer matches the verified stage record.');
        }
        $checksums = (array) ($inspection['checksums']['files'] ?? []);
        $this->verifyStagedFiles($stage, $checksums);
        $this->verifyGravBoot($stage, true);
        $this->verifyStagedFiles($stage, $checksums);

        $lock = $this->restoreLock();
        $operationId = 'restore-' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(3));
        $journalPath = $this->packageDirectory() . '/.' . $operationId . '.json';
        $preserve = $this->restorePreservePaths();
        $rollbackPackage = null;
        $rollbackStage = null;
        $maintenance = $this->root . '/.upgrading';
        $journal = [
            'schema' => 1,
            'id' => $operationId,
            'state' => 'preparing',
            'started_at' => gmdate('c'),
            'source_stage' => $id,
            'source_package' => $packageName,
            'source_sha256' => $inspection['package_sha256'],
            'preserved_paths' => $preserve,
            'rollback_package' => null,
            'rollback_stage' => null,
            'error' => null,
        ];
        try {
            $this->writeJournal($journalPath, $journal);
        } catch (\Throwable $journalError) {
            flock($lock, LOCK_UN);
            fclose($lock);
            throw $journalError;
        }

        try {
            $rollbackPackage = $this->createPackage('portable_site', 'Automatic rollback before ' . $operationId);
            $rollbackStage = $this->stagePackage((string) $rollbackPackage['name']);
            $rollbackInspection = $this->inspectPath($this->packagePath((string) $rollbackPackage['name']), true);
            $rollbackChecksums = (array) ($rollbackInspection['checksums']['files'] ?? []);
            $this->verifyGravBoot((string) $rollbackStage['path'], true);
            $this->verifyStagedFiles((string) $rollbackStage['path'], $rollbackChecksums);
            $journal['rollback_package'] = $rollbackPackage['name'];
            $journal['rollback_stage'] = $rollbackStage['id'];
            $journal['state'] = 'rollback-verified';
            $this->writeJournal($journalPath, $journal);

            if (file_put_contents($maintenance, gmdate('c') . ' ' . $operationId . "\n", LOCK_EX) === false) {
                throw new ValidationException('Unable to enter Grav maintenance mode.');
            }
            $journal['state'] = 'restoring';
            $this->writeJournal($journalPath, $journal);

            $stats = ['files_copied' => 0, 'directories_created' => 0, 'entries_removed' => 0, 'entries_preserved' => 0];
            $this->mirrorStageToRoot($stage, $preserve, $stats);
            $verified = $this->verifyRestoredFiles($checksums, $preserve);
            $this->clearRuntimeCache();
            @unlink($maintenance);
            $this->verifyGravBoot($this->root, false);

            $journal['state'] = 'completed';
            $journal['completed_at'] = gmdate('c');
            $journal['stats'] = $stats;
            $journal['verified_files'] = $verified;
            $this->writeJournal($journalPath, $journal);
            clearstatcache(true);
            if (function_exists('opcache_reset')) {
                @opcache_reset();
            }

            $this->grav['log']->notice('[Site Safeguard] Full-site restore completed: ' . $operationId);
            return [
                'message' => 'Full-site restore completed and verified.',
                'operation' => $journal,
                'rollback_package' => $rollbackPackage,
                'rollback_stage' => $rollbackStage,
            ];
        } catch (\Throwable $restoreError) {
            $journal['state'] = 'restore-failed';
            $journal['error'] = $restoreError->getMessage();
            $this->writeJournal($journalPath, $journal);
            @file_put_contents($maintenance, gmdate('c') . ' rollback ' . $operationId . "\n", LOCK_EX);

            if (is_array($rollbackStage) && isset($rollbackStage['id'])) {
                try {
                    $rollbackPath = $this->stagePath((string) $rollbackStage['id']);
                    $rollbackName = (string) ($rollbackStage['record']['package'] ?? $rollbackPackage['name'] ?? '');
                    $rollbackInspection = $this->inspectPath($this->packagePath($rollbackName), true);
                    $rollbackChecksums = (array) ($rollbackInspection['checksums']['files'] ?? []);
                    $rollbackStats = ['files_copied' => 0, 'directories_created' => 0, 'entries_removed' => 0, 'entries_preserved' => 0];
                    $this->mirrorStageToRoot($rollbackPath, $preserve, $rollbackStats);
                    $this->verifyRestoredFiles($rollbackChecksums, $preserve);
                    $this->clearRuntimeCache();
                    @unlink($maintenance);
                    $this->verifyGravBoot($this->root, false);
                    $journal['state'] = 'rolled-back';
                    $journal['rolled_back_at'] = gmdate('c');
                    $journal['rollback_stats'] = $rollbackStats;
                    $this->writeJournal($journalPath, $journal);
                } catch (\Throwable $rollbackError) {
                    @file_put_contents($maintenance, gmdate('c') . ' rollback-failed ' . $operationId . "\n", LOCK_EX);
                    $journal['state'] = 'rollback-failed';
                    $journal['rollback_error'] = $rollbackError->getMessage();
                    $this->writeJournal($journalPath, $journal);
                }
            }
            if ($journal['state'] === 'rolled-back') {
                @unlink($maintenance);
            }
            throw new ValidationException(
                $journal['state'] === 'rolled-back'
                    ? 'Restore failed and the previous site was restored automatically: ' . $restoreError->getMessage()
                    : 'Restore failed. Review the recovery journal and rollback stage immediately: ' . $restoreError->getMessage()
            );
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public function deletePackage(string $name): array
    {
        $path = $this->packagePath($name);
        if (!unlink($path)) {
            throw new ValidationException('Unable to delete the package.');
        }
        return ['message' => 'Package deleted.', 'name' => $name];
    }

    public function deleteStage(string $id): array
    {
        if ($id !== basename($id) || !preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/', $id)) {
            throw new ValidationException('Invalid stage identifier.');
        }
        $root = $this->stageDirectory();
        $path = $root . '/' . $id;
        if (!is_dir($path)) {
            throw new NotFoundException('Stage not found.');
        }
        $this->removeTree($path, $root);
        return ['message' => 'Stage deleted.', 'id' => $id];
    }

    public function createDownloadToken(string $name): array
    {
        $path = $this->packagePath($name);
        $now = time();
        $ttl = max(30, min(900, (int) ($this->config['download_token_ttl'] ?? 180)));
        $token = bin2hex(random_bytes(24));
        $this->mutateTokens(function (array &$tokens) use ($token, $path, $now, $ttl): void {
            foreach ($tokens as $key => $record) {
                if (!is_array($record) || (int) ($record['expires'] ?? 0) <= $now) {
                    unset($tokens[$key]);
                }
            }
            $tokens[$token] = ['name' => basename($path), 'expires' => $now + $ttl];
        });

        return [
            'token' => $token,
            'url' => rtrim((string) $this->grav['uri']->rootUrl(true), '/') . '/site-safeguard/download?token=' . rawurlencode($token),
            'expires_at' => gmdate('c', $now + $ttl),
        ];
    }

    public function consumeDownloadToken(string $token): array
    {
        if (!preg_match('/^[a-f0-9]{48}$/', $token)) {
            throw new ForbiddenException('Invalid package token.');
        }
        $record = $this->mutateTokens(function (array &$tokens) use ($token): mixed {
            $record = $tokens[$token] ?? null;
            unset($tokens[$token]);
            return $record;
        });
        if (!is_array($record) || (int) ($record['expires'] ?? 0) <= time()) {
            throw new ForbiddenException('Expired package token.');
        }
        $path = $this->packagePath((string) ($record['name'] ?? ''));
        return ['path' => $path, 'name' => basename($path)];
    }

    private function addPath(ZipArchive $zip, string $absolute, string $relative, array $profile, array &$checksums, array &$stats, array &$warnings): void
    {
        $relative = trim(str_replace('\\', '/', $relative), '/');
        if ($relative !== '' && $this->isExcluded($relative, $profile)) {
            $stats['excluded']++;
            return;
        }
        if (is_link($absolute)) {
            $stats['symlinks_skipped']++;
            $warnings[] = 'Symbolic link skipped: ' . ($relative ?: '.');
            return;
        }
        if (is_dir($absolute)) {
            if ($relative !== '') {
                $this->assertEntryCapacity($stats);
                $zip->addEmptyDir('site/' . $relative);
                $stats['directories']++;
            }
            $entries = scandir($absolute);
            if ($entries === false) {
                $warnings[] = 'Unreadable directory skipped: ' . ($relative ?: '.');
                return;
            }
            foreach ($entries as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }
                $nextRelative = $relative === '' ? $entry : $relative . '/' . $entry;
                $this->addPath($zip, $absolute . '/' . $entry, $nextRelative, $profile, $checksums, $stats, $warnings);
            }
            return;
        }
        if (!is_file($absolute) || !is_readable($absolute)) {
            $warnings[] = 'Unreadable file skipped: ' . $relative;
            return;
        }

        $size = (int) filesize($absolute);
        $this->assertEntryCapacity($stats);
        if ($stats['bytes'] + $size > $this->maxUncompressedBytes()) {
            throw new ValidationException('Package content exceeds the configured uncompressed size limit.');
        }
        $archivePath = 'site/' . $relative;
        if (!$zip->addFile($absolute, $archivePath)) {
            throw new ValidationException('Unable to add file to package: ' . $relative);
        }
        $checksums[$archivePath] = [
            'sha256' => hash_file('sha256', $absolute),
            'size' => $size,
            'modified' => (int) filemtime($absolute),
            'mode' => fileperms($absolute) & 0777,
        ];
        $stats['files']++;
        $stats['bytes'] += $size;
    }

    private function inspectPath(string $path, bool $includeChecksums = false): array
    {
        $this->assertZipAvailable();
        if (!is_file($path) || !is_readable($path)) {
            throw new NotFoundException('Package not found.');
        }
        if ((int) filesize($path) > $this->maxPackageBytes()) {
            throw new ValidationException('Package exceeds the configured size limit.');
        }

        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            throw new ValidationException('Unable to open the ZIP package.');
        }

        $errors = [];
        $warnings = [];
        $entries = [];
        $uncompressed = 0;
        $fileCount = 0;
        $maxEntries = max(100, (int) ($this->config['max_archive_entries'] ?? 100000));
        $maxBytes = $this->maxUncompressedBytes();

        $archiveEntryCount = $zip->numFiles;
        if ($archiveEntryCount > $maxEntries) {
            $errors[] = 'Archive entry limit exceeded.';
        }

        $scanLimit = min($archiveEntryCount, $maxEntries + 1);
        for ($index = 0; $index < $scanLimit && count($errors) < 50; $index++) {
            $stat = $zip->statIndex($index);
            $entry = (string) ($stat['name'] ?? '');
            try {
                $this->assertSafeArchiveEntry($entry);
            } catch (\Throwable $e) {
                $errors[] = $e->getMessage();
                continue;
            }
            if (isset($entries[$entry])) {
                $errors[] = 'Duplicate archive entry: ' . $entry;
                continue;
            }
            $entries[$entry] = true;
            $size = (int) ($stat['size'] ?? 0);
            $uncompressed += $size;
            if (!str_ends_with($entry, '/')) {
                $fileCount++;
            }
            if ($uncompressed > $maxBytes) {
                $errors[] = 'Uncompressed package size limit exceeded.';
                break;
            }
            if ($this->zipEntryIsSymlink($zip, $index)) {
                $errors[] = 'Symbolic links are not allowed in packages: ' . $entry;
            }
        }

        $manifest = $this->zipJson($zip, self::MANIFEST_PATH, $errors);
        $checksums = $this->zipJson($zip, self::CHECKSUMS_PATH, $errors);

        if (($manifest['schema'] ?? null) !== self::SCHEMA || ($manifest['generator'] ?? null) !== 'site-safeguard') {
            $errors[] = 'Site Safeguard manifest is missing or unsupported.';
        }
        if (($checksums['algorithm'] ?? null) !== 'sha256' || !is_array($checksums['files'] ?? null)) {
            $errors[] = 'Checksum manifest is missing or unsupported.';
        }

        $checksumFiles = is_array($checksums['files'] ?? null) ? $checksums['files'] : [];
        if ($errors === []) {
            foreach ($checksumFiles as $entry => $record) {
                if (count($errors) >= 50) {
                    break;
                }
                if (!is_string($entry) || !str_starts_with($entry, 'site/') || !is_array($record)) {
                    $errors[] = 'Invalid checksum record.';
                    continue;
                }
                try {
                    $this->assertSafeArchiveEntry($entry);
                } catch (\Throwable $e) {
                    $errors[] = $e->getMessage();
                    continue;
                }
                if (!isset($entries[$entry])) {
                    $errors[] = 'Checksum references a missing file: ' . $entry;
                    continue;
                }
                $expectedHash = strtolower((string) ($record['sha256'] ?? ''));
                if (!preg_match('/^[a-f0-9]{64}$/', $expectedHash)) {
                    $errors[] = 'Invalid SHA-256 value: ' . $entry;
                    continue;
                }
                $stat = $zip->statName($entry);
                if (!is_array($stat) || (int) ($stat['size'] ?? -1) !== (int) ($record['size'] ?? -2)) {
                    $errors[] = 'Size mismatch: ' . $entry;
                    continue;
                }
                try {
                    $actualHash = $this->hashZipEntry($zip, $entry);
                } catch (\Throwable $e) {
                    $errors[] = $e->getMessage();
                    continue;
                }
                if (!hash_equals($expectedHash, $actualHash)) {
                    $errors[] = 'Checksum mismatch: ' . $entry;
                }
            }
        } else {
            $warnings[] = 'Deep checksum verification was skipped because structural validation failed.';
        }

        foreach (array_keys($entries) as $entry) {
            if (str_starts_with($entry, 'site/') && !str_ends_with($entry, '/') && !isset($checksumFiles[$entry])) {
                $errors[] = 'Site file is absent from the checksum manifest: ' . $entry;
                if (count($errors) >= 50) {
                    break;
                }
            }
        }

        $profile = is_array($manifest['profile'] ?? null) ? $manifest['profile'] : [];
        if (($profile['deployable'] ?? false) === true) {
            foreach (['site/index.php', 'site/system', 'site/user'] as $required) {
                $found = isset($entries[$required]) || isset($entries[rtrim($required, '/') . '/']);
                if (!$found && !array_filter(array_keys($entries), static fn (string $entry): bool => str_starts_with($entry, rtrim($required, '/') . '/'))) {
                    $errors[] = 'Deployable package is missing required path: ' . substr($required, 5);
                }
            }
        } else {
            $warnings[] = 'This is a partial backup profile; it cannot be staged as a complete site.';
        }

        $zip->close();
        return [
            'name' => basename($path),
            'valid' => $errors === [],
            'errors' => array_values(array_unique($errors)),
            'warnings' => array_values(array_unique(array_merge($warnings, (array) ($manifest['warnings'] ?? [])))),
            'manifest' => $manifest,
            'checksum_file_count' => count($checksumFiles),
            'checksums' => $includeChecksums ? $checksums : null,
            'package_sha256' => hash_file('sha256', $path),
            'package_size' => (int) filesize($path),
            'archive_entries' => $archiveEntryCount,
            'archive_files' => $fileCount,
            'uncompressed_bytes' => $uncompressed,
            'checked_at' => gmdate('c'),
        ];
    }

    private function packageInfo(string $path): array
    {
        $manifest = [];
        $zip = new ZipArchive();
        if ($zip->open($path) === true) {
            $raw = $zip->getFromName(self::MANIFEST_PATH);
            $decoded = is_string($raw) ? json_decode($raw, true) : null;
            if (is_array($decoded)) {
                $manifest = $decoded;
            }
            $zip->close();
        }
        return [
            'name' => basename($path),
            'size' => (int) filesize($path),
            'modified' => (int) filemtime($path),
            'sha256' => hash_file('sha256', $path),
            'manifest' => $manifest,
            'state' => $manifest === [] ? 'unknown' : 'unverified',
        ];
    }

    private function stageInfo(string $path): array
    {
        $recordPath = $path . '/.site-safeguard-stage.json';
        $record = [];
        if (is_file($recordPath)) {
            $decoded = json_decode((string) file_get_contents($recordPath), true);
            if (is_array($decoded)) {
                $record = $decoded;
            }
        }
        return [
            'id' => basename($path),
            'path' => $path,
            'modified' => (int) filemtime($path),
            'record' => $record,
            'verified' => (bool) ($record['verified'] ?? false),
            'promotion_ready' => false,
        ];
    }

    private function verifyStagedFiles(string $root, array $checksums): void
    {
        foreach ($checksums as $entry => $record) {
            if (!is_string($entry) || !str_starts_with($entry, 'site/') || !is_array($record)) {
                throw new ValidationException('Invalid staged checksum record.');
            }
            $relative = substr($entry, 5);
            $path = $this->safeChildPath($root, $relative);
            if (!is_file($path)) {
                throw new ValidationException('Staged file is missing: ' . $relative);
            }
            if ((int) filesize($path) !== (int) ($record['size'] ?? -1)
                || !hash_equals((string) ($record['sha256'] ?? ''), hash_file('sha256', $path))) {
                throw new ValidationException('Staged file verification failed: ' . $relative);
            }
        }

        $expected = array_fill_keys(array_map(
            static fn (string $entry): string => substr($entry, 5),
            array_filter(array_keys($checksums), static fn ($entry): bool => is_string($entry) && str_starts_with($entry, 'site/'))
        ), true);
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $item) {
            if ($item->isLink()) {
                throw new ValidationException('A symbolic link appeared in the staged site.');
            }
            if (!$item->isFile()) {
                continue;
            }
            $relative = ltrim(str_replace('\\', '/', substr($item->getPathname(), strlen($root))), '/');
            if ($relative === '.site-safeguard-stage.json') {
                continue;
            }
            if (!isset($expected[$relative])) {
                throw new ValidationException('Unexpected file appeared in the staged site: ' . $relative);
            }
        }
    }

    private function stagePath(string $id): string
    {
        if ($id !== basename($id) || !preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/', $id)) {
            throw new ValidationException('Invalid stage identifier.');
        }
        $path = $this->stageDirectory() . '/' . $id;
        if (!is_dir($path)) {
            throw new NotFoundException('Stage not found.');
        }
        return $path;
    }

    private function restorePreservePaths(): array
    {
        $configured = $this->normaliseRelativePaths((array) ($this->config['restore_preserve_paths'] ?? []), false);
        return array_values(array_unique(array_merge([
            '.ddev',
            '.git',
            'cache',
            'logs',
            'tmp',
            'backup',
            'backups',
            'file-vault-files',
            'user/config/plugins/site-safeguard.yaml',
        ], $configured)));
    }

    private function restoreHistory(): array
    {
        $files = glob($this->packageDirectory() . '/.restore-*.json') ?: [];
        usort($files, static fn (string $a, string $b): int => filemtime($b) <=> filemtime($a));
        $history = [];
        foreach (array_slice($files, 0, 10) as $file) {
            $decoded = json_decode((string) file_get_contents($file), true);
            if (is_array($decoded)) {
                $history[] = $decoded;
            }
        }
        return $history;
    }

    private function restoreLock()
    {
        $path = $this->packageDirectory() . '/.restore.lock';
        $lock = fopen($path, 'c+');
        if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
            if (is_resource($lock)) {
                fclose($lock);
            }
            throw new ValidationException('Another Site Safeguard restore is already running.');
        }
        @chmod($path, 0600);
        return $lock;
    }

    private function writeJournal(string $path, array $journal): void
    {
        $temporary = $path . '.tmp-' . bin2hex(random_bytes(3));
        if (file_put_contents($temporary, $this->json($journal), LOCK_EX) === false || !rename($temporary, $path)) {
            @unlink($temporary);
            throw new RuntimeException('Unable to update the restore journal.');
        }
        @chmod($path, 0600);
    }

    private function mirrorStageToRoot(string $stage, array $preserve, array &$stats): void
    {
        $this->mirrorDirectory($stage, $this->root, '', $preserve, $stats);
    }

    private function mirrorDirectory(string $source, string $destination, string $relative, array $preserve, array &$stats): void
    {
        if (!is_dir($destination)) {
            if (!mkdir($destination, 0755, true) && !is_dir($destination)) {
                throw new ValidationException('Unable to create restore directory: ' . ($relative ?: '.'));
            }
            $stats['directories_created']++;
        }

        $sourceNames = [];
        foreach (scandir($source) ?: [] as $name) {
            if ($name === '.' || $name === '..' || ($relative === '' && $name === '.site-safeguard-stage.json')) {
                continue;
            }
            $childRelative = $relative === '' ? $name : $relative . '/' . $name;
            $sourceNames[$name] = true;
            if ($this->isRestorePreserved($childRelative, $preserve)) {
                $stats['entries_preserved']++;
                continue;
            }

            $sourcePath = $source . '/' . $name;
            $destinationPath = $destination . '/' . $name;
            if (is_link($sourcePath)) {
                throw new ValidationException('Symbolic links are not allowed in a restore stage: ' . $childRelative);
            }
            if (is_dir($sourcePath)) {
                if (file_exists($destinationPath) && !is_dir($destinationPath)) {
                    $this->removeRestoreEntry($destinationPath);
                    $stats['entries_removed']++;
                }
                $this->mirrorDirectory($sourcePath, $destinationPath, $childRelative, $preserve, $stats);
                continue;
            }
            if (!is_file($sourcePath)) {
                throw new ValidationException('Unsupported staged entry: ' . $childRelative);
            }
            if (is_dir($destinationPath) && !is_link($destinationPath)) {
                $this->removeTree($destinationPath, $this->root);
                $stats['entries_removed']++;
            } elseif (is_link($destinationPath)) {
                if (!unlink($destinationPath)) {
                    throw new ValidationException('Unable to remove destination link: ' . $childRelative);
                }
            }
            $temporary = $destinationPath . '.safeguard-' . bin2hex(random_bytes(3));
            if (!copy($sourcePath, $temporary)) {
                @unlink($temporary);
                throw new ValidationException('Unable to restore file: ' . $childRelative);
            }
            @chmod($temporary, fileperms($sourcePath) & 0777);
            if (!rename($temporary, $destinationPath)) {
                @unlink($temporary);
                throw new ValidationException('Unable to publish restored file: ' . $childRelative);
            }
            @touch($destinationPath, (int) filemtime($sourcePath));
            $stats['files_copied']++;
        }

        foreach (scandir($destination) ?: [] as $name) {
            if ($name === '.' || $name === '..' || isset($sourceNames[$name])) {
                continue;
            }
            $childRelative = $relative === '' ? $name : $relative . '/' . $name;
            if ($this->isRestorePreserved($childRelative, $preserve)) {
                $stats['entries_preserved']++;
                continue;
            }
            $destinationPath = $destination . '/' . $name;
            if ($this->hasPreservedDescendant($childRelative, $preserve) && is_dir($destinationPath) && !is_link($destinationPath)) {
                $this->pruneAbsentDirectory($destinationPath, $childRelative, $preserve, $stats);
                continue;
            }
            $this->removeRestoreEntry($destinationPath);
            $stats['entries_removed']++;
        }
    }

    private function pruneAbsentDirectory(string $path, string $relative, array $preserve, array &$stats): void
    {
        foreach (scandir($path) ?: [] as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $childRelative = $relative . '/' . $name;
            $child = $path . '/' . $name;
            if ($this->isRestorePreserved($childRelative, $preserve)) {
                $stats['entries_preserved']++;
                continue;
            }
            if ($this->hasPreservedDescendant($childRelative, $preserve) && is_dir($child) && !is_link($child)) {
                $this->pruneAbsentDirectory($child, $childRelative, $preserve, $stats);
                continue;
            }
            $this->removeRestoreEntry($child);
            $stats['entries_removed']++;
        }
    }

    private function removeRestoreEntry(string $path): void
    {
        if (is_dir($path) && !is_link($path)) {
            $this->removeTree($path, $this->root);
            return;
        }
        if (!unlink($path)) {
            throw new ValidationException('Unable to remove stale restore entry.');
        }
    }

    private function isRestorePreserved(string $relative, array $preserve): bool
    {
        $relative = trim(str_replace('\\', '/', $relative), '/');
        if ($relative === '.upgrading') {
            return true;
        }
        if (!str_contains($relative, '/')
            && $relative !== '.env.example'
            && ($relative === '.env' || str_starts_with($relative, '.env.'))) {
            return true;
        }
        foreach ($preserve as $path) {
            if ($relative === $path || str_starts_with($relative, $path . '/')) {
                return true;
            }
        }
        return false;
    }

    private function hasPreservedDescendant(string $relative, array $preserve): bool
    {
        foreach ($preserve as $path) {
            if (str_starts_with($path, $relative . '/')) {
                return true;
            }
        }
        return false;
    }

    private function verifyRestoredFiles(array $checksums, array $preserve): int
    {
        $verified = 0;
        foreach ($checksums as $entry => $record) {
            if (!is_string($entry) || !str_starts_with($entry, 'site/') || !is_array($record)) {
                throw new ValidationException('Invalid restore checksum record.');
            }
            $relative = substr($entry, 5);
            if ($this->isRestorePreserved($relative, $preserve)) {
                continue;
            }
            $path = $this->safeChildPath($this->root, $relative);
            if (!is_file($path)
                || (int) filesize($path) !== (int) ($record['size'] ?? -1)
                || !hash_equals((string) ($record['sha256'] ?? ''), hash_file('sha256', $path))) {
                throw new ValidationException('Restored file verification failed: ' . $relative);
            }
            $verified++;
        }
        return $verified;
    }

    private function clearRuntimeCache(): void
    {
        $cache = $this->root . '/cache';
        if (!is_dir($cache)) {
            return;
        }
        foreach (scandir($cache) ?: [] as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $path = $cache . '/' . $name;
            if (is_dir($path) && !is_link($path)) {
                $this->removeTree($path, $cache);
            } else {
                @unlink($path);
            }
        }
    }

    private function verifyGravBoot(string $root, bool $temporaryRuntime): void
    {
        if (!($this->config['restore_boot_check'] ?? true)) {
            return;
        }
        if (!function_exists('proc_open')) {
            throw new ValidationException('Fresh-process boot checks require proc_open().');
        }
        $binary = trim((string) ($this->config['php_cli_path'] ?? 'php'));
        if ($binary === '' || str_contains($binary, "\0") || !preg_match('#^[A-Za-z0-9._/+-]+$#', $binary)) {
            throw new ValidationException('The configured PHP CLI executable is invalid.');
        }
        $index = rtrim($root, '/') . '/index.php';
        if (!is_file($index)) {
            throw new ValidationException('The restore candidate has no index.php boot entrypoint.');
        }

        $runtimePaths = [];
        if ($temporaryRuntime) {
            foreach (['cache', 'logs', 'tmp', 'backup'] as $relative) {
                $path = rtrim($root, '/') . '/' . $relative;
                if (!file_exists($path)) {
                    $this->ensureDirectory($path);
                    $runtimePaths[] = $path;
                }
            }
        }

        $outputPath = $this->packageDirectory() . '/.boot-output-' . bin2hex(random_bytes(6));
        $errorPath = $this->packageDirectory() . '/.boot-error-' . bin2hex(random_bytes(6));
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['file', $outputPath, 'w'],
            2 => ['file', $errorPath, 'w'],
        ];
        $pipes = [];
        $process = proc_open([$binary, $index], $descriptors, $pipes, $root);
        if (!is_resource($process)) {
            @unlink($outputPath);
            @unlink($errorPath);
            $this->removeTemporaryRuntime($runtimePaths, $root);
            throw new ValidationException('Unable to start the isolated Grav boot check.');
        }
        fclose($pipes[0]);
        $exit = proc_close($process);
        $output = is_file($outputPath) ? (string) file_get_contents($outputPath) : '';
        $errors = is_file($errorPath) ? (string) file_get_contents($errorPath) : '';
        @unlink($outputPath);
        @unlink($errorPath);
        $this->removeTemporaryRuntime($runtimePaths, $root);

        $failedPage = str_contains($output, '<title>Grav Problems</title>')
            || str_contains($output, 'Whoops there was an error!');
        if ($exit !== 0 || $failedPage) {
            $detail = trim(strip_tags($errors !== '' ? $errors : substr($output, 0, 1000)));
            $detail = preg_replace('/\s+/', ' ', $detail) ?: 'Grav did not boot successfully.';
            throw new ValidationException('Fresh-process Grav boot check failed: ' . substr($detail, 0, 300));
        }
    }

    private function removeTemporaryRuntime(array $paths, string $root): void
    {
        foreach (array_reverse($paths) as $path) {
            if (is_dir($path)) {
                $this->removeTree($path, $root);
            }
        }
    }

    private function isExcluded(string $relative, array $profile): bool
    {
        $relative = trim(str_replace('\\', '/', $relative), '/');
        $base = basename($relative);
        if ($base === '.DS_Store' || str_starts_with($base, '._') || $base === 'Thumbs.db') {
            return true;
        }
        if (preg_match('/^\.env(?:\..+)?$/', $base) && $base !== '.env.example') {
            return true;
        }
        $excludes = array_merge(
            $this->normaliseRelativePaths((array) ($this->config['exclude_paths'] ?? []), false),
            (array) ($profile['exclude_paths'] ?? [])
        );
        foreach ($excludes as $prefix) {
            if ($relative === $prefix || str_starts_with($relative, $prefix . '/')
                || (in_array($prefix, ['.git', '.github', '.idea', '.vscode', 'node_modules', '__MACOSX'], true)
                    && in_array($prefix, explode('/', $relative), true))) {
                return true;
            }
        }
        return false;
    }

    private function packagePath(string $name): string
    {
        if ($name !== basename($name) || !preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*\.zip$/', $name)) {
            throw new ValidationException('Invalid package filename.');
        }
        $path = $this->packageDirectory() . '/' . $name;
        if (!is_file($path)) {
            throw new NotFoundException('Package not found.');
        }
        return $path;
    }

    private function packageDirectory(): string
    {
        return $this->outsideDirectory((string) ($this->config['package_path'] ?? '../site-safeguard-packages'), 'package');
    }

    private function stageDirectory(): string
    {
        return $this->outsideDirectory((string) ($this->config['stage_path'] ?? '../site-safeguard-stage'), 'stage');
    }

    private function outsideDirectory(string $configured, string $purpose): string
    {
        $configured = trim(str_replace('\\', '/', $configured));
        if ($configured === '' || str_contains($configured, "\0")) {
            throw new ValidationException('Invalid ' . $purpose . ' directory.');
        }
        $path = str_starts_with($configured, '/') ? $configured : $this->root . '/' . $configured;
        $this->ensureDirectory($path);
        $real = realpath($path);
        if ($real === false) {
            throw new ValidationException('Unable to resolve the ' . $purpose . ' directory.');
        }
        $real = rtrim(str_replace('\\', '/', $real), '/');
        if ($real === $this->root || str_starts_with($real . '/', $this->root . '/')) {
            throw new ValidationException(ucfirst($purpose) . ' directory must be outside the public Grav root.');
        }
        return $real;
    }

    private function safeChildPath(string $root, string $relative): string
    {
        $relative = trim(str_replace('\\', '/', $relative), '/');
        if ($relative === '' || preg_match('#(^|/)\.\.(/|$)#', $relative) || str_contains($relative, "\0")) {
            throw new ValidationException('Unsafe relative path.');
        }
        $candidate = rtrim(str_replace('\\', '/', $root), '/') . '/' . $relative;
        $parent = realpath(dirname($candidate));
        $rootReal = realpath($root);
        if ($parent !== false && $rootReal !== false) {
            $parent = str_replace('\\', '/', $parent);
            $rootReal = rtrim(str_replace('\\', '/', $rootReal), '/');
            if ($parent !== $rootReal && !str_starts_with($parent . '/', $rootReal . '/')) {
                throw new ValidationException('Path escaped the staging directory.');
            }
        }
        return $candidate;
    }

    private function assertSafeArchiveEntry(string $entry): void
    {
        if ($entry === '' || str_contains($entry, "\0") || str_contains($entry, '\\')
            || str_starts_with($entry, '/') || preg_match('/^[A-Za-z]:/', $entry)
            || preg_match('#(^|/)\.\.(/|$)#', $entry)) {
            throw new ValidationException('Unsafe archive entry: ' . ($entry ?: '(blank)'));
        }
    }

    private function zipEntryIsSymlink(ZipArchive $zip, int $index): bool
    {
        if (!method_exists($zip, 'getExternalAttributesIndex')) {
            return false;
        }
        $opsys = 0;
        $attributes = 0;
        if (!$zip->getExternalAttributesIndex($index, $opsys, $attributes)) {
            return false;
        }
        return (($attributes >> 16) & 0170000) === 0120000;
    }

    private function hashZipEntry(ZipArchive $zip, string $entry): string
    {
        $stream = $zip->getStream($entry);
        if (!is_resource($stream)) {
            throw new ValidationException('Unable to read package entry: ' . $entry);
        }
        $context = hash_init('sha256');
        while (!feof($stream)) {
            $chunk = fread($stream, 1024 * 1024);
            if ($chunk === false) {
                fclose($stream);
                throw new ValidationException('Unable to hash package entry: ' . $entry);
            }
            hash_update($context, $chunk);
        }
        fclose($stream);
        return hash_final($context);
    }

    private function zipJson(ZipArchive $zip, string $entry, array &$errors): array
    {
        $raw = $zip->getFromName($entry);
        if (!is_string($raw) || $raw === '' || strlen($raw) > 50 * 1024 * 1024) {
            $errors[] = 'Missing or oversized metadata entry: ' . $entry;
            return [];
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            $errors[] = 'Invalid JSON metadata entry: ' . $entry;
            return [];
        }
        return $decoded;
    }

    private function normaliseRelativePaths(array $paths, bool $allowDot): array
    {
        $result = [];
        foreach ($paths as $path) {
            $path = trim(str_replace('\\', '/', (string) $path), '/');
            if ($allowDot && ($path === '' || $path === '.')) {
                $result[] = '.';
                continue;
            }
            if ($path === '' || $path === '.' || str_starts_with($path, '/')
                || preg_match('#(^|/)\.\.(/|$)#', $path) || str_contains($path, "\0")) {
                continue;
            }
            $result[] = $path;
        }
        return array_values(array_unique($result));
    }

    private function maxPackageBytes(): int
    {
        return max(1048576, (int) ($this->config['max_package_bytes'] ?? 2147483648));
    }

    private function maxUncompressedBytes(): int
    {
        return max(1048576, (int) ($this->config['max_uncompressed_bytes'] ?? 5368709120));
    }

    private function assertEntryCapacity(array $stats): void
    {
        $entries = (int) ($stats['files'] ?? 0) + (int) ($stats['directories'] ?? 0) + 2;
        $maximum = max(100, (int) ($this->config['max_archive_entries'] ?? 100000));
        if ($entries >= $maximum) {
            throw new ValidationException('Package content exceeds the configured archive entry limit.');
        }
    }

    private function assertPackageCapacity(): void
    {
        $maximum = max(1, min(100, (int) ($this->config['max_packages'] ?? 12)));
        $count = count(glob($this->packageDirectory() . '/*.zip') ?: []);
        if ($count >= $maximum) {
            throw new ValidationException(sprintf(
                'The package retention limit (%d) has been reached. Delete an obsolete package explicitly before creating or importing another.',
                $maximum
            ));
        }
    }

    private function safeHost(): string
    {
        $host = method_exists($this->grav['uri'], 'host') ? (string) $this->grav['uri']->host() : 'grav-site';
        $host = strtolower(preg_replace('/[^A-Za-z0-9.-]+/', '-', $host) ?: 'grav-site');
        return trim($host, '.-') ?: 'grav-site';
    }

    private function tokensPath(): string
    {
        return $this->packageDirectory() . '/.download-tokens.json';
    }

    private function mutateTokens(callable $callback): mixed
    {
        $path = $this->tokensPath();
        $lock = fopen($path . '.lock', 'c+');
        if ($lock === false || !flock($lock, LOCK_EX)) {
            if (is_resource($lock)) {
                fclose($lock);
            }
            throw new RuntimeException('Unable to lock package download tokens.');
        }
        @chmod($path . '.lock', 0600);

        try {
            $tokens = [];
            if (is_file($path)) {
                $decoded = json_decode((string) file_get_contents($path), true);
                $tokens = is_array($decoded) ? $decoded : [];
            }
            $result = $callback($tokens);
            $temporary = $path . '.tmp-' . bin2hex(random_bytes(4));
            if (file_put_contents($temporary, $this->json($tokens)) === false || !rename($temporary, $path)) {
                @unlink($temporary);
                throw new RuntimeException('Unable to update package download tokens.');
            }
            @chmod($path, 0600);
            return $result;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function removeTree(string $path, string $allowedRoot): void
    {
        $realRoot = realpath($allowedRoot);
        $realPath = realpath($path);
        if ($realRoot === false || $realPath === false) {
            throw new ValidationException('Unable to resolve deletion target.');
        }
        $realRoot = rtrim(str_replace('\\', '/', $realRoot), '/');
        $realPath = rtrim(str_replace('\\', '/', $realPath), '/');
        if ($realPath === $realRoot || !str_starts_with($realPath . '/', $realRoot . '/')) {
            throw new ForbiddenException('Refusing to remove a path outside the staging root.');
        }
        $entries = scandir($realPath) ?: [];
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $child = $realPath . '/' . $entry;
            if (is_dir($child) && !is_link($child)) {
                $this->removeTree($child, $allowedRoot);
            } elseif (!unlink($child)) {
                throw new ValidationException('Unable to remove staged file.');
            }
        }
        if (!rmdir($realPath)) {
            throw new ValidationException('Unable to remove staged directory.');
        }
    }

    private function ensureDirectory(string $path): void
    {
        if (!is_dir($path) && !mkdir($path, 0700, true) && !is_dir($path)) {
            throw new ValidationException('Unable to create directory: ' . $path);
        }
    }

    private function assertZipAvailable(): void
    {
        if (!class_exists(ZipArchive::class)) {
            throw new ValidationException('PHP ZIP support is required.');
        }
    }

    private function json(array $data): string
    {
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        return $json . "\n";
    }
}
