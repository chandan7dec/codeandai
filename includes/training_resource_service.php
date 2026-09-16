<?php

declare(strict_types=1);

/**
 * Training Resource Service
 *
 * Recordings and documents attached to a training class.
 *  - recordings  → YouTube (dedicated channel); stores the 11-char video id,
 *                  embeds point at youtube-nocookie.com
 *  - slides/pdf  → Google Drive; stores the Drive file id, downloads redirect
 *                  to Drive's direct-download URL (zero load on this server)
 *
 * Gating: resources of free classes are open to everyone. Resources of paid
 * classes are only downloadable by attendees — matched by the email used at
 * registration (must have a confirmed registration), same model as the
 * user dashboard.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';

class TrainingResourceService
{
    /** Recording (YouTube), slides/pdf (any https URL, typically Google Drive) and code (GitHub repo/link). */
    public const TYPES = ['recording', 'slides', 'pdf', 'code'];

    /** @var \PDO|\mysqli */
    private $db;

    public function __construct($db = null)
    {
        $this->db = $db ?: getDB();
    }

    // ─────────────────────────────────────────────────────────────
    // URL parsing
    // ─────────────────────────────────────────────────────────────

    /**
     * Extract the 11-character YouTube video id from any common URL shape.
     *
     * Accepts: youtube.com/watch?v=ID, youtu.be/ID, youtube.com/shorts/ID,
     * youtube.com/embed/ID, youtube.com/live/ID (with optional extra params).
     *
     * @return string|null video id, or null if the URL is not a YouTube video
     */
    public function parseYouTubeId(string $url): ?string
    {
        $url = trim($url);
        if ($url === '') {
            return null;
        }

        $host = strtolower((string)parse_url($url, PHP_URL_HOST));
        $host = preg_replace('/^(www|m)\./', '', $host) ?? $host;
        $path = (string)parse_url($url, PHP_URL_PATH);
        parse_str((string)parse_url($url, PHP_URL_QUERY), $query);

        $isYouTube = $host === 'youtube.com' || $host === 'youtu.be'
            || str_ends_with($host, '.youtube.com');
        if (!$isYouTube) {
            return null;
        }

        // youtu.be/<id>
        if ($host === 'youtu.be') {
            $id = trim($path, '/');
            if ($id !== '' && $this->looksLikeVideoId($id)) {
                return $id;
            }
            return null;
        }

        // youtube.com/watch?v=<id>
        if (isset($query['v']) && $this->looksLikeVideoId((string)$query['v'])) {
            return (string)$query['v'];
        }

        // youtube.com/<kind>/<id> where kind is shorts|embed|live|v
        foreach (['shorts', 'embed', 'live', 'v'] as $kind) {
            if (preg_match('~^/' . $kind . '/([A-Za-z0-9_-]{11})(?:[/?#]|$)~', $path, $m)) {
                return $m[1];
            }
        }

        return null;
    }

    private function looksLikeVideoId(string $id): bool
    {
        return (bool)preg_match('/^[A-Za-z0-9_-]{11}$/', $id);
    }

    /**
     * Extract the file id from a Google Drive share URL and build the
     * direct-download URL.
     *
     * Accepts: /file/d/<id>/view, /file/d/<id>/edit, /open?id=<id>,
     * /uc?export=download&id=<id> (already a download link).
     *
     * @return array{drive_file_id: string, download_url: string}|null
     */
    public function parseDriveUrl(string $url): ?array
    {
        $url = trim($url);
        if ($url === '') {
            return null;
        }

        $host = strtolower((string)parse_url($url, PHP_URL_HOST));
        $host = preg_replace('/^(www)\./', '', $host) ?? $host;
        if ($host !== 'drive.google.com' && $host !== 'docs.google.com') {
            return null;
        }

        $path = (string)parse_url($url, PHP_URL_PATH);
        parse_str((string)parse_url($url, PHP_URL_QUERY), $query);

        // /file/d/<id>/...
        if (preg_match('#^/file/d/([A-Za-z0-9_-]+)(?:/|$)#', $path, $m)) {
            $id = $m[1];
        } elseif (isset($query['id']) && $query['id'] !== '') {
            // /open?id=<id> or /uc?export=download&id=<id>
            $id = (string)$query['id'];
        } else {
            return null;
        }

        if (!preg_match('/^[A-Za-z0-9_-]{10,64}$/', $id)) {
            return null;
        }

        return [
            'drive_file_id' => $id,
            'download_url'  => 'https://drive.google.com/uc?export=download&id=' . $id,
        ];
    }

    /**
     * Extract a GitHub repository id ("owner/repo") from any common GitHub
     * URL shape. Accepts repo roots, tree branches, blob file pages and
     * pull requests — everything else falls back to storing the raw URL.
     *
     * @return string|null "owner/repo", or null if the URL is not GitHub
     */
    public function parseGitHubUrl(string $url): ?string
    {
        $url = trim($url);
        if ($url === '') {
            return null;
        }

        $host = strtolower((string)parse_url($url, PHP_URL_HOST));
        $host = preg_replace('/^www\./', '', $host) ?? $host;
        if ($host !== 'github.com' && !str_ends_with($host, '.github.com')) {
            return null;
        }

        $path = trim((string)parse_url($url, PHP_URL_PATH), '/');
        if ($path === '') {
            return null;
        }

        $segments = explode('/', $path);
        if (count($segments) < 2) {
            return null;
        }

        $owner = $segments[0];
        $repo = $segments[1];
        if ($owner === '' || $repo === '') {
            return null;
        }

        return $owner . '/' . $repo;
    }

    /**
     * Resolve any https URL into a stored link for non-recording resources.
     *
     * Returns a shape that depends on the URL kind:
     *  - Google Drive share/download links → drive_file_id (+ download_url)
     *  - GitHub links                        → repo id in resource_url
     *  - any other https URL                 → stored verbatim in resource_url
     * Non-https URLs are rejected (http-only hosts are not supported).
     *
     * @return array{resource_url: string, drive_file_id: string|null}|null
     */
    public function parseResourceUrl(string $url): ?array
    {
        $url = trim($url);
        if ($url === '') {
            return null;
        }

        $scheme = strtolower((string)parse_url($url, PHP_URL_SCHEME));
        if ($scheme !== 'https') {
            return null;
        }

        // Google Drive keeps the lightweight id + direct-download URL path.
        $drive = $this->parseDriveUrl($url);
        if ($drive !== null) {
            return ['resource_url' => '', 'drive_file_id' => $drive['drive_file_id']];
        }

        // Everything else (GitHub, any https doc host) stores the URL as-is.
        return ['resource_url' => $url, 'drive_file_id' => null];
    }

    // ─────────────────────────────────────────────────────────────
    // Validation
    // ─────────────────────────────────────────────────────────────

    /**
     * Validate resource input; returns field => message map (empty = valid).
     *
     * @return array<string, string>
     */
    public static function validateResourceInput(array $input): array
    {
        $errors = [];

        $classId = trim((string)($input['class_id'] ?? ''));
        if ($classId === '') {
            $errors['class_id'] = 'A training class is required.';
        }

        $type = trim((string)($input['type'] ?? ''));
        if (!in_array($type, self::TYPES, true)) {
            $errors['type'] = 'Type must be one of: ' . implode(', ', self::TYPES) . '.';
        }

        $title = trim((string)($input['title'] ?? ''));
        if ($title === '') {
            $errors['title'] = 'Title is required.';
        } elseif (strlen($title) > 255) {
            $errors['title'] = 'Title must be 255 characters or fewer.';
        }

        $youtubeUrl = trim((string)($input['youtube_url'] ?? ''));
        $driveUrl = trim((string)($input['drive_url'] ?? ''));

        if ($type === 'recording') {
            if ($youtubeUrl === '') {
                $errors['youtube_url'] = 'A YouTube link is required for recordings.';
            } elseif (!(new self())->parseYouTubeId($youtubeUrl)) {
                $errors['youtube_url'] = 'That does not look like a valid YouTube video link.';
            }
        } elseif ($type !== '') {
            // Slides/PDF/code: any https URL is accepted (Google Drive, GitHub,
            // S3, a corporate wiki …). Only a well-formed https link is required.
            $url = $driveUrl !== '' ? $driveUrl : trim((string)($input['resource_url'] ?? ''));
            if ($url === '') {
                $errors['resource_url'] = 'A https link is required for ' . $type . ' resources.';
            } elseif ((new self())->parseResourceUrl($url) === null) {
                $errors['resource_url'] = 'Only https:// links are supported (e.g. Google Drive, GitHub).';
            }
        }

        $fileName = trim((string)($input['file_name'] ?? ''));
        if ($fileName !== '' && strlen($fileName) > 255) {
            $errors['file_name'] = 'File name must be 255 characters or fewer.';
        }

        $fileSizeLabel = trim((string)($input['file_size_label'] ?? ''));
        if ($fileSizeLabel !== '' && strlen($fileSizeLabel) > 20) {
            $errors['file_size_label'] = 'File size must be 20 characters or fewer.';
        }

        return $errors;
    }

    // ─────────────────────────────────────────────────────────────
    // CRUD
    // ─────────────────────────────────────────────────────────────

    /**
     * Create a resource from organizer input (type + pasted URL).
     *
     * @return array<string, mixed> the created resource row
     * @throws InvalidArgumentException with a JSON field-error map
     * @throws OutOfBoundsException    when the class does not exist
     */
    public function addResource(array $input): array
    {
        $errors = self::validateResourceInput($input);
        if ($errors !== []) {
            throw new InvalidArgumentException((string)json_encode($errors));
        }

        $classId = trim((string)$input['class_id']);
        if (!$this->classExists($classId)) {
            throw new OutOfBoundsException('Training class not found.');
        }

        $type = trim((string)$input['type']);
        $youtubeVideoId = null;
        $driveFileId = null;
        $resourceUrl = null;

        if ($type === 'recording') {
            $youtubeVideoId = $this->parseYouTubeId((string)($input['youtube_url'] ?? ''));
        } else {
            $url = trim((string)($input['drive_url'] ?? '')) !== ''
                ? (string)$input['drive_url']
                : (string)($input['resource_url'] ?? '');
            $parsed = $this->parseResourceUrl($url);
            if ($parsed === null) {
                throw new InvalidArgumentException((string)json_encode([
                    'resource_url' => 'Only https:// links are supported (e.g. Google Drive, GitHub).',
                ]));
            }
            $driveFileId = $parsed['drive_file_id'];
            $resourceUrl = $parsed['resource_url'] !== '' ? $parsed['resource_url'] : null;
        }

        $id = generateUUID();
        $title = trim((string)$input['title']);
        $fileName = trim((string)($input['file_name'] ?? ''));
        $fileName = $fileName !== '' ? $fileName : null;
        $fileSizeLabel = trim((string)($input['file_size_label'] ?? ''));
        $fileSizeLabel = $fileSizeLabel !== '' ? $fileSizeLabel : null;
        $isPublished = array_key_exists('is_published', $input)
            ? (int)(bool)$input['is_published']
            : 1;

        $this->execute(
            'INSERT INTO training_resources
                (id, class_id, type, title, youtube_video_id, drive_file_id, resource_url, file_name, file_size_label, is_published)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$id, $classId, $type, $title, $youtubeVideoId, $driveFileId, $resourceUrl, $fileName, $fileSizeLabel, $isPublished],
            'sssssssssi'
        );

        $resource = $this->getById($id);
        assert($resource !== null);
        return $resource;
    }

    /**
     * Update editable fields of a resource.
     *
     * @throws OutOfBoundsException when the resource does not exist
     * @throws InvalidArgumentException with a JSON field-error map
     */
    public function updateResource(string $id, array $input): array
    {
        $existing = $this->getById($id);
        if ($existing === null) {
            throw new OutOfBoundsException('Resource not found.');
        }

        $type = isset($input['type'])
            ? trim((string)$input['type'])
            : (string)$existing['type'];
        if (!in_array($type, self::TYPES, true)) {
            throw new InvalidArgumentException((string)json_encode([
                'type' => 'Type must be one of: ' . implode(', ', self::TYPES) . '.',
            ]));
        }

        $title = isset($input['title'])
            ? trim((string)$input['title'])
            : (string)$existing['title'];
        if ($title === '' || strlen($title) > 255) {
            throw new InvalidArgumentException((string)json_encode([
                'title' => 'Title is required and must be 255 characters or fewer.',
            ]));
        }

        $fileName = isset($input['file_name'])
            ? trim((string)$input['file_name'])
            : (string)($existing['file_name'] ?? '');
        $fileName = $fileName !== '' ? $fileName : null;

        $fileSizeLabel = isset($input['file_size_label'])
            ? trim((string)$input['file_size_label'])
            : (string)($existing['file_size_label'] ?? '');
        $fileSizeLabel = $fileSizeLabel !== '' ? $fileSizeLabel : null;

        $youtubeVideoId = $existing['youtube_video_id'];
        $driveFileId = $existing['drive_file_id'];
        $resourceUrl = $existing['resource_url'] ?? null;

        // If a new URL is supplied, re-parse it (and keep type consistent).
        $youtubeUrl = trim((string)($input['youtube_url'] ?? ''));
        $driveUrl = trim((string)($input['drive_url'] ?? ''));
        $resourceUrlInput = trim((string)($input['resource_url'] ?? ''));
        if ($youtubeUrl !== '') {
            $parsed = $this->parseYouTubeId($youtubeUrl);
            if ($parsed === null) {
                throw new InvalidArgumentException((string)json_encode([
                    'youtube_url' => 'That does not look like a valid YouTube video link.',
                ]));
            }
            $youtubeVideoId = $parsed;
            $type = 'recording';
        } else {
            $newUrl = $driveUrl !== '' ? $driveUrl : $resourceUrlInput;
            if ($newUrl !== '') {
                $parsed = $this->parseResourceUrl($newUrl);
                if ($parsed === null) {
                    throw new InvalidArgumentException((string)json_encode([
                        'resource_url' => 'Only https:// links are supported (e.g. Google Drive, GitHub).',
                    ]));
                }
                $driveFileId = $parsed['drive_file_id'];
                $resourceUrl = $parsed['resource_url'] !== '' ? $parsed['resource_url'] : null;
                if ($type === 'recording') {
                    $type = 'slides';
                }
            }
        }

        $this->execute(
            'UPDATE training_resources
             SET type = ?, title = ?, youtube_video_id = ?, drive_file_id = ?, resource_url = ?,
                 file_name = ?, file_size_label = ?, updated_at = CURRENT_TIMESTAMP
             WHERE id = ?',
            [$type, $title, $youtubeVideoId, $driveFileId, $resourceUrl, $fileName, $fileSizeLabel, $id],
            'sssssssss'
        );

        $resource = $this->getById($id);
        assert($resource !== null);
        return $resource;
    }

    /**
     * Publish or unpublish a resource. Unpublished resources disappear from
     * the public page and cannot be downloaded.
     *
     * @throws OutOfBoundsException when the resource does not exist
     */
    public function setPublished(string $id, bool $published): array
    {
        $existing = $this->getById($id);
        if ($existing === null) {
            throw new OutOfBoundsException('Resource not found.');
        }

        $this->execute(
            'UPDATE training_resources SET is_published = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?',
            [(int)$published, $id],
            'is'
        );

        $resource = $this->getById($id);
        assert($resource !== null);
        return $resource;
    }

    /**
     * Delete a resource.
     *
     * @throws OutOfBoundsException when the resource does not exist
     */
    public function deleteResource(string $id): void
    {
        $existing = $this->getById($id);
        if ($existing === null) {
            throw new OutOfBoundsException('Resource not found.');
        }

        $this->execute('DELETE FROM training_resources WHERE id = ?', [$id], 's');
    }

    /**
     * Increment the download counter (called by download.php after the gate).
     */
    public function incrementDownloadCount(string $id): void
    {
        $this->execute(
            'UPDATE training_resources SET download_count = download_count + 1 WHERE id = ?',
            [$id],
            's'
        );
    }

    // ─────────────────────────────────────────────────────────────
    // Queries
    // ─────────────────────────────────────────────────────────────

    public function getById(string $id): ?array
    {
        $row = $this->fetchOne(
            'SELECT * FROM training_resources WHERE id = ?',
            [$id],
            's'
        );
        return $row !== null ? $this->hydrate($row) : null;
    }

    /**
     * All resources (published + unpublished) with class context — organizer table.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getAllWithClass(): array
    {
        $rows = $this->fetchAll(
            'SELECT tr.*, dc.title AS class_title, dc.is_paid, dc.scheduled_at, dc.timezone
             FROM training_resources tr
             JOIN demo_classes dc ON tr.class_id = dc.id
             ORDER BY dc.scheduled_at DESC, tr.created_at ASC'
        );
        $out = [];
        foreach ($rows as $row) {
            $row = $this->hydrate($row);
            $row['is_paid'] = (int)$row['is_paid'];
            $out[] = $row;
        }
        return $out;
    }

    /**
     * Published resources for the public page, grouped by class.
     * Only includes classes whose scheduled date is in the past (trainings
     * that have actually happened), newest first.
     *
     * @return array<int, array{class: array<string, mixed>, resources: array<int, array<string, mixed>>}>
     */
    public function getPublishedGroupedByClass(): array
    {
        $rows = $this->fetchAll(
            'SELECT tr.*, dc.title AS class_title, dc.topic, dc.trainer_name,
                    dc.scheduled_at, dc.timezone, dc.is_paid, dc.price
             FROM training_resources tr
             JOIN demo_classes dc ON tr.class_id = dc.id
             WHERE tr.is_published = 1
             ORDER BY dc.scheduled_at DESC, tr.created_at ASC'
        );

        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $groups = [];
        foreach ($rows as $row) {
            $classId = (string)$row['class_id'];
            if (!isset($groups[$classId])) {
                $scheduled = null;
                try {
                    $scheduled = new DateTimeImmutable((string)$row['scheduled_at'], new DateTimeZone((string)$row['timezone']));
                } catch (Exception $e) {
                    // fall through with null → treat as future, skip below
                }
                if ($scheduled === null || $scheduled > $now) {
                    // Don't show resources for trainings that haven't happened yet
                    continue;
                }
                $groups[$classId] = [
                    'class' => [
                        'id'           => $classId,
                        'title'        => (string)$row['class_title'],
                        'topic'        => (string)$row['topic'],
                        'trainer_name' => (string)$row['trainer_name'],
                        'scheduled_at' => (string)$row['scheduled_at'],
                        'timezone'     => (string)$row['timezone'],
                        'is_paid'      => (int)$row['is_paid'],
                        'price'        => (float)$row['price'],
                        'is_past'      => true,
                    ],
                    'resources' => [],
                ];
            }

            $groups[$classId]['resources'][] = $this->hydrate($row);
        }

        return array_values($groups);
    }

    /**
     * Gating check: may this email download resources of this class?
     *
     * Free class → always true. Paid class → only if the email has a
     * confirmed registration for that class.
     */
    public function canDownload(string $classId, ?string $email): bool
    {
        $class = $this->fetchOne(
            'SELECT is_paid FROM demo_classes WHERE id = ?',
            [$classId],
            's'
        );
        if ($class === null) {
            return false;
        }

        if ((int)$class['is_paid'] !== 1) {
            return true; // free class → open to everyone
        }

        $email = normalizeEmail((string)$email);
        if ($email === '') {
            return false;
        }

        $row = $this->fetchOne(
            'SELECT r.id
             FROM registrations r
             JOIN registrants reg ON r.registrant_id = reg.id
             WHERE reg.email = ? AND r.demo_class_id = ? AND r.registration_status = ?',
            [$email, $classId, 'confirmed'],
            'sss'
        );
        return $row !== null;
    }

    // ─────────────────────────────────────────────────────────────
    // Internals
    // ─────────────────────────────────────────────────────────────

    private function classExists(string $classId): bool
    {
        return $this->fetchOne('SELECT id FROM demo_classes WHERE id = ?', [$classId], 's') !== null;
    }

    /**
     * Normalize a DB row for output (cast ints, add computed URLs).
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function hydrate(array $row): array
    {
        $row['download_count'] = (int)$row['download_count'];
        $row['is_published'] = (int)$row['is_published'];

        if (!empty($row['youtube_video_id'])) {
            $row['watch_url'] = 'https://www.youtube.com/watch?v=' . $row['youtube_video_id'];
            $row['embed_url'] = 'https://www.youtube-nocookie.com/embed/' . $row['youtube_video_id'];
            $row['thumbnail_url'] = 'https://i.ytimg.com/vi/' . $row['youtube_video_id'] . '/hqdefault.jpg';
        }

        if (!empty($row['drive_file_id'])) {
            $row['drive_download_url'] = 'https://drive.google.com/uc?export=download&id=' . $row['drive_file_id'];
        }

        if (!empty($row['resource_url'])) {
            $row['resource_download_url'] = (string)$row['resource_url'];
            $row['resource_host'] = strtolower((string)(parse_url((string)$row['resource_url'], PHP_URL_HOST) ?: ''));
            $row['resource_host'] = preg_replace('/^(www)\./', '', $row['resource_host']) ?? $row['resource_host'];
            if (str_ends_with($row['resource_host'], 'github.com')) {
                $row['github_repo'] = $this->parseGitHubUrl((string)$row['resource_url']);
            }
        }

        return $row;
    }

    // ── DB helpers (dual driver: PDO/SQLite + MySQLi) ──

    private function execute(string $sql, array $params, string $types): void
    {
        if ($this->db instanceof PDO) {
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            return;
        }
        $stmt = $this->db->prepare($sql);
        if ($types !== '' && $params !== []) {
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
    }

    private function fetchOne(string $sql, array $params = [], string $types = ''): ?array
    {
        if ($this->db instanceof PDO) {
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row !== false ? $row : null;
        }
        $stmt = $this->db->prepare($sql);
        if ($types !== '' && $params !== []) {
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result ? $result->fetch_assoc() : null;
        return $row !== null ? $row : null;
    }

    private function fetchAll(string $sql, array $params = [], string $types = ''): array
    {
        if ($this->db instanceof PDO) {
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
        $stmt = $this->db->prepare($sql);
        if ($types !== '' && $params !== []) {
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        $result = $stmt->get_result();
        return $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
    }
}
