<?php
declare(strict_types=1);

// ── PHP 7.4 polyfills ──────────────────────────────────────────────────────
if (!function_exists('str_contains')) {
    function str_contains(string $h, string $n): bool { return $n === '' || strpos($h, $n) !== false; }
}
if (!function_exists('str_starts_with')) {
    function str_starts_with(string $h, string $n): bool { return $n === '' || strncmp($h, $n, strlen($n)) === 0; }
}

/**
 * GoogleDriveBackup — aaPanel-style Google Drive backup for DishNet Hybrid
 *
 * Architecture:
 *   1. Admin creates Google Cloud project, enables Drive API, creates OAuth2 Desktop credentials
 *   2. Admin enters Client ID + Secret in plugin settings
 *   3. Admin clicks "Authorize" → opens Google consent URL in new tab
 *   4. After consent, Google redirects to localhost with ?code=XXX
 *   5. Admin copies full URL back into plugin
 *   6. Plugin exchanges code for access_token + refresh_token, stores persistently
 *   7. Cron job uses refresh_token to get fresh access_token, then uploads backups
 *
 * All API calls use curl — no Composer, no Google PHP SDK required.
 * PHP 7.4 compatible.
 */
class GoogleDriveBackup
{
    private string $dataDir;
    private string $configFile;
    private array  $config;
    private string $logFile;

    // Google OAuth2 endpoints
    private const AUTH_URL  = 'https://accounts.google.com/o/oauth2/v2/auth';
    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';
    private const DRIVE_API = 'https://www.googleapis.com/drive/v3';
    private const UPLOAD_API = 'https://www.googleapis.com/upload/drive/v3';

    // Scopes — only file create/delete in Drive
    private const SCOPES = 'https://www.googleapis.com/auth/drive.file';

    // Redirect URI for Desktop OAuth (aaPanel-style: user copies localhost URL)
    private const REDIRECT_URI = 'http://localhost';

    public function __construct(string $dataDir)
    {
        $this->dataDir    = $dataDir;
        $this->configFile = $dataDir . '/gdrive_config.json';
        $this->logFile    = $dataDir . '/gdrive_backup.log';
        $this->config     = $this->loadConfig();
    }

    // ── Configuration ────────────────────────────────────────────────────────

    private function loadConfig(): array
    {
        if (!file_exists($this->configFile)) return [];
        $d = json_decode(file_get_contents($this->configFile), true);
        return is_array($d) ? $d : [];
    }

    public function saveConfig(array $config): void
    {
        $this->config = $config;
        $json = json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        $tmp  = $this->configFile . '.tmp.' . getmypid();
        if (file_put_contents($tmp, $json, LOCK_EX) !== false) {
            rename($tmp, $this->configFile);
        } else {
            @unlink($tmp);
        }
    }

    public function getConfig(): array
    {
        return $this->config;
    }

    public function isConfigured(): bool
    {
        return !empty($this->config['client_id'])
            && !empty($this->config['client_secret']);
    }

    public function isAuthorized(): bool
    {
        return $this->isConfigured()
            && !empty($this->config['refresh_token']);
    }

    // ── OAuth2 Flow ──────────────────────────────────────────────────────────

    /**
     * Step 1: Generate the Google OAuth consent URL
     */
    public function getAuthUrl(): string
    {
        $params = [
            'client_id'     => $this->config['client_id'] ?? '',
            'redirect_uri'  => self::REDIRECT_URI,
            'response_type' => 'code',
            'scope'         => self::SCOPES,
            'access_type'   => 'offline',    // gives us refresh_token
            'prompt'        => 'consent',    // always show consent to get refresh_token
        ];
        return self::AUTH_URL . '?' . http_build_query($params);
    }

    /**
     * Step 2: Exchange auth code (from redirect URL) for tokens
     * @param string $redirectUrl The full localhost URL user copied (contains ?code=XXX)
     * @return array ['ok'=>bool, 'error'=>string|null]
     */
    public function exchangeCode(string $redirectUrl): array
    {
        // Extract code from URL
        $parsed = parse_url(trim($redirectUrl));
        $query  = [];
        parse_str($parsed['query'] ?? '', $query);
        $code = $query['code'] ?? '';

        if (empty($code)) {
            return ['ok' => false, 'error' => 'No authorization code found in the URL. Make sure you copied the full URL from the browser.'];
        }

        $postFields = [
            'code'          => $code,
            'client_id'     => $this->config['client_id'],
            'client_secret' => $this->config['client_secret'],
            'redirect_uri'  => self::REDIRECT_URI,
            'grant_type'    => 'authorization_code',
        ];

        $resp = $this->curlPost(self::TOKEN_URL, http_build_query($postFields), [
            'Content-Type: application/x-www-form-urlencoded',
        ]);

        if (!$resp['ok']) {
            return ['ok' => false, 'error' => 'Token exchange failed: ' . ($resp['error'] ?? 'unknown')];
        }

        $data = json_decode($resp['body'], true);
        if (empty($data['access_token'])) {
            $err = $data['error_description'] ?? $data['error'] ?? 'No access_token returned';
            return ['ok' => false, 'error' => "Google returned: {$err}"];
        }

        // Store tokens
        $this->config['access_token']  = $data['access_token'];
        $this->config['token_expiry']  = time() + (int)($data['expires_in'] ?? 3600);
        if (!empty($data['refresh_token'])) {
            $this->config['refresh_token'] = $data['refresh_token'];
        }
        $this->config['authorized_at'] = date('Y-m-d H:i:s');
        $this->saveConfig($this->config);

        return ['ok' => true];
    }

    /**
     * Refresh the access_token using refresh_token
     */
    public function refreshAccessToken(): array
    {
        if (empty($this->config['refresh_token'])) {
            return ['ok' => false, 'error' => 'No refresh token — re-authorize'];
        }

        $postFields = [
            'client_id'     => $this->config['client_id'],
            'client_secret' => $this->config['client_secret'],
            'refresh_token' => $this->config['refresh_token'],
            'grant_type'    => 'refresh_token',
        ];

        $resp = $this->curlPost(self::TOKEN_URL, http_build_query($postFields), [
            'Content-Type: application/x-www-form-urlencoded',
        ]);

        if (!$resp['ok']) {
            return ['ok' => false, 'error' => 'Refresh failed: ' . ($resp['error'] ?? 'unknown')];
        }

        $data = json_decode($resp['body'], true);
        if (empty($data['access_token'])) {
            $err = $data['error_description'] ?? $data['error'] ?? 'No access_token';
            return ['ok' => false, 'error' => "Refresh failed: {$err}"];
        }

        $this->config['access_token'] = $data['access_token'];
        $this->config['token_expiry'] = time() + (int)($data['expires_in'] ?? 3600);
        $this->saveConfig($this->config);

        return ['ok' => true, 'access_token' => $data['access_token']];
    }

    /**
     * Get a valid access_token (auto-refresh if expired)
     */
    public function getAccessToken(): ?string
    {
        if (!$this->isAuthorized()) return null;

        $expiry = (int)($this->config['token_expiry'] ?? 0);
        if (!empty($this->config['access_token']) && time() < ($expiry - 120)) {
            return $this->config['access_token'];
        }

        // Refresh
        $result = $this->refreshAccessToken();
        if ($result['ok']) {
            return $this->config['access_token'];
        }

        $this->log('ERROR: Could not refresh access token — ' . ($result['error'] ?? ''));
        return null;
    }

    /**
     * Disconnect / revoke authorization
     */
    public function disconnect(): void
    {
        unset(
            $this->config['access_token'],
            $this->config['refresh_token'],
            $this->config['token_expiry'],
            $this->config['authorized_at']
        );
        $this->saveConfig($this->config);
    }

    // ── Google Drive Operations ──────────────────────────────────────────────

    /**
     * Ensure the backup folder exists in Drive, return its ID
     */
    public function ensureFolder(): ?string
    {
        $folderName = $this->config['folder_name'] ?? 'DishNet-Backups';

        // Check if we already have a cached folder ID
        if (!empty($this->config['folder_id'])) {
            // Verify it still exists
            $token = $this->getAccessToken();
            if (!$token) return null;

            $url  = self::DRIVE_API . '/files/' . $this->config['folder_id'] . '?fields=id,trashed';
            $resp = $this->curlGet($url, ['Authorization: Bearer ' . $token]);
            if ($resp['ok']) {
                $data = json_decode($resp['body'], true);
                if (!empty($data['id']) && empty($data['trashed'])) {
                    return $this->config['folder_id'];
                }
            }
            // Folder was deleted — create a new one
        }

        $token = $this->getAccessToken();
        if (!$token) return null;

        // Search for existing folder
        $q    = "name='" . addcslashes($folderName, "'\\") . "' and mimeType='application/vnd.google-apps.folder' and trashed=false";
        $url  = self::DRIVE_API . '/files?' . http_build_query(['q' => $q, 'fields' => 'files(id,name)']);
        $resp = $this->curlGet($url, ['Authorization: Bearer ' . $token]);

        if ($resp['ok']) {
            $data = json_decode($resp['body'], true);
            if (!empty($data['files'][0]['id'])) {
                $this->config['folder_id'] = $data['files'][0]['id'];
                $this->saveConfig($this->config);
                return $this->config['folder_id'];
            }
        }

        // Create folder
        $meta = json_encode([
            'name'     => $folderName,
            'mimeType' => 'application/vnd.google-apps.folder',
        ]);

        $resp = $this->curlPost(self::DRIVE_API . '/files', $meta, [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json',
        ]);

        if ($resp['ok']) {
            $data = json_decode($resp['body'], true);
            if (!empty($data['id'])) {
                $this->config['folder_id'] = $data['id'];
                $this->saveConfig($this->config);
                $this->log("Created Drive folder '{$folderName}' (ID: {$data['id']})");
                return $data['id'];
            }
        }

        $this->log('ERROR: Could not create Drive folder');
        return null;
    }

    /**
     * Upload a file to Google Drive
     * Uses resumable upload for reliability with large files
     */
    public function uploadFile(string $localPath, string $driveName, ?string $folderId = null): array
    {
        $token = $this->getAccessToken();
        if (!$token) return ['ok' => false, 'error' => 'No access token'];

        if (!file_exists($localPath)) {
            return ['ok' => false, 'error' => "Local file not found: {$localPath}"];
        }

        $fileSize = filesize($localPath);
        $mimeType = 'application/zip';

        // File metadata
        $meta = ['name' => $driveName];
        if ($folderId) $meta['parents'] = [$folderId];

        // Simple upload for files < 5MB, resumable for larger
        if ($fileSize < 5 * 1024 * 1024) {
            return $this->simpleUpload($localPath, $meta, $mimeType, $token);
        }

        return $this->resumableUpload($localPath, $meta, $mimeType, $token, $fileSize);
    }

    private function simpleUpload(string $path, array $meta, string $mime, string $token): array
    {
        $boundary = 'dishnet_boundary_' . bin2hex(random_bytes(8));
        $content  = file_get_contents($path);

        $body  = "--{$boundary}\r\n";
        $body .= "Content-Type: application/json; charset=UTF-8\r\n\r\n";
        $body .= json_encode($meta) . "\r\n";
        $body .= "--{$boundary}\r\n";
        $body .= "Content-Type: {$mime}\r\n\r\n";
        $body .= $content . "\r\n";
        $body .= "--{$boundary}--";

        $url = self::UPLOAD_API . '/files?uploadType=multipart&fields=id,name,size';
        $resp = $this->curlPost($url, $body, [
            'Authorization: Bearer ' . $token,
            "Content-Type: multipart/related; boundary={$boundary}",
        ]);

        if ($resp['ok']) {
            $data = json_decode($resp['body'], true);
            if (!empty($data['id'])) {
                return ['ok' => true, 'file_id' => $data['id'], 'name' => $data['name'] ?? ''];
            }
        }

        return ['ok' => false, 'error' => 'Upload failed: ' . ($resp['body'] ?? $resp['error'] ?? 'unknown')];
    }

    private function resumableUpload(string $path, array $meta, string $mime, string $token, int $fileSize): array
    {
        // Step 1: Initiate resumable session
        $url = self::UPLOAD_API . '/files?uploadType=resumable&fields=id,name,size';
        $ch  = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($meta),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER         => true,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $token,
                'Content-Type: application/json; charset=UTF-8',
                'X-Upload-Content-Type: ' . $mime,
                'X-Upload-Content-Length: ' . $fileSize,
            ],
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $response   = curl_exec($ch);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $headers    = substr($response, 0, $headerSize);
        curl_close($ch);

        // Extract upload URI from Location header
        if (!preg_match('/location:\s*(\S+)/i', $headers, $m)) {
            return ['ok' => false, 'error' => 'Resumable upload init failed — no Location header'];
        }
        $uploadUri = trim($m[1]);

        // Step 2: Upload file in chunks (1MB chunks with retry)
        // 502/503/504 from Google are transient — retry up to 3 times with backoff
        $chunkSize  = 1024 * 1024; // 1MB chunks (was 256KB — larger = fewer round trips)
        $handle     = fopen($path, 'rb');
        $offset     = 0;
        $lastResp   = '';
        $maxRetries = 3;

        while ($offset < $fileSize) {
            $chunkStart = ftell($handle);
            $chunk      = fread($handle, $chunkSize);
            $chunkLen   = strlen($chunk);
            $rangeEnd   = $offset + $chunkLen - 1;

            $httpCode  = 0;
            $retryCount = 0;

            while ($retryCount <= $maxRetries) {
                $ch = curl_init($uploadUri);
                curl_setopt_array($ch, [
                    CURLOPT_CUSTOMREQUEST  => 'PUT',
                    CURLOPT_POSTFIELDS     => $chunk,
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_HTTPHEADER     => [
                        'Content-Length: ' . $chunkLen,
                        "Content-Range: bytes {$offset}-{$rangeEnd}/{$fileSize}",
                    ],
                    CURLOPT_TIMEOUT        => 180,
                    CURLOPT_SSL_VERIFYPEER => true,
                ]);

                $lastResp = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);

                // 308 = chunk accepted, 200/201 = complete — success
                if ($httpCode < 400) break;

                // 5xx = transient Google error — retry with backoff
                if ($httpCode >= 500 && $retryCount < $maxRetries) {
                    $retryCount++;
                    $this->log("WARN: Chunk at offset {$offset} got HTTP {$httpCode} — retry {$retryCount}/{$maxRetries} in " . (5 * $retryCount) . "s");
                    sleep(5 * $retryCount); // 5s, 10s, 15s
                    // Seek back to re-read chunk cleanly
                    fseek($handle, $chunkStart);
                    $chunk = fread($handle, $chunkSize);
                    continue;
                }

                // 4xx or retries exhausted — fail
                fclose($handle);
                return ['ok' => false, 'error' => "Chunk upload failed at offset {$offset}: HTTP {$httpCode} after {$retryCount} retries"];
            }

            $offset += $chunkLen;
        }
        fclose($handle);

        $data = json_decode($lastResp, true);
        if (!empty($data['id'])) {
            return ['ok' => true, 'file_id' => $data['id'], 'name' => $data['name'] ?? ''];
        }

        return ['ok' => false, 'error' => 'Resumable upload completed but no file ID returned'];
    }

    /**
     * List files in the backup folder
     */
    public function listBackups(?string $folderId = null): array
    {
        $token = $this->getAccessToken();
        if (!$token) return [];

        $fid = $folderId ?: ($this->config['folder_id'] ?? '');
        if (empty($fid)) return [];

        $q   = "'{$fid}' in parents and trashed=false";
        $url = self::DRIVE_API . '/files?' . http_build_query([
            'q'       => $q,
            'fields'  => 'files(id,name,size,createdTime,modifiedTime)',
            'orderBy' => 'createdTime desc',
            'pageSize' => 50,
        ]);

        $resp = $this->curlGet($url, ['Authorization: Bearer ' . $token]);
        if ($resp['ok']) {
            $data = json_decode($resp['body'], true);
            return $data['files'] ?? [];
        }

        return [];
    }

    /**
     * Delete a file from Drive (for retention cleanup)
     */
    public function deleteFile(string $fileId): bool
    {
        $token = $this->getAccessToken();
        if (!$token) return false;

        $url = self::DRIVE_API . '/files/' . $fileId;
        $ch  = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => 'DELETE',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $token],
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return $code >= 200 && $code < 300;
    }

    /**
     * Get Drive storage usage for display
     */
    public function getStorageInfo(): ?array
    {
        $token = $this->getAccessToken();
        if (!$token) return null;

        $url  = self::DRIVE_API . '/about?fields=storageQuota,user';
        $resp = $this->curlGet($url, ['Authorization: Bearer ' . $token]);
        if ($resp['ok']) {
            return json_decode($resp['body'], true);
        }
        return null;
    }

    // ── Backup Orchestration ─────────────────────────────────────────────────

    /**
     * Run a full backup cycle:
     * 1. Create local zip of data/ directory
     * 2. Upload to Google Drive
     * 3. Apply retention policy (delete old backups)
     * 4. Clean up local temp file
     */
    public function runBackup(): array
    {
        $startTime = microtime(true);
        $this->log('=== Google Drive backup started ===');

        if (!$this->isAuthorized()) {
            $this->log('ERROR: Not authorized. Skipping.');
            return ['ok' => false, 'error' => 'Google Drive not authorized'];
        }

        // 1. Ensure folder exists
        $folderId = $this->ensureFolder();
        if (!$folderId) {
            $this->log('ERROR: Could not create/find Drive folder');
            return ['ok' => false, 'error' => 'Could not ensure Drive folder'];
        }

        // 2. Export UCRM data via API (clients, invoices, payments, services)
        $this->log('Exporting UCRM data via API...');
        $ucrmExportDir = $this->dataDir . '/ucrm_export';
        $ucrmStats = $this->exportUcrmData($ucrmExportDir);
        $this->log("UCRM export: {$ucrmStats['summary']}");

        // 3. Create TWO backup ZIPs:
        //    CODE = uploadable to UCRM plugin installer (no data/)
        //    DATA = for SSH restore (only data/)
        $timestamp  = date('Y-m-d_H-i-s');
        $backupDir  = $this->dataDir . '/backups';
        if (!is_dir($backupDir)) @mkdir($backupDir, 0755, true);

        $codeZipPath = $backupDir . '/gdrive-code-tmp.zip';
        $dataZipPath = $backupDir . '/gdrive-data-tmp.zip';

        // Create CODE ZIP (plugin code only — no data/ directory)
        $codeResult = $this->createCodeZip($codeZipPath);
        if (!$codeResult['ok']) {
            $this->log('ERROR creating code ZIP: ' . $codeResult['error']);
            return $codeResult;
        }

        // Create DATA ZIP (data directory only — SQLite, JSON, uploads)
        $dataResult = $this->createDataZip($dataZipPath);
        if (!$dataResult['ok']) {
            $this->log('ERROR creating data ZIP: ' . $dataResult['error']);
            // Continue — code ZIP is still useful
        }

        $ver = $codeResult['version'] ?? 'unknown';
        $codeZipName = "dishnet-v{$ver}-CODE-{$timestamp}.zip";
        $dataZipName = "dishnet-v{$ver}-DATA-{$timestamp}.zip";

        $codeSizeKB = round(filesize($codeZipPath) / 1024);
        $dataSizeKB = file_exists($dataZipPath) ? round(filesize($dataZipPath) / 1024) : 0;
        $this->log("CODE ZIP: {$codeZipName} ({$codeSizeKB} KB)");
        $this->log("DATA ZIP: {$dataZipName} ({$dataSizeKB} KB)");

        // 4. Upload CODE ZIP to Google Drive
        $codeUpload = $this->uploadFile($codeZipPath, $codeZipName, $folderId);
        @unlink($codeZipPath);

        if (!$codeUpload['ok']) {
            $this->log('ERROR: Code upload failed — ' . ($codeUpload['error'] ?? 'unknown'));
            @unlink($dataZipPath);
            return $codeUpload;
        }
        $this->log("Uploaded CODE: {$codeZipName}");

        // 5. Upload DATA ZIP to Google Drive
        $dataUpload = ['ok' => false];
        if (file_exists($dataZipPath)) {
            $dataUpload = $this->uploadFile($dataZipPath, $dataZipName, $folderId);
            @unlink($dataZipPath);
            if ($dataUpload['ok']) {
                $this->log("Uploaded DATA: {$dataZipName}");
            } else {
                $this->log('WARNING: Data upload failed — ' . ($dataUpload['error'] ?? 'unknown'));
            }
        }

        // 6. Apply retention — keep last N backups (counts both CODE+DATA as one set)
        $retain = max(1, (int)($this->config['retention_count'] ?? 7));
        $this->applyRetention($folderId, $retain * 2); // x2 because each backup = 2 files

        // 7. Update last backup meta
        $duration = round(microtime(true) - $startTime, 1);
        $totalSizeKB = $codeSizeKB + $dataSizeKB;
        $this->config['last_backup'] = [
            'time'     => date('Y-m-d H:i:s'),
            'file'     => $codeZipName,
            'data_file'=> $dataZipName,
            'file_id'  => $codeUpload['file_id'] ?? '',
            'data_file_id' => $dataUpload['file_id'] ?? '',
            'size_kb'  => $totalSizeKB,
            'code_size_kb' => $codeSizeKB,
            'data_size_kb' => $dataSizeKB,
            'duration' => $duration,
            'version'  => $ver,
            'type'     => 'split_backup',
            'ucrm_export' => $ucrmStats,
        ];
        $this->saveConfig($this->config);

        $this->log("=== Backup complete in {$duration}s ===");
        return ['ok' => true, 'file' => $codeZipName, 'data_file' => $dataZipName, 'size_kb' => $totalSizeKB, 'code_size_kb' => $codeSizeKB, 'data_size_kb' => $dataSizeKB, 'duration' => $duration];
    }

    /**
     * Create a FULL DEPLOYABLE plugin ZIP.
     *
     * This ZIP can be uploaded directly to UCRM's "Plugins > Upload"
     * and will restore ALL code + data + config + certificates.
     * Disaster recovery: install fresh UCRM, upload this ZIP, done.
     */
    /**
     * Create CODE-only ZIP — uploadable directly to UCRM plugin installer.
     * Contains all PHP, JS, templates, migrations — NO data/ directory.
     */
    private function createCodeZip(string $zipPath): array
    {
        if (!class_exists('ZipArchive')) {
            return ['ok' => false, 'error' => 'ZipArchive extension not available'];
        }
        $zip = new \ZipArchive();
        if ($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            return ['ok' => false, 'error' => 'Could not create code zip'];
        }
        $pluginRoot = dirname($this->dataDir);
        $this->addDirectoryToZip($zip, $pluginRoot, '', ['data', 'certs']);
        $certsDir = $pluginRoot . '/certs';
        if (is_dir($certsDir)) $this->addDirectoryToZip($zip, $certsDir, 'certs');
        $manifest = $pluginRoot . '/manifest.json';
        $version  = '?';
        if (file_exists($manifest)) {
            $m = json_decode(file_get_contents($manifest), true);
            $version = $m['information']['version'] ?? '?';
        }
        $zip->close();
        return ['ok' => true, 'version' => $version];
    }

    /**
     * Create DATA-only ZIP — for SSH restore to data/ directory.
     * Contains SQLite databases, JSON configs, uploads, UCRM export.
     */
    private function createDataZip(string $zipPath): array
    {
        if (!class_exists('ZipArchive')) {
            return ['ok' => false, 'error' => 'ZipArchive extension not available'];
        }
        $zip = new \ZipArchive();
        if ($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            return ['ok' => false, 'error' => 'Could not create data zip'];
        }
        $backupDir = $this->dataDir . '/backups';
        $tempFiles = [];

        // JSON data files
        foreach (glob($this->dataDir . '/*.json') as $f) {
            $base = basename($f);
            if ($base === 'gdrive_config.json') continue;
            if (strpos($base, 'cron_master.lock') === 0) continue;
            if (strpos($base, '.') === 0) continue;
            $zip->addFile($f, 'data/' . $base);
        }

        // SQLite databases
        $mainDb = $this->dataDir . '/plugin.sqlite3';
        if (file_exists($mainDb)) {
            $copy = $this->safeSqliteCopy($mainDb, $backupDir . '/plugin_backup.sqlite3');
            if ($copy) {
                $zip->addFile($copy, 'data/plugin.sqlite3');
                $tempFiles[] = $copy;
                if (file_exists($copy . '-wal')) { $zip->addFile($copy . '-wal', 'data/plugin.sqlite3-wal'); $tempFiles[] = $copy . '-wal'; }
            }
        }
        $waDb = $this->dataDir . '/dishnet.sqlite';
        if (file_exists($waDb)) {
            $copy = $this->safeSqliteCopy($waDb, $backupDir . '/dishnet_backup.sqlite');
            if ($copy) {
                $zip->addFile($copy, 'data/dishnet.sqlite');
                $tempFiles[] = $copy;
                if (file_exists($copy . '-wal')) { $zip->addFile($copy . '-wal', 'data/dishnet.sqlite-wal'); $tempFiles[] = $copy . '-wal'; }
            }
        }

        // Uploads (receipt photos)
        $uploadsDir = $this->dataDir . '/uploads';
        if (is_dir($uploadsDir)) $this->addDirectoryToZip($zip, $uploadsDir, 'data/uploads');

        // UCRM export
        $ucrmExportDir = $this->dataDir . '/ucrm_export';
        if (is_dir($ucrmExportDir)) $this->addDirectoryToZip($zip, $ucrmExportDir, 'data/ucrm_export');

        // Restore instructions
        $zip->addFromString('RESTORE_INSTRUCTIONS.txt',
            "DishNet Data Restore\n====================\n\n" .
            "Created: " . date('Y-m-d H:i:s') . "\n\n" .
            "Step 1: Upload the CODE zip via UCRM Plugins\n" .
            "Step 2: SCP this DATA zip to server\n" .
            "Step 3: cd /data/ucrm/data/plugins/dishnet-hybrid-telecom/\n" .
            "        unzip -o /tmp/dishnet-DATA-*.zip\n" .
            "        chown -R 33:33 data/\n" .
            "Step 4: Settings -> Update CRM token\n" .
            "Step 5: UCRM Data tab -> Full Sync\n"
        );

        $zip->close();
        foreach ($tempFiles as $tmp) @unlink($tmp);
        return ['ok' => true];
    }
    private function safeSqliteCopy(string $dbPath, string $destPath): ?string
    {
        if (!file_exists($dbPath)) return null;

        try {
            // Checkpoint WAL — PASSIVE mode so we never block live queries
            $pdo = new \PDO('sqlite:' . $dbPath);
            $pdo->exec('PRAGMA wal_checkpoint(PASSIVE)');
            $pdo = null; // Close connection
        } catch (\Throwable $e) {
            $this->log("WAL checkpoint warning for " . basename($dbPath) . ": " . $e->getMessage());
        }

        if (!@copy($dbPath, $destPath)) {
            $this->log("ERROR: Could not copy " . basename($dbPath));
            return null;
        }

        // Also copy WAL file if it exists — PASSIVE may not have flushed everything.
        // SQLite auto-replays WAL on next open, so backup stays consistent.
        $walPath = $dbPath . '-wal';
        if (file_exists($walPath)) {
            @copy($walPath, $destPath . '-wal');
        }

        return $destPath;
    }

    /**
     * Recursively add a directory to ZipArchive.
     *
     * @param \ZipArchive $zip       The archive
     * @param string      $dirPath   Absolute path to directory
     * @param string      $zipPrefix Prefix inside the ZIP (e.g., 'lib')
     * @param array       $exclude   Directory names to skip
     */
    private function addDirectoryToZip(\ZipArchive $zip, string $dirPath, string $zipPrefix = '', array $exclude = []): void
    {
        $items = @scandir($dirPath);
        if (!$items) return;

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') continue;
            if (in_array($item, $exclude, true)) continue;

            // Skip hidden files, temp files, backup zips
            if ($item[0] === '.') continue;
            if (str_contains($item, '.tmp.')) continue;
            if (str_contains($item, '-backup-') && str_contains($item, '.zip')) continue;
            if (str_contains($item, 'auto-backup-') && str_contains($item, '.zip')) continue;
            if ($item === 'backups') continue;  // Skip data/backups/ subdirectory

            $fullPath = $dirPath . '/' . $item;
            $zipPath  = ($zipPrefix !== '' ? $zipPrefix . '/' : '') . $item;

            if (is_dir($fullPath)) {
                $this->addDirectoryToZip($zip, $fullPath, $zipPath);
            } elseif (is_file($fullPath)) {
                // Skip very large files (>50MB) — likely not plugin code
                if (filesize($fullPath) > 50 * 1024 * 1024) {
                    $this->log("Skipped large file: {$zipPath} (" . round(filesize($fullPath)/1048576) . " MB)");
                    continue;
                }
                $zip->addFile($fullPath, $zipPath);
            }
        }
    }

    /**
     * Delete old backups beyond retention limit
     */
    private function applyRetention(string $folderId, int $keep): void
    {
        $files = $this->listBackups($folderId);
        if (count($files) <= $keep) return;

        // Files are already sorted by createdTime desc — delete from end
        $toDelete = array_slice($files, $keep);
        foreach ($toDelete as $f) {
            if ($this->deleteFile($f['id'])) {
                $this->log("Retention: deleted old backup '{$f['name']}'");
            } else {
                $this->log("Retention: FAILED to delete '{$f['name']}'");
            }
        }
    }

    // ── UCRM Data Export ─────────────────────────────────────────────────

    /**
     * Export all critical UCRM data via API for disaster recovery.
     *
     * Pulls clients, invoices, payments, services, and quotes via the
     * UCRM REST API and saves them as JSON files. These files are included
     * in the backup ZIP so that if the UCRM PostgreSQL database is lost,
     * the data can be imported into a fresh UCRM instance.
     *
     * Never fails the backup — if UCRM API is unreachable, logs a warning
     * and continues with the rest of the backup.
     *
     * @param string $exportDir  Directory to save export files
     * @return array Stats about what was exported
     */
    public function exportUcrmData(string $exportDir): array
    {
        $stats = [
            'clients'  => 0,
            'invoices' => 0,
            'payments' => 0,
            'services' => 0,
            'quotes'   => 0,
            'errors'   => [],
            'summary'  => '',
        ];

        if (!is_dir($exportDir)) @mkdir($exportDir, 0755, true);

        $pluginRoot = dirname($this->dataDir);

        // Load kyc_config for potential admin auth token (needed for quotes)
        $kycConfigPath = $this->dataDir . '/kyc_config.json';
        $kycConfig = [];
        if (file_exists($kycConfigPath)) {
            $kycConfig = json_decode(file_get_contents($kycConfigPath), true) ?: [];
        }

        // Build CrmApiClient from ucrm.json (auto-detect)
        $crmFile = $pluginRoot . '/lib/CrmApiClient.php';
        if (!file_exists($crmFile)) {
            $stats['errors'][] = 'CrmApiClient.php not found';
            $stats['summary'] = 'SKIPPED — CrmApiClient not found';
            return $stats;
        }

        require_once $crmFile;
        $crm = \CrmApiClient::fromUcrm($pluginRoot, $kycConfig);

        if (!$crm->getBaseUrl()) {
            $stats['errors'][] = 'UCRM API not configured (no ucrm.json or manual override)';
            $stats['summary'] = 'SKIPPED — UCRM API not configured';
            return $stats;
        }

        // ── Export each dataset (paginated, never fail the backup) ────────

        // 1. Clients (with contacts, addresses, custom attributes)
        $clients = $this->ucrmFetchAll($crm, '/clients');
        if ($clients !== null) {
            $stats['clients'] = count($clients);
            $this->saveExportJson($exportDir . '/clients.json', $clients);
            $this->log("  UCRM export: {$stats['clients']} clients");
        } else {
            $stats['errors'][] = 'Failed to fetch clients: ' . json_encode($crm->getLastError());
        }

        // 2. Invoices
        $invoices = $this->ucrmFetchAll($crm, '/invoices');
        if ($invoices !== null) {
            $stats['invoices'] = count($invoices);
            $this->saveExportJson($exportDir . '/invoices.json', $invoices);
            $this->log("  UCRM export: {$stats['invoices']} invoices");
        } else {
            $stats['errors'][] = 'Failed to fetch invoices: ' . json_encode($crm->getLastError());
        }

        // 3. Payments
        $payments = $this->ucrmFetchAll($crm, '/payments');
        if ($payments !== null) {
            $stats['payments'] = count($payments);
            $this->saveExportJson($exportDir . '/payments.json', $payments);
            $this->log("  UCRM export: {$stats['payments']} payments");
        } else {
            $stats['errors'][] = 'Failed to fetch payments: ' . json_encode($crm->getLastError());
        }

        // 4. Client services
        $services = $this->ucrmFetchAll($crm, '/clients/services');
        if ($services !== null) {
            $stats['services'] = count($services);
            $this->saveExportJson($exportDir . '/services.json', $services);
            $this->log("  UCRM export: {$stats['services']} services");
        } else {
            $stats['errors'][] = 'Failed to fetch services: ' . json_encode($crm->getLastError());
        }

        // 5. Quotes (may need admin token — plugin app key often can't read quotes)
        $adminToken = trim($kycConfig['crm_auth_token'] ?? '');
        $adminUrl   = trim($kycConfig['crm_base_url']   ?? '');
        if ($adminToken && $adminUrl) {
            $adminCrm = new \CrmApiClient($adminUrl, $adminToken, 'x-auth-token');
            $quotes = $this->ucrmFetchAll($adminCrm, '/billing/quotes');
            if ($quotes !== null) {
                $stats['quotes'] = count($quotes);
                $this->saveExportJson($exportDir . '/quotes.json', $quotes);
                $this->log("  UCRM export: {$stats['quotes']} quotes");
            } else {
                $stats['errors'][] = 'Failed to fetch quotes (admin token)';
            }
        }

        // 6. Export timestamp + stats for restore reference
        $this->saveExportJson($exportDir . '/export_meta.json', [
            'exported_at'  => date('Y-m-d H:i:s T'),
            'api_base'     => $crm->getBaseUrl(),
            'counts'       => [
                'clients'  => $stats['clients'],
                'invoices' => $stats['invoices'],
                'payments' => $stats['payments'],
                'services' => $stats['services'],
                'quotes'   => $stats['quotes'],
            ],
            'errors'       => $stats['errors'],
            'note'         => 'Use these JSON files to recreate UCRM data on a fresh server.',
        ]);

        $total = $stats['clients'] + $stats['invoices'] + $stats['payments'] + $stats['services'] + $stats['quotes'];
        $errCount = count($stats['errors']);
        $stats['summary'] = "{$total} records ({$stats['clients']} clients, {$stats['invoices']} inv, {$stats['payments']} pay, {$stats['services']} svc, {$stats['quotes']} quotes)"
            . ($errCount ? " — {$errCount} errors" : '');

        return $stats;
    }

    /**
     * Fetch all records from a UCRM API endpoint with pagination.
     * Returns null on failure, empty array if no records.
     */
    private function ucrmFetchAll(\CrmApiClient $crm, string $endpoint): ?array
    {
        $all    = [];
        $limit  = 500;
        $offset = 0;
        $maxPages = 50; // Safety: max 25,000 records per endpoint

        for ($page = 0; $page < $maxPages; $page++) {
            $sep  = (strpos($endpoint, '?') !== false) ? '&' : '?';
            $path = $endpoint . $sep . "limit={$limit}&offset={$offset}";

            $batch = $crm->get($path);
            if ($batch === null) {
                // First page failed → report error
                if ($page === 0) return null;
                // Later page failed → return what we have
                break;
            }

            if (!is_array($batch) || empty($batch)) break;

            // UCRM sometimes returns an object with 'raw' key instead of array
            if (isset($batch['raw'])) break;

            $all = array_merge($all, $batch);

            // If we got fewer than limit, we've reached the end
            if (count($batch) < $limit) break;

            $offset += $limit;
        }

        return $all;
    }

    /**
     * Atomically save JSON export file.
     */
    private function saveExportJson(string $path, $data): void
    {
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        $tmp  = $path . '.tmp.' . getmypid();
        if (file_put_contents($tmp, $json, LOCK_EX) !== false) {
            rename($tmp, $path);
        } else {
            @unlink($tmp);
        }
    }

    /**
     * Test connection — try listing files
     */
    public function testConnection(): array
    {
        $token = $this->getAccessToken();
        if (!$token) return ['ok' => false, 'error' => 'Cannot get access token'];

        $info = $this->getStorageInfo();
        if ($info && isset($info['user'])) {
            $email = $info['user']['emailAddress'] ?? 'unknown';
            $used  = $this->formatBytes((int)($info['storageQuota']['usage'] ?? 0));
            $total = $this->formatBytes((int)($info['storageQuota']['limit'] ?? 0));
            return [
                'ok'    => true,
                'email' => $email,
                'used'  => $used,
                'total' => $total,
            ];
        }

        return ['ok' => false, 'error' => 'Could not reach Google Drive API'];
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function curlPost(string $url, string $body, array $headers = []): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error    = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            return ['ok' => false, 'error' => $error, 'body' => ''];
        }

        return [
            'ok'        => $httpCode >= 200 && $httpCode < 300,
            'http_code' => $httpCode,
            'body'      => $response,
            'error'     => $httpCode >= 400 ? "HTTP {$httpCode}" : null,
        ];
    }

    private function curlGet(string $url, array $headers = []): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error    = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            return ['ok' => false, 'error' => $error, 'body' => ''];
        }

        return [
            'ok'        => $httpCode >= 200 && $httpCode < 300,
            'http_code' => $httpCode,
            'body'      => $response,
            'error'     => $httpCode >= 400 ? "HTTP {$httpCode}" : null,
        ];
    }

    public function formatBytes(int $bytes): string
    {
        if ($bytes === 0) return '0 B';
        $units = ['B','KB','MB','GB','TB'];
        $i = (int)floor(log($bytes, 1024));
        return round($bytes / pow(1024, $i), 1) . ' ' . $units[$i];
    }

    public function log(string $msg): void
    {
        $line = '[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL;
        file_put_contents($this->logFile, $line, FILE_APPEND);
        // Trim to last 300 lines
        if (file_exists($this->logFile)) {
            $lines = file($this->logFile);
            if ($lines && count($lines) > 300) {
                file_put_contents($this->logFile, implode('', array_slice($lines, -200)));
            }
        }
    }

    /**
     * Read recent log entries for display
     */
    public function getRecentLogs(int $count = 30): array
    {
        if (!file_exists($this->logFile)) return [];
        $lines = file($this->logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!$lines) return [];
        $recent = array_slice($lines, -$count);
        $parsed = [];
        foreach ($recent as $line) {
            // Format: [2026-03-21 10:55:15] Some message here
            if (preg_match('/^\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\]\s*(.+)$/', $line, $m)) {
                $msg = $m[2];
                $isError = stripos($msg, 'ERROR') !== false || stripos($msg, 'FAILED') !== false;
                $isOk    = stripos($msg, 'OK') !== false || stripos($msg, 'complete') !== false || stripos($msg, 'Uploaded') !== false;
                $parsed[] = [
                    'time'  => $m[1],
                    'msg'   => $msg,
                    'level' => $isError ? 'error' : ($isOk ? 'ok' : 'info'),
                ];
            } else {
                $parsed[] = ['time' => '', 'msg' => $line, 'level' => 'info'];
            }
        }
        return array_reverse($parsed); // newest first
    }

    /**
     * Get structured status for the backup UI dashboard.
     */
    public function getStatus(): array
    {
        $cfg        = $this->config;
        $lastBackup = $cfg['last_backup'] ?? null;
        $logFile    = $this->logFile;

        $lastRunAgo = null;
        if ($lastBackup && !empty($lastBackup['time'])) {
            $diff = time() - strtotime($lastBackup['time']);
            if ($diff < 3600)      $lastRunAgo = round($diff/60) . 'm ago';
            elseif ($diff < 86400) $lastRunAgo = round($diff/3600) . 'h ago';
            else                   $lastRunAgo = round($diff/86400) . 'd ago';
        }

        $logExists  = file_exists($logFile);
        $logSize    = $logExists ? filesize($logFile) : 0;

        // Last error from log
        $lastError = null;
        if ($logExists) {
            $lines = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
            foreach (array_reverse($lines) as $line) {
                if (stripos($line, 'ERROR') !== false || stripos($line, 'FAILED') !== false) {
                    if (preg_match('/^\[([^\]]+)\]\s*(.+)$/', $line, $m)) {
                        $lastError = ['time' => $m[1], 'msg' => $m[2]];
                    }
                    break;
                }
            }
        }

        return [
            'authorized'   => $this->isAuthorized(),
            'configured'   => $this->isConfigured(),
            'enabled'      => !empty($cfg['enabled']),
            'schedule'     => $cfg['schedule'] ?? 'daily',
            'last_backup'  => $lastBackup,
            'last_run_ago' => $lastRunAgo,
            'log_exists'   => $logExists,
            'log_size_kb'  => round($logSize / 1024, 1),
            'last_error'   => $lastError,
            'folder_name'  => $cfg['folder_name'] ?? 'DishNet Backups',
            'retention'    => $cfg['retention_count'] ?? 7,
        ];
    }
}
