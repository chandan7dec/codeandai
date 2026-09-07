<?php

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';

class ClassManagementService
{
    private $db;

    public function __construct($db = null)
    {
        $this->db = $db ?: getDB();
    }

    public static function validateClassInput(array $input): array
    {
        $errors = [];
        $title = trim((string)($input['title'] ?? ''));
        $topic = trim((string)($input['topic'] ?? ''));
        $trainerName = trim((string)($input['trainer_name'] ?? ''));
        $topic = trim((string)($input['topic'] ?? ''));
        $trainerName = trim((string)($input['trainer_name'] ?? ''));
        $scheduledAt = trim((string)($input['scheduled_at'] ?? ''));
        $timezone = trim((string)($input['timezone'] ?? ''));
        $teamsLink = trim((string)($input['teams_link'] ?? ''));
        $capacity = $input['capacity'] ?? null;

        if ($title === '') {
            $errors['title'] = 'Title is required.';
        } elseif (strlen($title) > 255) {
            $errors['title'] = 'Title must be 255 characters or fewer.';
        }
        if ($topic === '') {
            $errors['topic'] = 'Topic is required.';
        } elseif (strlen($topic) > 255) {
            $errors['topic'] = 'Topic must be 255 characters or fewer.';
        }
        if ($trainerName === '') {
            $errors['trainer_name'] = 'Trainer name is required.';
        } elseif (strlen($trainerName) > 255) {
            $errors['trainer_name'] = 'Trainer name must be 255 characters or fewer.';
        }
        if ($topic === '') {
            $errors['topic'] = 'Topic is required.';
        } elseif (strlen($topic) > 255) {
            $errors['topic'] = 'Topic must be 255 characters or fewer.';
        }
        if ($trainerName === '') {
            $errors['trainer_name'] = 'Trainer name is required.';
        } elseif (strlen($trainerName) > 255) {
            $errors['trainer_name'] = 'Trainer name must be 255 characters or fewer.';
        }
        if ($scheduledAt === false || strtotime($scheduledAt) === false) {
            $errors['scheduled_at'] = 'A valid scheduled date and time is required.';
        }
        try {
            new DateTimeZone($timezone);
        } catch (Exception $exception) {
            $errors['timezone'] = 'A valid timezone is required.';
        }
        if ($teamsLink !== '' && filter_var($teamsLink, FILTER_VALIDATE_URL) === false) {
            $errors['teams_link'] = 'Meeting link must be a valid URL.';
        }
        if ($capacity !== null && $capacity !== '' && (!filter_var($capacity, FILTER_VALIDATE_INT) || (int)$capacity < 1)) {
            $errors['capacity'] = 'Capacity must be a positive whole number or unlimited.';
        }

        return $errors;
    }

    public static function canTransition(string $status, bool $open, string $action): bool
    {
        if ($action === 'open') {
            return $status === 'active' && !$open;
        }
        if ($action === 'close') {
            return $status === 'active' && $open;
        }
        return in_array($action, ['cancel', 'archive'], true) && $status !== 'archived';
    }

    public function getAllClasses(): array
    {
        $sql = "SELECT dc.*,
               (SELECT COUNT(*) FROM registrations r WHERE r.demo_class_id = dc.id AND r.registration_status != 'cancelled') AS registration_count,
               (SELECT COUNT(*) FROM registrations r WHERE r.demo_class_id = dc.id) AS registration_total
            FROM demo_classes dc
                ORDER BY dc.scheduled_at ASC";
        return array_map([$this, 'withAvailability'], $this->query($sql));
    }

    public function getOpenClass(): ?array
    {
        $row = $this->queryOne(
                "SELECT dc.*,
                    (SELECT COUNT(*) FROM registrations r WHERE r.demo_class_id = dc.id AND r.registration_status != 'cancelled') AS registration_count,
                    (SELECT COUNT(*) FROM registrations r WHERE r.demo_class_id = dc.id) AS registration_total
             FROM demo_classes dc
             WHERE dc.status = 'active' AND dc.registration_open = 1
             ORDER BY dc.scheduled_at ASC LIMIT 1"
        );
        return $row ? $this->withAvailability($row) : null;
    }

    public function getCalendarClasses(): array
    {
        $classes = $this->query(
                "SELECT dc.*,
                    (SELECT COUNT(*) FROM registrations r WHERE r.demo_class_id = dc.id AND r.registration_status != 'cancelled') AS registration_count,
                    (SELECT COUNT(*) FROM registrations r WHERE r.demo_class_id = dc.id) AS registration_total
                 FROM demo_classes dc
             WHERE dc.status = 'active'
             ORDER BY dc.scheduled_at ASC"
        );
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $calendar = ['active' => [], 'upcoming' => []];
        foreach ($classes as $class) {
            $class = $this->withAvailability($class);
            $scheduledAt = new DateTimeImmutable($class['scheduled_at'], new DateTimeZone($class['timezone']));
            $calendar[$scheduledAt <= $now ? 'active' : 'upcoming'][] = $class;
        }
        return $calendar;
    }

    public function create(array $input): array
    {
        $errors = self::validateClassInput($input);
        if ($errors) {
            throw new InvalidArgumentException(json_encode($errors));
        }
        $id = generateUUID();
        $capacity = ($input['capacity'] ?? '') === '' ? null : (int)$input['capacity'];
        $this->execute(
            'INSERT INTO demo_classes (id, title, topic, trainer_name, scheduled_at, timezone, teams_link, status, registration_open, capacity) VALUES (?, ?, ?, ?, ?, ?, ?, \'active\', ?, ?)',
            [$id, trim($input['title']), trim($input['topic']), trim($input['trainer_name']), trim($input['scheduled_at']), trim($input['timezone']), trim((string)($input['teams_link'] ?? '')) ?: null, !empty($input['registration_open']) ? 1 : 0, $capacity],
            'sssssssii'
        );
        return $this->getById($id);
    }

    public function getById(string $id): ?array
    {
        $row = $this->queryOne(
                "SELECT dc.*,
                    (SELECT COUNT(*) FROM registrations r WHERE r.demo_class_id = dc.id AND r.registration_status != 'cancelled') AS registration_count,
                    (SELECT COUNT(*) FROM registrations r WHERE r.demo_class_id = dc.id) AS registration_total
             FROM demo_classes dc
             WHERE dc.id = ?",
            [$id],
            's'
        );
        return $row ? $this->withAvailability($row) : null;
    }

    public function update(string $id, array $input): array
    {
        $errors = self::validateClassInput($input);
        if ($errors) {
            throw new InvalidArgumentException(json_encode($errors));
        }
        $current = $this->getById($id);
        if (!$current) {
            throw new OutOfBoundsException('Class not found.');
        }
        $capacity = ($input['capacity'] ?? '') === '' ? null : (int)$input['capacity'];
        if ($capacity !== null && $capacity < (int)$current['registration_count']) {
            throw new RuntimeException('Capacity cannot be lower than active registrations.');
        }
        $this->execute(
            'UPDATE demo_classes SET title = ?, topic = ?, trainer_name = ?, scheduled_at = ?, timezone = ?, teams_link = ?, registration_open = ?, capacity = ? WHERE id = ?',
            [trim($input['title']), trim($input['topic']), trim($input['trainer_name']), trim($input['scheduled_at']), trim($input['timezone']), trim((string)($input['teams_link'] ?? '')) ?: null, !empty($input['registration_open']) ? 1 : 0, $capacity, $id],
            'ssssssiis'
        );
        return $this->getById($id);
    }

    public function transition(string $id, string $action): array
    {
        $current = $this->getById($id);
        if (!$current || !self::canTransition($current['status'], (bool)$current['registration_open'], $action)) {
            throw new RuntimeException('Invalid class state transition.');
        }
        $status = $current['status'];
        $open = (int)$current['registration_open'];
        if ($action === 'open') $open = 1;
        if ($action === 'close') $open = 0;
        if ($action === 'cancel') { $status = 'cancelled'; $open = 0; }
        if ($action === 'archive') { $status = 'archived'; $open = 0; }
        $this->execute('UPDATE demo_classes SET status = ?, registration_open = ? WHERE id = ?', [$status, $open, $id], 'sis');
        return $this->getById($id);
    }

    public function delete(string $id): void
    {
        $current = $this->getById($id);
        if (!$current) {
            throw new OutOfBoundsException('Class not found.');
        }
        if ((int)$current['registration_total'] > 0) {
            throw new RuntimeException('Classes with registrations must be archived instead of deleted.');
        }
        $this->execute('DELETE FROM demo_classes WHERE id = ?', [$id], 's');
    }

    private function withAvailability(array $row): array
    {
        $row['registration_count'] = (int)$row['registration_count'];
        $row['registration_total'] = (int)($row['registration_total'] ?? $row['registration_count']);
        $row['capacity'] = $row['capacity'] === null ? null : (int)$row['capacity'];
        $row['registration_open'] = (bool)$row['registration_open'];
        $row['remaining_capacity'] = $row['capacity'] === null ? null : max(0, $row['capacity'] - $row['registration_count']);
        return $row;
    }

    private function query(string $sql, array $params = [], string $types = ''): array
    {
        if ($this->db instanceof PDO) {
            $statement = $this->db->prepare($sql); $statement->execute($params); return $statement->fetchAll(PDO::FETCH_ASSOC);
        }
        $statement = $this->db->prepare($sql); if ($params) $statement->bind_param($types, ...$params); $statement->execute();
        $result = $statement->get_result(); $rows = []; while ($row = $result->fetch_assoc()) $rows[] = $row; return $rows;
    }

    private function queryOne(string $sql, array $params = [], string $types = ''): ?array
    {
        $rows = $this->query($sql, $params, $types); return $rows[0] ?? null;
    }

    private function execute(string $sql, array $params = [], string $types = ''): int
    {
        if ($this->db instanceof PDO) { $statement = $this->db->prepare($sql); $statement->execute($params); return $statement->rowCount(); }
        $statement = $this->db->prepare($sql); if ($params) $statement->bind_param($types, ...$params); $statement->execute(); return $statement->affected_rows;
    }
}