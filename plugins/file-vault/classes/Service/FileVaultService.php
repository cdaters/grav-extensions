<?php

declare(strict_types=1);

namespace Grav\Plugin\FileVault\Service;

use Grav\Common\Grav;
use Psr\Http\Message\UploadedFileInterface;
use RocketTheme\Toolbox\File\YamlFile;

class FileVaultService
{
    private Grav $grav;

    /** @var array<string,mixed> */
    private array $config;

    public function __construct()
    {
        $this->grav = Grav::instance();
        $this->config = (array) $this->grav['config']->get('plugins.file-vault', []);
    }

    /** @return array<string,mixed> */
    public function adminStatus(): array
    {
        $catalog = $this->catalogData();
        $items = $catalog['items'];
        $categories = $catalog['categories'];
        $stats = $this->readJson($this->statsPath(), ['downloads' => []]);
        $downloads = (array) ($stats['downloads'] ?? []);
        $totalBytes = 0;
        $totalDownloads = 0;

        foreach ($items as &$item) {
            $item = $this->enrichItem($item, $downloads, false);
            $totalBytes += (int) $item['bytes'];
            $totalDownloads += (int) $item['download_count'];
        }
        unset($item);

        foreach ($categories as &$category) {
            $category['file_count'] = 0;
            $category['bytes'] = 0;
            $category['downloads'] = 0;
            foreach ($items as $item) {
                if (strcasecmp((string) $item['category'], (string) $category['name']) !== 0) {
                    continue;
                }
                ++$category['file_count'];
                $category['bytes'] += (int) $item['bytes'];
                $category['downloads'] += (int) $item['download_count'];
            }
        }
        unset($category);

        return [
            'items' => $items,
            'categories' => $categories,
            'public_settings' => $this->publicSettings(),
            'vault_settings' => $this->vaultSettings(),
            'activity_settings' => $this->activitySettings(),
            'activity_count' => count((array) ($this->readJson($this->activityPath(), ['events' => []])['events'] ?? [])),
            'totals' => [
                'files' => count($items),
                'bytes' => $totalBytes,
                'downloads' => $totalDownloads,
            ],
            'storage_path' => $this->storagePath(),
            'public_route' => (string) ($this->config['public_route'] ?? '/downloads'),
            'allowed_extensions' => array_values((array) ($this->config['allowed_extensions'] ?? ['zip'])),
            'max_upload_size' => (int) ($this->config['max_upload_size'] ?? 104857600),
        ];
    }

    /** @return array<string,mixed> */
    public function publicCatalog(): array
    {
        $catalog = $this->catalogData();
        $items = $catalog['items'];
        $stats = $this->readJson($this->statsPath(), ['downloads' => []]);
        $downloads = (array) ($stats['downloads'] ?? []);
        $showLocked = (bool) ($this->config['show_locked'] ?? true);
        $categories = [];
        $public = [];
        $totalDownloads = 0;

        foreach ($items as $item) {
            if (!(bool) ($item['enabled'] ?? true) || !(bool) ($item['listed'] ?? true)) {
                continue;
            }

            $item = $this->enrichItem($item, $downloads, true);
            $totalDownloads += (int) $item['download_count'];
            if ($item['exhausted']) {
                continue;
            }
            if ($item['locked'] && !$showLocked) {
                continue;
            }

            if ($item['category'] !== '') {
                $categories[$item['category']] = true;
            }
            $public[] = $item;
        }

        usort($public, static function (array $a, array $b): int {
            $order = ((int) ($a['sort_order'] ?? 0)) <=> ((int) ($b['sort_order'] ?? 0));
            return $order !== 0 ? $order : strcasecmp((string) $a['display_name'], (string) $b['display_name']);
        });

        $categoryNames = [];
        $categoryDetails = [];
        foreach ($catalog['categories'] as $category) {
            $name = (string) $category['name'];
            if (isset($categories[$name])) {
                $categoryNames[] = $name;
                $categoryDetails[] = [
                    'id' => (string) $category['id'],
                    'name' => $name,
                    'label' => (string) ($category['label'] ?? $name),
                    'description' => (string) ($category['description'] ?? ''),
                    'section' => (string) ($category['section'] ?? ''),
                    'section_id' => (string) ($category['section_id'] ?? ''),
                    'group' => (string) ($category['group'] ?? ''),
                    'group_id' => (string) ($category['group_id'] ?? ''),
                    'group_order' => (int) ($category['group_order'] ?? 0),
                    'file_count' => count(array_filter($public, static fn(array $item): bool => strcasecmp((string) $item['category'], $name) === 0)),
                ];
                unset($categories[$name]);
            }
        }
        $orphanNames = array_keys($categories);
        natcasesort($orphanNames);
        $categoryNames = array_merge($categoryNames, array_values($orphanNames));
        foreach ($orphanNames as $name) {
            $id = strtolower(trim((string) preg_replace('/[^a-z0-9]+/i', '-', $name), '-'));
            $categoryDetails[] = [
                'id' => $id,
                'name' => $name,
                'label' => $name,
                'description' => '',
                'section' => '',
                'section_id' => '',
                'group' => '',
                'group_id' => '',
                'group_order' => 0,
                'file_count' => count(array_filter($public, static fn(array $item): bool => strcasecmp((string) $item['category'], $name) === 0)),
            ];
        }

        $categoryIds = [];
        $categoryMetadata = [];
        foreach ($categoryDetails as $detail) {
            $categoryIds[strtolower((string) $detail['name'])] = (string) $detail['id'];
            $categoryMetadata[strtolower((string) $detail['name'])] = $detail;
        }
        foreach ($public as &$item) {
            $key = strtolower((string) $item['category']);
            $detail = $categoryMetadata[$key] ?? [];
            $item['category_id'] = $categoryIds[$key] ?? '';
            $item['category_label'] = (string) ($detail['label'] ?? $item['category']);
            $item['section_label'] = (string) ($detail['section'] ?? '');
            $item['provenance_label'] = (string) ($item['provenance'] ?? ($detail['group'] ?? ''));
            $item['provenance_id'] = (string) ($detail['group_id'] ?? '');
        }
        unset($item);

        $groups = [];
        foreach ($categoryDetails as $detail) {
            $groupId = (string) ($detail['group_id'] ?? '');
            if ($groupId === '') {
                continue;
            }
            if (!isset($groups[$groupId])) {
                $groups[$groupId] = [
                    'id' => $groupId,
                    'name' => (string) ($detail['group'] ?? ''),
                    'sort_order' => (int) ($detail['group_order'] ?? 0),
                    'file_count' => 0,
                    'categories' => [],
                ];
            }
            $groups[$groupId]['file_count'] += (int) ($detail['file_count'] ?? 0);
            $groups[$groupId]['categories'][] = $detail;
        }
        usort($groups, static fn(array $a, array $b): int => ((int) $a['sort_order']) <=> ((int) $b['sort_order']));

        $sectionLabels = array_values(array_unique(array_filter(array_map(
            static fn(array $detail): string => trim((string) ($detail['section'] ?? '')),
            $categoryDetails
        ))));

        return [
            'items' => array_values($public),
            'categories' => array_values($categoryNames),
            'category_details' => $categoryDetails,
            'groups' => array_values($groups),
            'section_label' => count($sectionLabels) === 1 ? $sectionLabels[0] : '',
            'total_downloads' => $totalDownloads,
            'count' => count($public),
            'settings' => $this->publicSettings(),
        ];
    }

    /**
     * Resolve one enabled item for an inline download. Unlike publicCatalog(),
     * this intentionally includes unlisted assets while preserving every
     * normal ACL, password, missing-file, and download-limit check.
     *
     * @return array<string,mixed>|null
     */
    public function publicItem(string $needle): ?array
    {
        $needle = trim($needle);
        if ($needle === '') {
            return null;
        }

        $stats = $this->readJson($this->statsPath(), ['downloads' => []]);
        $downloads = (array) ($stats['downloads'] ?? []);
        foreach ($this->catalogItems() as $item) {
            if (!(bool) ($item['enabled'] ?? true)) {
                continue;
            }
            if (strcasecmp((string) ($item['id'] ?? ''), $needle) !== 0
                && strcasecmp((string) ($item['filename'] ?? ''), basename($needle)) !== 0
                && strcasecmp((string) ($item['download_name'] ?? ''), basename($needle)) !== 0) {
                continue;
            }

            $item = $this->enrichItem($item, $downloads, true);
            return $item['exhausted'] ? null : $item;
        }

        return null;
    }

    /** @param array<string,mixed> $values
     *  @return array<string,mixed>
     */
    public function savePublicSettings(array $values): array
    {
        $settings = $this->publicSettings();
        $catalog = $this->catalogData();
        $allowedCategories = array_column($catalog['categories'], 'id');
        $view = strtolower(trim((string) ($values['default_view'] ?? $settings['default_view'])));
        $category = strtolower(trim((string) ($values['default_category'] ?? $settings['default_category'])));
        if (!in_array($view, ['list', 'grid'], true)) {
            throw new \RuntimeException('Default view must be list or grid.');
        }
        if ($category !== '' && !in_array($category, $allowedCategories, true)) {
            throw new \RuntimeException('The selected default category does not exist.');
        }

        foreach (['hero_kicker' => 120, 'hero_title' => 120, 'hero_intro' => 1000, 'search_placeholder' => 160] as $key => $limit) {
            if (array_key_exists($key, $values)) {
                $settings[$key] = mb_substr(trim((string) $values[$key]), 0, $limit);
            }
        }
        foreach (['show_intro', 'show_file_count', 'show_download_count'] as $key) {
            if (array_key_exists($key, $values)) {
                $settings[$key] = filter_var($values[$key], FILTER_VALIDATE_BOOL);
            }
        }
        $settings['default_view'] = $view;
        $settings['default_category'] = $category;

        $configPath = rtrim((string) $this->grav['locator']->findResource('user://config', true), '/') . '/plugins/file-vault.yaml';
        $this->ensureDirectory(dirname($configPath));
        $file = YamlFile::instance($configPath);
        $content = (array) $file->content();
        $content['public'] = $settings;
        $file->content($content);
        $file->save();
        $this->grav['config']->set('plugins.file-vault.public', $settings);
        $this->config['public'] = $settings;

        return ['message' => 'Public Downloads settings saved.', 'settings' => $settings];
    }

    /** @param array<string,mixed> $values
     *  @return array<string,mixed>
     */
    public function saveVaultSettings(array $values): array
    {
        $raw = $values['allowed_extensions'] ?? [];
        if (is_string($raw)) {
            $raw = preg_split('/[\s,]+/', $raw, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        }
        $extensions = [];
        foreach ((array) $raw as $extension) {
            $extension = strtolower(ltrim(trim((string) $extension), '.'));
            if ($extension === '' || !preg_match('/^[a-z0-9]{1,12}$/', $extension)) {
                throw new \RuntimeException('Allowed file types must be extensions containing only letters and numbers.');
            }
            $extensions[] = $extension;
        }
        $extensions = array_values(array_unique($extensions));
        if (!$extensions) {
            throw new \RuntimeException('At least one allowed file type is required.');
        }

        $configPath = rtrim((string) $this->grav['locator']->findResource('user://config', true), '/') . '/plugins/file-vault.yaml';
        $this->ensureDirectory(dirname($configPath));
        $file = YamlFile::instance($configPath);
        $content = (array) $file->content();
        $content['allowed_extensions'] = $extensions;
        $activity = $this->activitySettings();
        if (isset($values['activity']) && is_array($values['activity'])) {
            $submitted = $values['activity'];
            $mode = strtolower(trim((string) ($submitted['ip_mode'] ?? $activity['ip_mode'])));
            if (!in_array($mode, ['none', 'hash', 'full'], true)) {
                throw new \RuntimeException('IP storage must be none, hashed, or full.');
            }
            $retention = (int) ($submitted['retention_days'] ?? $activity['retention_days']);
            if ($retention < 1 || $retention > 3650) {
                throw new \RuntimeException('Activity retention must be between 1 and 3650 days.');
            }
            $activity = [
                'enabled' => filter_var($submitted['enabled'] ?? $activity['enabled'], FILTER_VALIDATE_BOOL),
                'ip_mode' => $mode,
                'retention_days' => $retention,
                'store_user_agent' => filter_var($submitted['store_user_agent'] ?? $activity['store_user_agent'], FILTER_VALIDATE_BOOL),
                'trust_proxy_headers' => filter_var($submitted['trust_proxy_headers'] ?? $activity['trust_proxy_headers'], FILTER_VALIDATE_BOOL),
            ];
        }
        $content['activity'] = $activity;
        $file->content($content);
        $file->save();
        $this->grav['config']->set('plugins.file-vault.allowed_extensions', $extensions);
        $this->config['allowed_extensions'] = $extensions;
        $this->grav['config']->set('plugins.file-vault.activity', $activity);
        $this->config['activity'] = $activity;

        return ['message' => 'Vault settings saved.', 'settings' => $this->vaultSettings()];
    }

    /** @return array<string,mixed> */
    public function activity(int $limit = 100): array
    {
        $limit = max(1, min(500, $limit));
        $events = (array) ($this->readJson($this->activityPath(), ['events' => []])['events'] ?? []);
        $events = array_values(array_filter($events, 'is_array'));
        usort($events, static fn(array $a, array $b): int => strcmp((string) ($b['at'] ?? ''), (string) ($a['at'] ?? '')));
        return [
            'events' => array_slice($events, 0, $limit),
            'count' => count($events),
            'settings' => $this->activitySettings(),
        ];
    }

    /** @return array<string,mixed> */
    public function purgeActivity(): array
    {
        $this->writeJson($this->activityPath(), ['schema' => 1, 'events' => []]);
        return ['message' => 'Download activity log cleared.'];
    }

    public function recordDownloadActivity(string $id, string $sourceType = 'file'): void
    {
        $settings = $this->activitySettings();
        if (!$settings['enabled']) {
            return;
        }

        $item = $this->findById($id, $this->catalogItems());
        $event = [
            'at' => gmdate('c'),
            'item_id' => $id,
            'item_name' => (string) ($item['display_name'] ?? $id),
            'source_type' => $sourceType === 'url' ? 'url' : 'file',
            'user' => $this->currentUsername(),
            'ip_mode' => (string) $settings['ip_mode'],
            'ip' => $this->activityIp((string) $settings['ip_mode'], (bool) $settings['trust_proxy_headers']),
        ];
        if ($settings['store_user_agent']) {
            $event['user_agent'] = mb_substr(trim((string) ($_SERVER['HTTP_USER_AGENT'] ?? '')), 0, 300);
        }

        $path = $this->activityPath();
        $this->ensureDirectory(dirname($path));
        $handle = fopen($path, 'c+');
        if ($handle === false || !flock($handle, LOCK_EX)) {
            throw new \RuntimeException('Unable to lock the download activity log.');
        }
        $raw = stream_get_contents($handle);
        $data = is_string($raw) && $raw !== '' ? json_decode($raw, true) : [];
        $data = is_array($data) ? $data : [];
        $events = array_values(array_filter((array) ($data['events'] ?? []), 'is_array'));
        $cutoff = time() - ((int) $settings['retention_days'] * 86400);
        $events = array_values(array_filter($events, static function (array $entry) use ($cutoff): bool {
            $timestamp = strtotime((string) ($entry['at'] ?? ''));
            return $timestamp !== false && $timestamp >= $cutoff;
        }));
        $events[] = $event;
        if (count($events) > 5000) {
            $events = array_slice($events, -5000);
        }
        $this->rewriteLockedJson($handle, ['schema' => 1, 'events' => $events]);
        flock($handle, LOCK_UN);
        fclose($handle);
    }

    /** @return array<string,mixed> */
    public function upload(UploadedFileInterface $upload, string $category = '', bool $listed = true): array
    {
        if ($upload->getError() !== UPLOAD_ERR_OK) {
            throw new \RuntimeException('Upload failed with error code ' . $upload->getError() . '.');
        }

        $size = (int) ($upload->getSize() ?? 0);
        $max = (int) ($this->config['max_upload_size'] ?? 104857600);
        if ($size < 1 || $size > $max) {
            throw new \RuntimeException('The uploaded file is empty or exceeds the configured size limit.');
        }

        $original = basename((string) $upload->getClientFilename());
        $safe = preg_replace('/[^A-Za-z0-9._-]+/', '-', $original) ?: 'download.bin';
        $safe = ltrim($safe, '.-');
        $extension = strtolower((string) pathinfo($safe, PATHINFO_EXTENSION));
        $allowed = array_map('strtolower', (array) ($this->config['allowed_extensions'] ?? ['zip']));
        if (!in_array($extension, $allowed, true)) {
            throw new \RuntimeException('Files with the .' . $extension . ' extension are not allowed.');
        }

        $storage = $this->storagePath();
        $this->ensureDirectory($storage);
        $candidate = $safe;
        $stem = pathinfo($safe, PATHINFO_FILENAME);
        $counter = 2;
        while (is_file($storage . '/' . $candidate)) {
            $candidate = $stem . '-' . $counter . '.' . $extension;
            ++$counter;
        }

        $upload->moveTo($storage . '/' . $candidate);
        @chmod($storage . '/' . $candidate, 0644);

        $catalog = $this->catalogData();
        $items = $catalog['items'];
        $category = trim($category);
        $knownCategory = false;
        foreach ($catalog['categories'] as $candidateCategory) {
            if (strcasecmp((string) $candidateCategory['name'], $category) === 0) {
                $category = (string) $candidateCategory['name'];
                $knownCategory = true;
                break;
            }
        }
        $id = $this->uniqueId($stem, $items);
        $item = $this->normalizeItem([
            'id' => $id,
            'filename' => $candidate,
            'display_name' => $this->humanize($stem),
            'download_name' => $candidate,
            'version' => '',
            'description' => '',
            'category' => $knownCategory ? $category : '',
            'tags' => [],
            'published_at' => date('Y-m-d'),
            'featured' => false,
            'enabled' => true,
            'listed' => $listed,
            'access' => '',
            'sort_order' => count($items) + 1,
            'checksum_sha256' => hash_file('sha256', $storage . '/' . $candidate) ?: '',
        ]);
        $items[] = $item;
        $this->writeCatalog($items, $catalog['categories']);

        return ['message' => $candidate . ' uploaded.', 'item' => $this->enrichItem($item, [], false)];
    }

    /** @param array<string,mixed> $values
     *  @return array<string,mixed>
     */
    public function createUrlItem(array $values): array
    {
        $url = $this->validateExternalUrl((string) ($values['external_url'] ?? ''));
        $displayName = mb_substr(trim((string) ($values['display_name'] ?? '')), 0, 180);
        if ($displayName === '') {
            throw new \RuntimeException('A display name is required for a URL download.');
        }

        $catalog = $this->catalogData();
        $category = trim((string) ($values['category'] ?? ''));
        if ($category !== '') {
            $matched = '';
            foreach ($catalog['categories'] as $candidate) {
                if ((string) $candidate['id'] === $category || strcasecmp((string) $candidate['name'], $category) === 0) {
                    $matched = (string) $candidate['name'];
                    break;
                }
            }
            if ($matched === '') {
                throw new \RuntimeException('The selected category does not exist.');
            }
            $category = $matched;
        }

        $pathName = basename((string) (parse_url($url, PHP_URL_PATH) ?: ''));
        $downloadName = basename(trim((string) ($values['download_name'] ?? $pathName)));
        if ($downloadName === '' || $downloadName === '.' || $downloadName === '/') {
            $downloadName = 'external-download';
        }
        $id = $this->uniqueId($displayName, $catalog['items']);
        $item = $this->normalizeItem([
            'id' => $id,
            'source_type' => 'url',
            'filename' => $downloadName,
            'external_url' => $url,
            'display_name' => $displayName,
            'download_name' => $downloadName,
            'description' => trim((string) ($values['description'] ?? '')),
            'category' => $category,
            'published_at' => date('Y-m-d'),
            'enabled' => true,
            'listed' => filter_var($values['listed'] ?? true, FILTER_VALIDATE_BOOL),
            'sort_order' => count($catalog['items']) + 1,
        ]);
        $catalog['items'][] = $item;
        $this->writeCatalog($catalog['items'], $catalog['categories']);

        return ['message' => 'Secure URL download added.', 'item' => $this->enrichItem($item, [], false)];
    }

    /** @param array<string,mixed> $changes
     *  @return array<string,mixed>
     */
    public function saveItem(string $id, array $changes): array
    {
        $catalog = $this->catalogData();
        $items = $catalog['items'];
        if (array_key_exists('category', $changes) && trim((string) $changes['category']) !== '') {
            $requested = trim((string) $changes['category']);
            $matched = '';
            foreach ($catalog['categories'] as $category) {
                if (strcasecmp((string) $category['name'], $requested) === 0) {
                    $matched = (string) $category['name'];
                    break;
                }
            }
            if ($matched === '') {
                throw new \RuntimeException('The selected category does not exist.');
            }
            $changes['category'] = $matched;
        }
        $found = false;
        $allowed = [
            'display_name', 'download_name', 'version', 'description', 'category', 'tags',
            'published_at', 'featured', 'enabled', 'listed', 'access', 'sort_order', 'download_limit',
            'original_filename', 'date_basis', 'author', 'publisher', 'provenance',
            'provenance_confidence', 'provenance_note', 'license', 'requirements',
            'compatibility', 'work_files', 'rights_review_required',
        ];

        foreach ($items as &$item) {
            if ((string) $item['id'] !== $id) {
                continue;
            }
            foreach ($allowed as $key) {
                if (array_key_exists($key, $changes)) {
                    $item[$key] = $changes[$key];
                }
            }
            if ((string) ($item['source_type'] ?? 'file') === 'url' && array_key_exists('external_url', $changes)) {
                $item['external_url'] = $this->validateExternalUrl((string) $changes['external_url']);
            }
            $password = (string) ($changes['download_password'] ?? '');
            if (filter_var($changes['clear_password'] ?? false, FILTER_VALIDATE_BOOL)) {
                $item['password_hash'] = '';
            } elseif ($password !== '') {
                if (mb_strlen($password) < 8) {
                    throw new \RuntimeException('Download passwords must contain at least 8 characters.');
                }
                $item['password_hash'] = password_hash($password, PASSWORD_DEFAULT);
            }
            $item = $this->normalizeItem($item);
            $found = true;
            break;
        }
        unset($item);

        if (!$found) {
            throw new \RuntimeException('Catalog item not found.');
        }

        $this->writeCatalog($items);
        $stats = $this->readJson($this->statsPath(), ['downloads' => []]);
        return [
            'message' => 'Metadata saved.',
            'item' => $this->enrichItem($this->findById($id, $items), (array) ($stats['downloads'] ?? []), false),
        ];
    }

    /** @return array<string,mixed> */
    public function deleteItem(string $id, bool $deleteFile): array
    {
        $items = $this->catalogItems();
        $remaining = [];
        $removed = null;
        foreach ($items as $item) {
            if ((string) $item['id'] === $id) {
                $removed = $item;
            } else {
                $remaining[] = $item;
            }
        }

        if (!$removed) {
            throw new \RuntimeException('Catalog item not found.');
        }

        $isUrl = (string) ($removed['source_type'] ?? 'file') === 'url';
        if ($deleteFile && !$isUrl) {
            $path = $this->filePath((string) $removed['filename']);
            if (is_file($path) && !unlink($path)) {
                throw new \RuntimeException('Unable to delete the stored file.');
            }
        }

        $this->writeCatalog($remaining);
        $this->removeDownloadStats($id);
        return ['message' => $isUrl
            ? 'Secure URL entry removed.'
            : ($deleteFile ? 'Catalog item and stored file deleted.' : 'Catalog item removed; stored file kept.')];
    }

    private function removeDownloadStats(string $id): void
    {
        $path = $this->statsPath();
        $this->ensureDirectory(dirname($path));
        $handle = fopen($path, 'c+');
        if ($handle === false || !flock($handle, LOCK_EX)) {
            throw new \RuntimeException('Unable to lock download statistics.');
        }
        $raw = stream_get_contents($handle);
        $stats = is_string($raw) && $raw !== '' ? json_decode($raw, true) : [];
        $stats = is_array($stats) ? $stats : [];
        $stats['downloads'] = (array) ($stats['downloads'] ?? []);
        $stats['last_download_at'] = (array) ($stats['last_download_at'] ?? []);
        unset($stats['downloads'][$id], $stats['last_download_at'][$id]);
        if (!$stats['last_download_at']) {
            unset($stats['last_download_at']);
        }
        $this->rewriteLockedJson($handle, $stats);
        flock($handle, LOCK_UN);
        fclose($handle);
    }

    /** @param array<string,mixed> $values
     *  @return array<string,mixed>
     */
    public function createCategory(array $values): array
    {
        $catalog = $this->catalogData();
        $name = trim((string) ($values['name'] ?? ''));
        if ($name === '') {
            throw new \RuntimeException('Category name is required.');
        }

        foreach ($catalog['categories'] as $category) {
            if (strcasecmp((string) $category['name'], $name) === 0) {
                throw new \RuntimeException('A category with that name already exists.');
            }
        }

        $category = $this->normalizeCategory([
            'id' => $this->uniqueCategoryId($name, $catalog['categories']),
            'name' => $name,
            'description' => (string) ($values['description'] ?? ''),
            'sort_order' => (int) ($values['sort_order'] ?? (count($catalog['categories']) + 1)),
        ], count($catalog['categories']) + 1);
        $catalog['categories'][] = $category;
        $this->sortCategories($catalog['categories']);
        $this->writeCatalog($catalog['items'], $catalog['categories']);

        return ['message' => $name . ' category created.', 'category' => $category];
    }

    /** @param array<string,mixed> $values
     *  @return array<string,mixed>
     */
    public function saveCategory(string $id, array $values): array
    {
        $catalog = $this->catalogData();
        $found = false;
        $oldName = '';
        $newName = trim((string) ($values['name'] ?? ''));
        if ($newName === '') {
            throw new \RuntimeException('Category name is required.');
        }

        foreach ($catalog['categories'] as $category) {
            if ((string) $category['id'] !== $id && strcasecmp((string) $category['name'], $newName) === 0) {
                throw new \RuntimeException('A category with that name already exists.');
            }
        }

        foreach ($catalog['categories'] as &$category) {
            if ((string) $category['id'] !== $id) {
                continue;
            }
            $oldName = (string) $category['name'];
            $category['name'] = $newName;
            $category['description'] = (string) ($values['description'] ?? $category['description']);
            $category['sort_order'] = (int) ($values['sort_order'] ?? $category['sort_order']);
            $category = $this->normalizeCategory($category, (int) $category['sort_order']);
            $found = true;
            break;
        }
        unset($category);

        if (!$found) {
            throw new \RuntimeException('Category not found.');
        }

        if (strcasecmp($oldName, $newName) !== 0) {
            foreach ($catalog['items'] as &$item) {
                if (strcasecmp((string) $item['category'], $oldName) === 0) {
                    $item['category'] = $newName;
                }
            }
            unset($item);
        }

        $this->sortCategories($catalog['categories']);
        $this->writeCatalog($catalog['items'], $catalog['categories']);
        return ['message' => $newName . ' category saved.', 'category' => $this->findCategoryById($id, $catalog['categories'])];
    }

    /** @return array<string,mixed> */
    public function deleteCategory(string $id, string $moveTo = ''): array
    {
        $catalog = $this->catalogData();
        $removed = $this->findCategoryById($id, $catalog['categories']);
        $moveTo = trim($moveTo);

        if ($moveTo !== '') {
            $targetFound = false;
            foreach ($catalog['categories'] as $category) {
                if ((string) $category['id'] !== $id && ((string) $category['id'] === $moveTo || strcasecmp((string) $category['name'], $moveTo) === 0)) {
                    $moveTo = (string) $category['name'];
                    $targetFound = true;
                    break;
                }
            }
            if (!$targetFound) {
                throw new \RuntimeException('The destination category does not exist.');
            }
        }

        $catalog['categories'] = array_values(array_filter(
            $catalog['categories'],
            static fn(array $category): bool => (string) $category['id'] !== $id
        ));

        $moved = 0;
        foreach ($catalog['items'] as &$item) {
            if (strcasecmp((string) $item['category'], (string) $removed['name']) === 0) {
                $item['category'] = $moveTo;
                ++$moved;
            }
        }
        unset($item);

        $this->writeCatalog($catalog['items'], $catalog['categories']);
        return [
            'message' => sprintf('%s category deleted; %d file%s %s.', (string) $removed['name'], $moved, $moved === 1 ? '' : 's', $moveTo !== '' ? 'moved to ' . $moveTo : 'left uncategorized'),
        ];
    }

    /** @return array<string,mixed> */
    public function resetCount(string $id): array
    {
        $this->findById($id, $this->catalogItems());
        $path = $this->statsPath();
        $this->ensureDirectory(dirname($path));
        $handle = fopen($path, 'c+');
        if ($handle === false || !flock($handle, LOCK_EX)) {
            throw new \RuntimeException('Unable to lock download statistics.');
        }
        $raw = stream_get_contents($handle);
        $stats = is_string($raw) && $raw !== '' ? json_decode($raw, true) : [];
        $stats = is_array($stats) ? $stats : [];
        $stats['downloads'] = (array) ($stats['downloads'] ?? []);
        $stats['downloads'][$id] = 0;
        $this->rewriteLockedJson($handle, $stats);
        flock($handle, LOCK_UN);
        fclose($handle);
        return ['message' => 'Download count reset.'];
    }

    public function recordDownload(string $id): void
    {
        $this->claimDownload($id);
    }

    public function claimDownload(string $id): int
    {
        $item = $this->findById($id, $this->catalogItems());
        $limit = max(0, (int) ($item['download_limit'] ?? 0));
        $path = $this->statsPath();
        $this->ensureDirectory(dirname($path));
        $handle = fopen($path, 'c+');
        if ($handle === false || !flock($handle, LOCK_EX)) {
            throw new \RuntimeException('Unable to lock download statistics.');
        }
        $raw = stream_get_contents($handle);
        $stats = is_string($raw) && $raw !== '' ? json_decode($raw, true) : [];
        $stats = is_array($stats) ? $stats : [];
        $stats['downloads'] = (array) ($stats['downloads'] ?? []);
        $current = (int) ($stats['downloads'][$id] ?? 0);
        if ($limit > 0 && $current >= $limit) {
            flock($handle, LOCK_UN);
            fclose($handle);
            throw new \RuntimeException('This download has reached its configured limit.');
        }
        $stats['downloads'][$id] = $current + 1;
        $stats['last_download_at'][$id] = gmdate('c');
        $this->rewriteLockedJson($handle, $stats);
        flock($handle, LOCK_UN);
        fclose($handle);
        return $current + 1;
    }

    /** @return array<string,mixed> */
    public function resolveDownloadToken(string $token): array
    {
        $parts = explode('.', $token, 2);
        if (count($parts) !== 2) {
            throw new \RuntimeException('Malformed token.');
        }

        [$encoded, $signature] = $parts;
        $expected = $this->base64UrlEncode(hash_hmac('sha256', $encoded, $this->signingSecret(), true));
        if (!hash_equals($expected, $signature)) {
            throw new \RuntimeException('Invalid token signature.');
        }

        $payload = json_decode($this->base64UrlDecode($encoded), true);
        if (!is_array($payload) || (int) ($payload['exp'] ?? 0) < time()) {
            throw new \RuntimeException('Expired token.');
        }

        $item = $this->findById((string) ($payload['id'] ?? ''), $this->catalogItems());
        if (!(bool) ($item['enabled'] ?? true) || !$this->canAccess((string) ($item['access'] ?? ''))) {
            throw new \RuntimeException('Download not authorized.');
        }

        $stats = $this->readJson($this->statsPath(), ['downloads' => []]);
        $count = (int) (($stats['downloads'] ?? [])[(string) $item['id']] ?? 0);
        $limit = max(0, (int) ($item['download_limit'] ?? 0));
        if ($limit > 0 && $count >= $limit) {
            throw new \RuntimeException('This download has reached its configured limit.');
        }

        $sourceType = (string) ($item['source_type'] ?? 'file');
        $result = [
            'id' => (string) $item['id'],
            'source_type' => $sourceType,
            'download_name' => (string) ($item['download_name'] ?: $item['filename']),
            'password_hash' => (string) ($item['password_hash'] ?? ''),
        ];
        if ($sourceType === 'url') {
            $result['external_url'] = $this->validateExternalUrl((string) ($item['external_url'] ?? ''));
            return $result;
        }

        $absolute = $this->filePath((string) $item['filename']);
        if (!is_file($absolute) || !is_readable($absolute)) {
            throw new \RuntimeException('Stored file not found.');
        }

        $result['absolute'] = $absolute;
        $result['mime'] = $this->mimeType($absolute);
        return $result;
    }

    /** @param array<string,mixed> $item
     *  @param array<string,mixed> $downloads
     *  @return array<string,mixed>
     */
    private function enrichItem(array $item, array $downloads, bool $withUrl): array
    {
        $sourceType = (string) ($item['source_type'] ?? 'file');
        $path = $sourceType === 'file' ? $this->filePath((string) $item['filename']) : '';
        $bytes = $sourceType === 'file' && is_file($path) ? (int) filesize($path) : 0;
        $modified = $sourceType === 'file' && is_file($path) ? (int) filemtime($path) : 0;
        $access = (string) ($item['access'] ?? '');
        $locked = !$this->canAccess($access);
        $published = strtotime((string) ($item['published_at'] ?? '')) ?: $modified;
        $newDays = max(0, (int) ($this->config['new_days'] ?? 30));

        $item['bytes'] = $bytes;
        $item['size'] = $this->formatBytes($bytes);
        $item['modified_at'] = $modified > 0 ? date('Y-m-d', $modified) : '';
        $item['download_count'] = (int) ($downloads[(string) $item['id']] ?? 0);
        $item['download_limit'] = max(0, (int) ($item['download_limit'] ?? 0));
        $item['downloads_remaining'] = $item['download_limit'] > 0 ? max(0, $item['download_limit'] - $item['download_count']) : null;
        $item['exhausted'] = $item['download_limit'] > 0 && $item['download_count'] >= $item['download_limit'];
        $item['locked'] = $locked;
        $item['password_protected'] = (string) ($item['password_hash'] ?? '') !== '';
        $item['missing'] = $sourceType === 'file' ? !is_file($path) : (string) ($item['external_url'] ?? '') === '';
        $item['is_new'] = $newDays > 0 && $published > 0 && $published >= strtotime('-' . $newDays . ' days');
        $item['is_hot'] = (int) $item['download_count'] >= (int) ($this->config['hot_downloads'] ?? 25);
        $item['download_url'] = ($withUrl && !$locked && !$item['missing'] && !$item['exhausted']) ? $this->downloadUrl((string) $item['id']) : null;
        unset($item['password_hash']);
        if ($withUrl) {
            unset($item['external_url']);
        }

        return $item;
    }

    private function downloadUrl(string $id): string
    {
        $ttl = max(300, (int) ($this->config['link_ttl'] ?? 43200));
        $payload = $this->base64UrlEncode((string) json_encode(['id' => $id, 'exp' => time() + $ttl], JSON_UNESCAPED_SLASHES));
        $signature = $this->base64UrlEncode(hash_hmac('sha256', $payload, $this->signingSecret(), true));
        // Keep signed links on the site that rendered them. An absolute root
        // can point at the production canonical host while Grav is running in
        // DDEV or staging, which makes otherwise valid local tokens unusable.
        $base = rtrim((string) $this->grav['uri']->rootUrl(false), '/');
        return $base . '/file-vault/download?token=' . rawurlencode($payload . '.' . $signature);
    }

    private function canAccess(string $permission): bool
    {
        if ($permission === '') {
            return true;
        }
        $user = $this->grav['user'] ?? null;
        return $user && method_exists($user, 'authorize') && (bool) $user->authorize($permission);
    }

    /** @return list<array<string,mixed>> */
    private function catalogItems(): array
    {
        return $this->catalogData()['items'];
    }

    /** @return array<string,mixed> */
    private function publicSettings(): array
    {
        $configured = (array) ($this->config['public'] ?? []);
        return [
            'hero_kicker' => trim((string) ($configured['hero_kicker'] ?? 'FILE VAULT')),
            'hero_title' => trim((string) ($configured['hero_title'] ?? 'Downloads')),
            'hero_intro' => trim((string) ($configured['hero_intro'] ?? 'A curated collection of protected downloads. Search the library or browse by category, then select a file to download.')),
            'show_intro' => (bool) ($configured['show_intro'] ?? true),
            'show_file_count' => (bool) ($configured['show_file_count'] ?? true),
            'show_download_count' => (bool) ($configured['show_download_count'] ?? true),
            'default_view' => in_array(($configured['default_view'] ?? 'list'), ['list', 'grid'], true) ? (string) $configured['default_view'] : 'list',
            'default_category' => strtolower(trim((string) ($configured['default_category'] ?? ''))),
            'search_placeholder' => trim((string) ($configured['search_placeholder'] ?? 'Search files, descriptions, and tags…')),
        ];
    }

    /** @return array<string,mixed> */
    private function vaultSettings(): array
    {
        return [
            'allowed_extensions' => array_values(array_unique(array_map(
                static fn($value): string => strtolower(ltrim(trim((string) $value), '.')),
                (array) ($this->config['allowed_extensions'] ?? ['zip'])
            ))),
            'max_upload_size' => (int) ($this->config['max_upload_size'] ?? 104857600),
            'activity' => $this->activitySettings(),
        ];
    }

    /** @return array{enabled:bool,ip_mode:string,retention_days:int,store_user_agent:bool,trust_proxy_headers:bool} */
    private function activitySettings(): array
    {
        $configured = (array) ($this->config['activity'] ?? []);
        $mode = strtolower(trim((string) ($configured['ip_mode'] ?? 'hash')));
        if (!in_array($mode, ['none', 'hash', 'full'], true)) {
            $mode = 'hash';
        }
        return [
            'enabled' => (bool) ($configured['enabled'] ?? false),
            'ip_mode' => $mode,
            'retention_days' => max(1, min(3650, (int) ($configured['retention_days'] ?? 30))),
            'store_user_agent' => (bool) ($configured['store_user_agent'] ?? false),
            'trust_proxy_headers' => (bool) ($configured['trust_proxy_headers'] ?? false),
        ];
    }

    private function currentUsername(): string
    {
        $user = $this->grav['user'] ?? null;
        if (!$user) {
            return '';
        }
        if (method_exists($user, 'get')) {
            return mb_substr(trim((string) ($user->get('username') ?? '')), 0, 120);
        }
        return mb_substr(trim((string) ($user->username ?? '')), 0, 120);
    }

    private function activityIp(string $mode, bool $trustProxyHeaders): string
    {
        if ($mode === 'none') {
            return '';
        }
        $candidate = trim((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
        if ($trustProxyHeaders) {
            $forwarded = trim((string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ''));
            if ($forwarded !== '') {
                $candidate = trim(explode(',', $forwarded)[0]);
            }
        }
        if (filter_var($candidate, FILTER_VALIDATE_IP) === false) {
            return '';
        }
        if ($mode === 'full') {
            return $candidate;
        }
        return substr(hash_hmac('sha256', $candidate, $this->signingSecret()), 0, 24);
    }

    /** @return array{items:list<array<string,mixed>>,categories:list<array<string,mixed>>} */
    private function catalogData(): array
    {
        $catalog = $this->readJson($this->catalogPath(), ['items' => [], 'categories' => []]);
        $rawItems = $catalog['items'] ?? [];
        if (!is_array($rawItems)) {
            $rawItems = [];
        }
        $items = [];
        foreach ($rawItems as $raw) {
            if (is_array($raw)) {
                $items[] = $this->normalizeItem($raw);
            }
        }

        $rawCategories = $catalog['categories'] ?? [];
        $categories = [];
        if (is_array($rawCategories)) {
            foreach ($rawCategories as $index => $raw) {
                if (is_array($raw)) {
                    $categories[] = $this->normalizeCategory($raw, $index + 1);
                } elseif (is_string($raw) && trim($raw) !== '') {
                    $categories[] = $this->normalizeCategory(['name' => $raw], $index + 1);
                }
            }
        }

        if (!$categories) {
            $names = [];
            foreach ($items as $item) {
                $name = trim((string) $item['category']);
                if ($name !== '') {
                    $names[strtolower($name)] = $name;
                }
            }
            foreach (array_values($names) as $index => $name) {
                $categories[] = $this->normalizeCategory(['name' => $name], $index + 1);
            }
        }

        $this->sortCategories($categories);
        return ['items' => $items, 'categories' => $categories];
    }

    /** @param list<array<string,mixed>> $items
     *  @param null|list<array<string,mixed>> $categories
     */
    private function writeCatalog(array $items, ?array $categories = null): void
    {
        if ($categories === null) {
            $categories = $this->catalogData()['categories'];
        }
        $this->sortCategories($categories);
        $this->writeJson($this->catalogPath(), [
            'schema' => 5,
            'categories' => array_values($categories),
            'items' => array_values($items),
        ]);
    }

    /** @param array<string,mixed> $category
     *  @return array<string,mixed>
     */
    private function normalizeCategory(array $category, int $fallbackOrder): array
    {
        $name = trim((string) ($category['name'] ?? ''));
        if ($name === '') {
            throw new \RuntimeException('Every category requires a name.');
        }
        $id = trim((string) ($category['id'] ?? ''));
        $id = strtolower(trim((string) preg_replace('/[^a-z0-9]+/i', '-', $id !== '' ? $id : $name), '-'));
        if ($id === '') {
            throw new \RuntimeException('Every category requires a valid id.');
        }

        return [
            'id' => $id,
            'name' => $name,
            'label' => trim((string) ($category['label'] ?? $name)) ?: $name,
            'description' => trim((string) ($category['description'] ?? '')),
            'sort_order' => max(0, (int) ($category['sort_order'] ?? $fallbackOrder)),
            'section' => trim((string) ($category['section'] ?? '')),
            'section_id' => trim((string) ($category['section_id'] ?? '')),
            'group' => trim((string) ($category['group'] ?? '')),
            'group_id' => trim((string) ($category['group_id'] ?? '')),
            'group_order' => max(0, (int) ($category['group_order'] ?? 0)),
        ];
    }

    /** @param list<array<string,mixed>> $categories */
    private function sortCategories(array &$categories): void
    {
        usort($categories, static function (array $a, array $b): int {
            $order = ((int) ($a['sort_order'] ?? 0)) <=> ((int) ($b['sort_order'] ?? 0));
            return $order !== 0 ? $order : strcasecmp((string) $a['name'], (string) $b['name']);
        });
    }

    /** @param list<array<string,mixed>> $categories */
    private function uniqueCategoryId(string $name, array $categories): string
    {
        $base = strtolower(trim((string) preg_replace('/[^a-z0-9]+/i', '-', $name), '-')) ?: 'category';
        $ids = array_column($categories, 'id');
        $candidate = $base;
        $counter = 2;
        while (in_array($candidate, $ids, true)) {
            $candidate = $base . '-' . $counter;
            ++$counter;
        }
        return $candidate;
    }

    /** @param list<array<string,mixed>> $categories
     *  @return array<string,mixed>
     */
    private function findCategoryById(string $id, array $categories): array
    {
        foreach ($categories as $category) {
            if ((string) $category['id'] === $id) {
                return $category;
            }
        }
        throw new \RuntimeException('Category not found.');
    }

    /** @param array<string,mixed> $item
     *  @return array<string,mixed>
     */
    private function normalizeItem(array $item): array
    {
        $id = strtolower((string) ($item['id'] ?? ''));
        $id = trim((string) preg_replace('/[^a-z0-9-]+/', '-', $id), '-');
        $sourceType = strtolower(trim((string) ($item['source_type'] ?? 'file')));
        $sourceType = $sourceType === 'url' ? 'url' : 'file';
        $filename = basename((string) ($item['filename'] ?? ''));
        if ($id === '' || $filename === '') {
            throw new \RuntimeException('Every catalog item requires a valid id and filename.');
        }
        $externalUrl = $sourceType === 'url' ? $this->validateExternalUrl((string) ($item['external_url'] ?? '')) : '';

        $tags = $item['tags'] ?? [];
        if (is_string($tags)) {
            $tags = preg_split('/\s*,\s*/', $tags, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        }

        return [
            'id' => $id,
            'source_type' => $sourceType,
            'filename' => $filename,
            'external_url' => $externalUrl,
            'display_name' => trim((string) ($item['display_name'] ?? $filename)) ?: $filename,
            'download_name' => basename((string) ($item['download_name'] ?? $filename)) ?: $filename,
            'version' => trim((string) ($item['version'] ?? '')),
            'description' => trim((string) ($item['description'] ?? '')),
            'category' => trim((string) ($item['category'] ?? 'Other')),
            'tags' => array_values(array_unique(array_filter(array_map('trim', (array) $tags)))),
            'published_at' => trim((string) ($item['published_at'] ?? '')),
            'featured' => (bool) ($item['featured'] ?? false),
            'enabled' => (bool) ($item['enabled'] ?? true),
            'listed' => (bool) ($item['listed'] ?? true),
            'access' => trim((string) ($item['access'] ?? '')),
            'password_hash' => trim((string) ($item['password_hash'] ?? '')),
            'download_limit' => max(0, (int) ($item['download_limit'] ?? 0)),
            'sort_order' => max(0, (int) ($item['sort_order'] ?? 0)),
            'checksum_sha256' => strtolower(trim((string) ($item['checksum_sha256'] ?? ''))),
            'original_filename' => basename((string) ($item['original_filename'] ?? $item['download_name'] ?? $filename)),
            'date_basis' => trim((string) ($item['date_basis'] ?? '')),
            'author' => trim((string) ($item['author'] ?? '')),
            'publisher' => trim((string) ($item['publisher'] ?? '')),
            'provenance' => trim((string) ($item['provenance'] ?? '')),
            'provenance_confidence' => trim((string) ($item['provenance_confidence'] ?? '')),
            'provenance_note' => trim((string) ($item['provenance_note'] ?? '')),
            'license' => trim((string) ($item['license'] ?? '')),
            'requirements' => trim((string) ($item['requirements'] ?? '')),
            'compatibility' => trim((string) ($item['compatibility'] ?? '')),
            'work_files' => array_values(array_unique(array_filter(array_map('trim', (array) ($item['work_files'] ?? []))))),
            'rights_review_required' => (bool) ($item['rights_review_required'] ?? false),
        ];
    }

    private function validateExternalUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '' || filter_var($url, FILTER_VALIDATE_URL) === false) {
            throw new \RuntimeException('A valid destination URL is required.');
        }
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        if (!in_array($scheme, ['http', 'https'], true)) {
            throw new \RuntimeException('Only http and https destination URLs are allowed.');
        }
        if (preg_match('/[\r\n]/', $url)) {
            throw new \RuntimeException('The destination URL is invalid.');
        }
        return $url;
    }

    /** @param list<array<string,mixed>> $items
     *  @return array<string,mixed>
     */
    private function findById(string $id, array $items): array
    {
        foreach ($items as $item) {
            if ((string) $item['id'] === $id) {
                return $item;
            }
        }
        throw new \RuntimeException('Catalog item not found.');
    }

    /** @param list<array<string,mixed>> $items */
    private function uniqueId(string $seed, array $items): string
    {
        $base = strtolower(trim((string) preg_replace('/[^a-z0-9]+/i', '-', $seed), '-')) ?: 'file';
        $ids = array_column($items, 'id');
        $candidate = $base;
        $counter = 2;
        while (in_array($candidate, $ids, true)) {
            $candidate = $base . '-' . $counter;
            ++$counter;
        }
        return $candidate;
    }

    private function humanize(string $value): string
    {
        return ucwords(trim((string) preg_replace('/[-_]+/', ' ', $value)));
    }

    private function storagePath(): string
    {
        return $this->resolvePath((string) ($this->config['storage_path'] ?? '../file-vault-files'));
    }

    private function catalogPath(): string
    {
        return $this->resolvePath((string) ($this->config['catalog_path'] ?? 'user/data/file-vault/catalog.json'));
    }

    private function statsPath(): string
    {
        return $this->resolvePath((string) ($this->config['stats_path'] ?? 'user/data/file-vault/stats.json'));
    }

    private function activityPath(): string
    {
        return $this->resolvePath((string) ($this->config['activity_path'] ?? 'user/data/file-vault/activity.json'));
    }

    private function filePath(string $filename): string
    {
        if ($filename !== basename($filename) || $filename === '') {
            throw new \RuntimeException('Invalid stored filename.');
        }
        return $this->storagePath() . '/' . $filename;
    }

    private function resolvePath(string $path): string
    {
        if ($path === '') {
            throw new \RuntimeException('File Vault path cannot be empty.');
        }
        if (str_starts_with($path, '/')) {
            return rtrim($path, '/');
        }
        return rtrim(GRAV_ROOT . '/' . $path, '/');
    }

    private function signingSecret(): string
    {
        $path = $this->resolvePath('user/data/file-vault/signing.key');
        if (is_file($path)) {
            $secret = trim((string) file_get_contents($path));
            if ($secret !== '') {
                return $secret;
            }
        }
        $this->ensureDirectory(dirname($path));
        $secret = bin2hex(random_bytes(32));
        if (file_put_contents($path, $secret . "\n", LOCK_EX) === false) {
            throw new \RuntimeException('Unable to persist the File Vault signing key.');
        }
        @chmod($path, 0600);
        return $secret;
    }

    /** @param array<string,mixed> $default
     *  @return array<string,mixed>
     */
    private function readJson(string $path, array $default): array
    {
        if (!is_file($path)) {
            return $default;
        }
        $decoded = json_decode((string) file_get_contents($path), true);
        return is_array($decoded) ? $decoded : $default;
    }

    /** @param array<string,mixed> $data */
    private function writeJson(string $path, array $data): void
    {
        $this->ensureDirectory(dirname($path));
        $temporary = tempnam(dirname($path), '.file-vault-');
        if ($temporary === false) {
            throw new \RuntimeException('Unable to create a temporary catalog file.');
        }
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
        if (file_put_contents($temporary, $json, LOCK_EX) === false || !rename($temporary, $path)) {
            @unlink($temporary);
            throw new \RuntimeException('Unable to save File Vault data.');
        }
        @chmod($path, 0640);
    }

    /** @param resource $handle
     *  @param array<string,mixed> $data
     */
    private function rewriteLockedJson($handle, array $data): void
    {
        rewind($handle);
        ftruncate($handle, 0);
        fwrite($handle, (string) json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
        fflush($handle);
    }

    private function ensureDirectory(string $path): void
    {
        if (!is_dir($path) && !mkdir($path, 0750, true) && !is_dir($path)) {
            throw new \RuntimeException('Unable to create directory: ' . $path);
        }
    }

    private function mimeType(string $path): string
    {
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        return $finfo->file($path) ?: 'application/octet-stream';
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes . ' B';
        }
        $units = ['KB', 'MB', 'GB', 'TB'];
        $value = $bytes / 1024;
        foreach ($units as $unit) {
            if ($value < 1024 || $unit === 'TB') {
                return number_format($value, $value >= 10 ? 1 : 2) . ' ' . $unit;
            }
            $value /= 1024;
        }
        return $bytes . ' B';
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function base64UrlDecode(string $value): string
    {
        $decoded = base64_decode(strtr($value, '-_', '+/'), true);
        if ($decoded === false) {
            throw new \RuntimeException('Invalid token encoding.');
        }
        return $decoded;
    }
}
