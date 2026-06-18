<?php

declare(strict_types=1);

namespace Maegc;

use PDO;
use Throwable;

final class App
{
    private PDO $db;
    private Auth $auth;
    private Cloudinary $cloudinary;

    public function __construct(private array $config)
    {
        $this->db = Database::connect($config);
        $this->auth = new Auth($config);
        $this->cloudinary = new Cloudinary($config);
    }

    public function handle(): void
    {
        $this->cors();

        if ($this->method() === 'OPTIONS') {
            Support::noContent();
            return;
        }

        try {
            $this->ensureDefaults();
            $path = $this->normalizedPath();
            $segments = $path === '/' ? [] : explode('/', trim($path, '/'));

            if ($segments === []) {
                Support::text('API is running ✔');
                return;
            }

            match ($segments[0]) {
                'auth' => $this->routeAuth($segments),
                'players' => $this->routePlayers($segments),
                'teams' => $this->routeTeams($segments),
                'competitions' => $this->routeCompetitions($segments),
                'matches' => $this->routeMatches($segments),
                'contracts' => $this->routeContracts($segments),
                'mercato' => $this->routeMercato($segments),
                'banned' => $this->routeBanned($segments),
                'settings' => $this->routeSettings($segments),
                'standings' => $this->routeStandings($segments),
                'news' => $this->routeNews($segments),
                'public' => $this->routeCalendarPublic($segments),
                'admin' => $this->routeCalendarAdmin($segments),
                default => Support::json(['error' => 'Route not found'], 404),
            };
        } catch (Throwable $e) {
            error_log($e->getMessage() . "\n" . $e->getTraceAsString());
            Support::json(['error' => 'Server error'], 500);
        }
    }

    private function method(): string
    {
        return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    }

    private function normalizedPath(): string
    {
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        $scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/'));
        if ($scriptDir !== '/' && str_starts_with($path, $scriptDir . '/')) {
            $path = substr($path, strlen($scriptDir));
        }
        if (str_starts_with($path, '/index.php')) {
            $path = substr($path, strlen('/index.php')) ?: '/';
        }
        if ($path === '/api') {
            $path = '/';
        } elseif (str_starts_with($path, '/api/')) {
            $path = substr($path, 4);
        }
        return '/' . trim($path, '/');
    }

    private function cors(): void
    {
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
        $origins = $this->config['frontend_origins'] ?? [];
        $envOrigins = getenv('FRONTEND_ORIGINS');
        if ($envOrigins) {
            $origins = array_map('trim', explode(',', $envOrigins));
        }

        $allowed = false;
        if ($origin === '') {
            $allowed = true;
        } elseif (in_array($origin, $origins, true)) {
            $allowed = true;
        } elseif (preg_match('/^https:\/\/maegc-frontend(-[a-z0-9-]+)?\.vercel\.app$/i', $origin)) {
            $allowed = true;
        } elseif (preg_match('/^https:\/\/maegc-frontend-[a-z0-9-]+-walid-benabdelhaks-projects\.vercel\.app$/i', $origin)) {
            $allowed = true;
        } elseif (preg_match('/^http:\/\/(localhost|127\.0\.0\.1):\d+$/i', $origin)) {
            $allowed = true;
        }

        if ($allowed && $origin !== '') {
            header("Access-Control-Allow-Origin: $origin");
            header('Vary: Origin');
        }
        header('Access-Control-Allow-Credentials: true');
        header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Authorization');
    }

    private function ensureDefaults(): void
    {
        $count = (int) $this->value('SELECT COUNT(*) FROM users WHERE role = ?', ['SUPERADMIN']);
        if ($count === 0) {
            $admin = $this->config['default_superadmin'];
            $this->insert('users', [
                'email' => $admin['email'],
                'password' => password_hash((string) $admin['password'], PASSWORD_BCRYPT, ['cost' => 10]),
                'role' => 'SUPERADMIN',
                'createdAt' => gmdate('Y-m-d H:i:s'),
                'updatedAt' => gmdate('Y-m-d H:i:s'),
            ]);
        }

        $settings = (int) $this->value('SELECT COUNT(*) FROM settings WHERE id = 1');
        if ($settings === 0) {
            $this->insert('settings', [
                'id' => 1,
                'editMode' => 0,
                'mercatoOpen' => 0,
                'playerCreateOpen' => 0,
            ]);
        }
    }

    private function routeAuth(array $s): void
    {
        $method = $this->method();
        if ($method === 'POST' && $s === ['auth', 'login']) {
            $this->login();
            return;
        }
        if ($method === 'GET' && $s === ['auth', 'me']) {
            $this->getProfile();
            return;
        }
        if ($method === 'PUT' && $s === ['auth', 'update-profile']) {
            $this->updateProfile();
            return;
        }
        if ($method === 'GET' && $s === ['auth', 'debug']) {
            Support::text('AUTH ROUTE LOADED');
            return;
        }
        if ($method === 'GET' && $s === ['auth', 'admins']) {
            $this->getAdmins();
            return;
        }
        if ($method === 'POST' && $s === ['auth', 'create-admin']) {
            $this->createAdmin();
            return;
        }
        if ($method === 'PUT' && count($s) >= 3 && $s[1] === 'admins') {
            $this->updateAdmin((int) $s[2]);
            return;
        }
        if ($method === 'DELETE' && count($s) === 3 && $s[1] === 'admins') {
            $this->deleteAdmin((int) $s[2]);
            return;
        }
        Support::json(['error' => 'Route not found'], 404);
    }

    private function routePlayers(array $s): void
    {
        $method = $this->method();
        if ($method === 'GET' && $s === ['players', 'public']) {
            $this->getPublicPlayers();
            return;
        }
        if ($method === 'GET' && $s === ['players', 'private']) {
            $this->getPrivatePlayers();
            return;
        }
        if ($method === 'POST' && $s === ['players']) {
            $this->addPlayer();
            return;
        }
        if ($method === 'POST' && $s === ['players', 'by-identifiers']) {
            $this->deletePlayerByIdentifiers();
            return;
        }
        if ($method === 'PUT' && count($s) === 2) {
            $this->updatePlayer((int) $s[1]);
            return;
        }
        if ($method === 'DELETE' && count($s) === 2) {
            $this->deletePlayer((int) $s[1]);
            return;
        }
        Support::json(['error' => 'Route not found'], 404);
    }

    private function routeTeams(array $s): void
    {
        $method = $this->method();
        if ($method === 'GET' && $s === ['teams']) {
            Support::json(array_map(fn ($r) => $this->teamRow($r), $this->all('SELECT * FROM teams ORDER BY id ASC')));
            return;
        }
        if ($method === 'GET' && count($s) === 2) {
            $this->getTeamById((int) $s[1]);
            return;
        }
        if ($method === 'POST' && $s === ['teams']) {
            $this->createTeam();
            return;
        }
        if ($method === 'PUT' && count($s) === 2) {
            $this->updateTeam((int) $s[1]);
            return;
        }
        if ($method === 'PUT' && count($s) === 3 && $s[2] === 'logo') {
            $this->updateTeamLogo((int) $s[1]);
            return;
        }
        if ($method === 'DELETE' && count($s) === 2) {
            $this->deleteTeam((int) $s[1]);
            return;
        }
        Support::json(['error' => 'Route not found'], 404);
    }

    private function routeCompetitions(array $s): void
    {
        $method = $this->method();
        if ($method === 'GET' && $s === ['competitions']) {
            Support::json(array_map(fn ($r) => $this->competitionRow($r), $this->all('SELECT * FROM competitions ORDER BY id ASC')));
            return;
        }
        if ($method === 'GET' && count($s) === 3 && $s[2] === 'rules-pdf') {
            $this->downloadCompetitionRulesPdf((int) $s[1]);
            return;
        }
        if ($method === 'GET' && count($s) === 2) {
            $competition = $this->one('SELECT * FROM competitions WHERE id = ?', [(int) $s[1]]);
            $competition ? Support::json($this->competitionRow($competition)) : Support::json(['error' => 'Competition not found'], 404);
            return;
        }
        if ($method === 'POST' && $s === ['competitions']) {
            $this->createCompetition();
            return;
        }
        if ($method === 'PUT' && count($s) === 2) {
            $this->updateCompetition((int) $s[1]);
            return;
        }
        if ($method === 'DELETE' && count($s) === 2) {
            $this->deleteCompetition((int) $s[1]);
            return;
        }
        if ($method === 'POST' && count($s) === 3 && $s[2] === 'logo') {
            $this->uploadCompetitionLogo((int) $s[1]);
            return;
        }
        if ($method === 'POST' && count($s) === 3 && $s[2] === 'rules-pdf') {
            $this->uploadCompetitionRulesPdf((int) $s[1]);
            return;
        }
        Support::json(['error' => 'Route not found'], 404);
    }

    private function routeMatches(array $s): void
    {
        $method = $this->method();
        if ($method === 'GET' && $s === ['matches']) {
            $this->respondMatches('SELECT * FROM matches ORDER BY matchDate ASC');
            return;
        }
        if ($method === 'GET' && count($s) === 3 && $s[1] === 'competition') {
            $this->respondMatches('SELECT * FROM matches WHERE competitionId = ? ORDER BY matchDate ASC', [(int) $s[2]]);
            return;
        }
        if ($method === 'GET' && count($s) === 3 && $s[1] === 'team') {
            $this->respondMatches('SELECT * FROM matches WHERE homeTeamId = ? OR awayTeamId = ? ORDER BY matchDate ASC', [(int) $s[2], (int) $s[2]]);
            return;
        }
        if ($method === 'GET' && $s === ['matches', 'upcoming']) {
            $this->respondMatches('SELECT * FROM matches WHERE matchDate >= ? ORDER BY matchDate ASC', [gmdate('Y-m-d H:i:s')]);
            return;
        }
        if ($method === 'GET' && $s === ['matches', 'past']) {
            $this->respondMatches('SELECT * FROM matches WHERE matchDate < ? ORDER BY matchDate DESC', [gmdate('Y-m-d H:i:s')]);
            return;
        }
        if ($method === 'POST' && $s === ['matches']) {
            $this->createMatch();
            return;
        }
        if ($method === 'PUT' && count($s) === 2) {
            $this->updateMatch((int) $s[1]);
            return;
        }
        if ($method === 'DELETE' && count($s) === 2) {
            $this->deleteMatch((int) $s[1]);
            return;
        }
        Support::json(['error' => 'Route not found'], 404);
    }

    private function routeContracts(array $s): void
    {
        $method = $this->method();
        if ($method === 'GET' && $s === ['contracts', 'my']) {
            $this->getMyContracts();
            return;
        }
        if ($method === 'POST' && $s === ['contracts']) {
            $this->createContract();
            return;
        }
        if ($method === 'PUT' && count($s) === 2) {
            $this->updateContract((int) $s[1]);
            return;
        }
        if ($method === 'GET' && count($s) === 4 && $s[1] === 'player' && $s[3] === 'pdf') {
            $this->downloadPlayerContractPdf((int) $s[2]);
            return;
        }
        Support::json(['error' => 'Route not found'], 404);
    }

    private function routeMercato(array $s): void
    {
        if ($this->method() === 'POST' && $s === ['mercato', 'transfer']) {
            $this->transferPlayer();
            return;
        }
        Support::json(['error' => 'Route not found'], 404);
    }

    private function routeBanned(array $s): void
    {
        $method = $this->method();
        if ($method === 'GET' && $s === ['banned']) {
            $this->auth->requireAdmin();
            Support::json(array_map(fn ($r) => $this->bannedRow($r), $this->all('SELECT * FROM banned_players ORDER BY dateAdded DESC')));
            return;
        }
        if ($method === 'POST' && $s === ['banned', 'existing']) {
            $this->banExistingPlayer();
            return;
        }
        if ($method === 'POST' && $s === ['banned']) {
            $this->addBannedPlayer();
            return;
        }
        if ($method === 'DELETE' && count($s) === 2) {
            $this->removeBannedPlayer((int) $s[1]);
            return;
        }
        Support::json(['error' => 'Route not found'], 404);
    }

    private function routeSettings(array $s): void
    {
        $method = $this->method();
        if ($method === 'GET' && $s === ['settings', 'public']) {
            $settings = $this->settings();
            Support::json(['generalRulesPdfUrl' => $settings['generalRulesPdfUrl'] ?? null]);
            return;
        }
        if ($method === 'GET' && $s === ['settings', 'general-rules-pdf']) {
            $this->downloadGeneralRulesPdf();
            return;
        }
        if ($method === 'GET' && $s === ['settings']) {
            $this->auth->requireUser();
            Support::json($this->settingsRow($this->settings()));
            return;
        }
        if ($method === 'PUT' && $s === ['settings']) {
            $this->updateSettings();
            return;
        }
        if ($method === 'POST' && $s === ['settings', 'general-rules-pdf']) {
            $this->uploadGeneralRulesPdf();
            return;
        }
        Support::json(['error' => 'Route not found'], 404);
    }

    private function routeStandings(array $s): void
    {
        if ($this->method() === 'GET' && count($s) === 3 && $s[2] === 'standings') {
            $this->getCompetitionStandings((int) $s[1]);
            return;
        }
        Support::json(['error' => 'Route not found'], 404);
    }

    private function routeNews(array $s): void
    {
        $method = $this->method();
        if ($method === 'GET' && $s === ['news']) {
            Support::json(array_map(fn ($r) => $this->newsRow($r), $this->all('SELECT * FROM news ORDER BY date DESC, id DESC')));
            return;
        }
        if ($method === 'POST' && $s === ['news']) {
            $this->createNews();
            return;
        }
        if ($method === 'PUT' && count($s) === 2) {
            $this->updateNews((int) $s[1]);
            return;
        }
        if ($method === 'POST' && count($s) === 3 && $s[2] === 'image') {
            $this->uploadNewsImage((int) $s[1]);
            return;
        }
        if ($method === 'DELETE' && count($s) === 2) {
            $this->deleteNews((int) $s[1]);
            return;
        }
        Support::json(['error' => 'Route not found'], 404);
    }

    private function routeCalendarPublic(array $s): void
    {
        if ($this->method() === 'GET' && $s === ['public', 'calendar-events']) {
            $this->calendarEvents(false);
            return;
        }
        Support::json(['error' => 'Route not found'], 404);
    }

    private function routeCalendarAdmin(array $s): void
    {
        $method = $this->method();
        if ($method === 'GET' && $s === ['admin', 'calendar-events']) {
            $this->auth->requireSuperAdmin();
            $this->calendarEvents(true);
            return;
        }
        if ($method === 'POST' && $s === ['admin', 'calendar-events']) {
            $this->createCalendarEvent();
            return;
        }
        if ($method === 'PUT' && count($s) === 3 && $s[1] === 'calendar-events') {
            $this->updateCalendarEvent((int) $s[2]);
            return;
        }
        if ($method === 'DELETE' && count($s) === 3 && $s[1] === 'calendar-events') {
            $this->deleteCalendarEvent((int) $s[2]);
            return;
        }
        Support::json(['error' => 'Route not found'], 404);
    }

    private function login(): void
    {
        $body = Support::body();
        $email = (string) ($body['email'] ?? '');
        $password = (string) ($body['password'] ?? '');

        $user = $this->one('SELECT * FROM users WHERE email = ?', [$email]);
        if (!$user) {
            Support::json(['message' => 'User not found'], 404);
            return;
        }
        if (!password_verify($password, (string) $user['password'])) {
            Support::json(['message' => 'Invalid password'], 401);
            return;
        }

        $token = Jwt::encode([
            'id' => (int) $user['id'],
            'role' => $user['role'],
            'teamId' => $user['teamId'] !== null ? (int) $user['teamId'] : null,
        ], (string) $this->config['jwt_secret'], (int) $this->config['jwt_ttl_seconds']);

        Support::json([
            'message' => 'Login successful',
            'token' => $token,
            'id' => (int) $user['id'],
            'email' => $user['email'],
            'role' => $user['role'],
            'teamId' => $user['teamId'] !== null ? (int) $user['teamId'] : null,
            'coachName' => $user['coachName'],
            'coachAge' => $user['coachAge'] !== null ? (int) $user['coachAge'] : null,
        ]);
    }

    private function getProfile(): void
    {
        $auth = $this->auth->requireUser();
        $user = $this->one('SELECT * FROM users WHERE id = ?', [$auth['id']]);
        $user ? Support::json($this->userSelect($user)) : Support::json(['error' => 'User not found'], 404);
    }

    private function getAdmins(): void
    {
        $this->auth->requireSuperAdmin();
        $admins = $this->all('SELECT * FROM users WHERE role = ? ORDER BY id ASC', ['ADMIN']);
        Support::json(array_map(fn ($r) => $this->userSelect($r), $admins));
    }

    private function createAdmin(): void
    {
        $this->auth->requireSuperAdmin();
        $body = Support::body();
        $email = Support::cleanString($body['email'] ?? null);
        $password = (string) ($body['password'] ?? '');

        if (!$email || !$password) {
            Support::json(['message' => 'Email and password are required'], 400);
            return;
        }
        if ($this->one('SELECT id FROM users WHERE email = ?', [$email])) {
            Support::json(['message' => 'User with this email already exists'], 409);
            return;
        }

        $coachAge = $this->normalizeCoachAge($body['coachAge'] ?? null);
        if ($coachAge === false) {
            Support::json(['message' => 'Invalid coachAge'], 400);
            return;
        }

        $id = $this->insert('users', [
            'email' => $email,
            'password' => password_hash($password, PASSWORD_BCRYPT, ['cost' => 10]),
            'role' => 'ADMIN',
            'teamId' => Support::intOrNull($body['teamId'] ?? null),
            'coachName' => Support::cleanString($body['coachName'] ?? null),
            'coachAge' => $coachAge,
            'createdAt' => gmdate('Y-m-d H:i:s'),
            'updatedAt' => gmdate('Y-m-d H:i:s'),
        ]);

        Support::json(['message' => 'Coach created successfully', 'admin' => $this->userSelect($this->one('SELECT * FROM users WHERE id = ?', [$id]))]);
    }

    private function updateAdmin(int $id): void
    {
        $this->auth->requireSuperAdmin();
        $existing = $this->one('SELECT * FROM users WHERE id = ? AND role = ?', [$id, 'ADMIN']);
        if (!$existing) {
            Support::json(['message' => 'Coach not found'], 404);
            return;
        }

        $body = Support::body();
        $data = [];
        if (array_key_exists('email', $body)) {
            $data['email'] = $body['email'];
        }
        if (!empty($body['password'])) {
            $data['password'] = password_hash((string) $body['password'], PASSWORD_BCRYPT, ['cost' => 10]);
        }
        if (array_key_exists('teamId', $body)) {
            $data['teamId'] = Support::intOrNull($body['teamId']);
        }
        if (array_key_exists('coachName', $body)) {
            $data['coachName'] = Support::cleanString($body['coachName']);
        }
        if (array_key_exists('coachAge', $body)) {
            $coachAge = $this->normalizeCoachAge($body['coachAge']);
            if ($coachAge === false) {
                Support::json(['error' => 'Invalid coachAge'], 400);
                return;
            }
            $data['coachAge'] = $coachAge;
        }
        $data['updatedAt'] = gmdate('Y-m-d H:i:s');

        $this->update('users', $data, 'id = ?', [$id]);
        Support::json(['message' => 'Coach updated', 'admin' => $this->userSelect($this->one('SELECT * FROM users WHERE id = ?', [$id]))]);
    }

    private function deleteAdmin(int $id): void
    {
        $this->auth->requireSuperAdmin();
        $admin = $this->one('SELECT * FROM users WHERE id = ? AND role = ?', [$id, 'ADMIN']);
        if (!$admin) {
            Support::json(['message' => 'Coach not found'], 404);
            return;
        }
        $this->run('DELETE FROM users WHERE id = ?', [$id]);
        Support::json(['message' => 'Coach removed']);
    }

    private function updateProfile(): void
    {
        $auth = $this->auth->requireUser();
        $body = Support::body();
        $data = [];
        if (!empty($body['email'])) {
            $data['email'] = $body['email'];
        }
        if (!empty($body['password'])) {
            $data['password'] = password_hash((string) $body['password'], PASSWORD_BCRYPT, ['cost' => 10]);
        }
        if (array_key_exists('coachName', $body)) {
            $data['coachName'] = Support::cleanString($body['coachName']);
        }
        if (array_key_exists('coachAge', $body)) {
            $coachAge = $this->normalizeCoachAge($body['coachAge']);
            if ($coachAge === false) {
                Support::json(['error' => 'Invalid coachAge'], 400);
                return;
            }
            $data['coachAge'] = $coachAge;
        }
        $data['updatedAt'] = gmdate('Y-m-d H:i:s');
        $this->update('users', $data, 'id = ?', [$auth['id']]);
        Support::json(['message' => 'Profile updated', 'user' => $this->userSelect($this->one('SELECT * FROM users WHERE id = ?', [$auth['id']]))]);
    }

    private function getPublicPlayers(): void
    {
        $players = $this->all('SELECT * FROM players ORDER BY fullName ASC');
        $result = [];
        foreach ($players as $row) {
            $result[] = [
                'id' => (int) $row['id'],
                'fullName' => $row['fullName'],
                'age' => $row['age'] !== null ? (int) $row['age'] : null,
                'address' => $row['address'],
                'team' => $this->teamSummary($row['teamId']),
                'contract' => $this->contractForPlayer((int) $row['id']),
            ];
        }
        Support::json($result);
    }

    private function getPrivatePlayers(): void
    {
        $this->auth->requireAdmin();
        $players = $this->all('SELECT * FROM players ORDER BY fullName ASC');
        Support::json(array_map(fn ($r) => $this->playerRow($r, true, true), $players));
    }

    private function addPlayer(): void
    {
        $user = $this->auth->requireAdmin();
        $settings = $this->settings();
        if (!$settings || !(bool) $settings['playerCreateOpen']) {
            Support::json(['error' => 'Player creation is currently disabled by SuperAdmin.'], 403);
            return;
        }
        if ($user['role'] === 'ADMIN' && !$user['teamId']) {
            Support::json(['error' => 'Admin has no team assigned.'], 403);
            return;
        }

        $body = Support::body();
        foreach (['fullName', 'phoneId', 'phoneSerie', 'efootballId'] as $field) {
            if (empty($body[$field])) {
                Support::json(['error' => 'Missing required fields.'], 400);
                return;
            }
        }

        if ($this->identifierExists('banned_players', $body['phoneId'], $body['efootballId'])) {
            Support::json(['error' => 'This player is banned. Cannot be added.'], 403);
            return;
        }
        if ($this->identifierExists('players', $body['phoneId'], $body['efootballId'])) {
            Support::json(['error' => 'A player with this Serial Number or eFootball ID already exists.'], 400);
            return;
        }

        $this->db->beginTransaction();
        try {
            $playerId = $this->insert('players', [
                'fullName' => $body['fullName'],
                'phoneId' => $body['phoneId'],
                'phoneSerie' => $body['phoneSerie'],
                'efootballId' => $body['efootballId'],
                'age' => Support::intOrNull($body['age'] ?? null),
                'address' => $body['city'] ?? null,
                'notes' => $body['notes'] ?? null,
                'teamId' => $user['role'] === 'ADMIN' ? $user['teamId'] : Support::intOrNull($body['teamId'] ?? null),
                'createdAt' => gmdate('Y-m-d H:i:s'),
                'updatedAt' => gmdate('Y-m-d H:i:s'),
            ]);
            $start = gmdate('Y-m-d H:i:s');
            $end = gmdate('Y-m-d H:i:s', strtotime('+1 year'));
            $this->insert('contracts', [
                'playerId' => $playerId,
                'startDate' => $start,
                'endDate' => $end,
                'createdAt' => gmdate('Y-m-d H:i:s'),
            ]);
            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        Support::json(['message' => 'Player and contract created.', 'player' => $this->playerRow($this->one('SELECT * FROM players WHERE id = ?', [$playerId]))], 201);
    }

    private function updatePlayer(int $id): void
    {
        $user = $this->auth->requireAdmin();
        $settings = $this->settings();
        if ($user['role'] === 'ADMIN' && !(bool) $settings['editMode']) {
            Support::json(['error' => 'Edit mode is OFF.'], 403);
            return;
        }
        $player = $this->one('SELECT * FROM players WHERE id = ?', [$id]);
        if (!$player) {
            Support::json(['error' => 'Player not found.'], 404);
            return;
        }
        if ($user['role'] === 'ADMIN' && (int) $player['teamId'] !== (int) $user['teamId']) {
            Support::json(['error' => 'You can edit only your players.'], 403);
            return;
        }

        $body = Support::body();
        $phoneId = $body['phoneId'] ?? null;
        $efootballId = $body['efootballId'] ?? null;
        if (($phoneId || $efootballId) && $this->identifierExists('banned_players', $phoneId, $efootballId)) {
            Support::json(['error' => 'This identifier belongs to a banned player.'], 403);
            return;
        }
        if (($phoneId || $efootballId) && $this->identifierExists('players', $phoneId, $efootballId, $id)) {
            Support::json(['error' => 'Identifier already used by another player.'], 400);
            return;
        }

        $data = [];
        foreach (['fullName', 'phoneId', 'phoneSerie', 'efootballId', 'notes'] as $field) {
            if (array_key_exists($field, $body)) {
                $data[$field] = $body[$field];
            }
        }
        if (array_key_exists('age', $body)) {
            $data['age'] = Support::intOrNull($body['age']);
        }
        if (array_key_exists('city', $body)) {
            $data['address'] = $body['city'] ?: null;
        }
        $data['updatedAt'] = gmdate('Y-m-d H:i:s');
        $this->update('players', $data, 'id = ?', [$id]);
        Support::json(['message' => 'Player updated.', 'player' => $this->playerRow($this->one('SELECT * FROM players WHERE id = ?', [$id]))]);
    }

    private function deletePlayer(int $id): void
    {
        $user = $this->auth->requireAdmin();
        $settings = $this->settings();
        if ($user['role'] === 'ADMIN' && !(bool) $settings['editMode']) {
            Support::json(['error' => 'Edit mode is OFF.'], 403);
            return;
        }
        $player = $this->one('SELECT * FROM players WHERE id = ?', [$id]);
        if (!$player) {
            Support::json(['error' => 'Player not found.'], 404);
            return;
        }
        if ($user['role'] === 'ADMIN' && (int) $player['teamId'] !== (int) $user['teamId']) {
            Support::json(['error' => 'Not allowed.'], 403);
            return;
        }
        $this->run('DELETE FROM transfer_history WHERE playerId = ?', [$id]);
        $this->run('DELETE FROM contracts WHERE playerId = ?', [$id]);
        $this->run('DELETE FROM players WHERE id = ?', [$id]);
        Support::json(['message' => 'Player deleted.']);
    }

    private function deletePlayerByIdentifiers(): void
    {
        $this->auth->requireSuperAdmin();
        $body = Support::body();
        if (empty($body['phoneId']) && empty($body['efootballId'])) {
            Support::json(['error' => 'Missing identifiers (phoneId or efootballId)'], 400);
            return;
        }
        $player = $this->findByIdentifiers('players', $body['phoneId'] ?? null, $body['efootballId'] ?? null);
        if (!$player) {
            Support::json(['message' => 'No player matched — nothing to delete.']);
            return;
        }
        $this->run('DELETE FROM transfer_history WHERE playerId = ?', [$player['id']]);
        $this->run('DELETE FROM contracts WHERE playerId = ?', [$player['id']]);
        $this->run('DELETE FROM players WHERE id = ?', [$player['id']]);
        Support::json(['message' => 'Player deleted due to ban.']);
    }

    private function getTeamById(int $id): void
    {
        $team = $this->one('SELECT * FROM teams WHERE id = ?', [$id]);
        if (!$team) {
            Support::json(['error' => 'Team not found'], 404);
            return;
        }
        $result = $this->teamRow($team);
        $admins = $this->all('SELECT coachName, coachAge, updatedAt FROM users WHERE teamId = ? AND role = ? ORDER BY updatedAt DESC', [$id, 'ADMIN']);
        $preferred = null;
        foreach ($admins as $admin) {
            if ($admin['coachName'] || $admin['coachAge'] !== null) {
                $preferred = $admin;
                break;
            }
        }
        $preferred ??= $admins[0] ?? null;
        $result['coach'] = $preferred ? [
            'name' => $preferred['coachName'],
            'age' => $preferred['coachAge'] !== null ? (int) $preferred['coachAge'] : null,
        ] : null;
        $result['players'] = array_map(
            fn ($r) => $this->playerRow($r, false, true),
            $this->all('SELECT * FROM players WHERE teamId = ? ORDER BY fullName ASC', [$id])
        );
        $result['matchesHome'] = array_map(
            fn ($r) => $this->matchRow($r, true),
            $this->all('SELECT * FROM matches WHERE homeTeamId = ? ORDER BY matchDate ASC', [$id])
        );
        $result['matchesAway'] = array_map(
            fn ($r) => $this->matchRow($r, true),
            $this->all('SELECT * FROM matches WHERE awayTeamId = ? ORDER BY matchDate ASC', [$id])
        );
        Support::json($result);
    }

    private function createTeam(): void
    {
        $this->auth->requireSuperAdmin();
        $body = Support::body();
        if (empty($body['name'])) {
            Support::json(['error' => 'Team name is required'], 400);
            return;
        }
        if ($this->one('SELECT id FROM teams WHERE name = ?', [$body['name']])) {
            Support::json(['error' => 'Team already exists'], 400);
            return;
        }
        $id = $this->insert('teams', [
            'name' => $body['name'],
            'fullName' => $body['fullName'] ?? null,
            'createdAt' => gmdate('Y-m-d H:i:s'),
            'updatedAt' => gmdate('Y-m-d H:i:s'),
        ]);
        Support::json(['message' => 'Team created', 'team' => $this->teamRow($this->one('SELECT * FROM teams WHERE id = ?', [$id]))], 201);
    }

    private function updateTeam(int $id): void
    {
        $this->auth->requireAdmin();
        $team = $this->one('SELECT * FROM teams WHERE id = ?', [$id]);
        if (!$team) {
            Support::json(['error' => 'Team not found'], 404);
            return;
        }
        $body = Support::body();
        $data = ['updatedAt' => gmdate('Y-m-d H:i:s')];
        if (array_key_exists('name', $body)) {
            $data['name'] = $body['name'];
        }
        if (array_key_exists('fullName', $body)) {
            $data['fullName'] = $body['fullName'];
        }
        $this->update('teams', $data, 'id = ?', [$id]);
        Support::json(['message' => 'Team updated', 'team' => $this->teamRow($this->one('SELECT * FROM teams WHERE id = ?', [$id]))]);
    }

    private function updateTeamLogo(int $id): void
    {
        $this->auth->requireAdmin();
        if (empty($_FILES['logo'])) {
            Support::json(['error' => 'No logo uploaded'], 400);
            return;
        }
        $url = $this->cloudinary->upload($_FILES['logo'], 'image', 'maegc/teams');
        $this->update('teams', ['logo' => $url, 'updatedAt' => gmdate('Y-m-d H:i:s')], 'id = ?', [$id]);
        Support::json(['message' => 'Logo updated successfully', 'team' => $this->teamRow($this->one('SELECT * FROM teams WHERE id = ?', [$id]))]);
    }

    private function deleteTeam(int $id): void
    {
        $this->auth->requireSuperAdmin();
        if (!$this->one('SELECT id FROM teams WHERE id = ?', [$id])) {
            Support::json(['error' => 'Team not found'], 404);
            return;
        }
        $players = $this->all('SELECT id FROM players WHERE teamId = ?', [$id]);
        foreach ($players as $player) {
            $this->run('DELETE FROM transfer_history WHERE playerId = ?', [$player['id']]);
            $this->run('DELETE FROM contracts WHERE playerId = ?', [$player['id']]);
        }
        $this->run('DELETE FROM players WHERE teamId = ?', [$id]);
        $this->run('DELETE FROM matches WHERE homeTeamId = ? OR awayTeamId = ?', [$id, $id]);
        $this->run('UPDATE users SET teamId = NULL WHERE teamId = ?', [$id]);
        $this->run('DELETE FROM teams WHERE id = ?', [$id]);
        Support::json(['message' => 'Team deleted successfully']);
    }

    private function createCompetition(): void
    {
        $this->auth->requireSuperAdmin();
        $body = Support::body();
        $type = $this->normalizeCompetitionType($body['type'] ?? null);
        if (empty($body['name']) || !$type) {
            Support::json(['error' => !$type ? 'Invalid competition type' : 'Missing name or type'], 400);
            return;
        }
        $id = $this->insert('competitions', [
            'name' => $body['name'],
            'type' => $type,
            'rules' => $this->normalizeRules($body['rules'] ?? null),
            'createdAt' => gmdate('Y-m-d H:i:s'),
        ]);
        Support::json($this->competitionRow($this->one('SELECT * FROM competitions WHERE id = ?', [$id])), 201);
    }

    private function updateCompetition(int $id): void
    {
        $this->auth->requireSuperAdmin();
        $body = Support::body();
        $data = [];
        if (array_key_exists('name', $body)) {
            $data['name'] = $body['name'];
        }
        if (array_key_exists('type', $body)) {
            $type = $this->normalizeCompetitionType($body['type']);
            if (!$type) {
                Support::json(['error' => 'Invalid competition type'], 400);
                return;
            }
            $data['type'] = $type;
        }
        if (array_key_exists('rules', $body)) {
            $data['rules'] = $this->normalizeRules($body['rules']);
        }
        $this->update('competitions', $data, 'id = ?', [$id]);
        Support::json(['message' => 'Competition updated']);
    }

    private function deleteCompetition(int $id): void
    {
        $this->auth->requireSuperAdmin();
        $this->run('DELETE FROM matches WHERE competitionId = ?', [$id]);
        $this->run('DELETE FROM competitions WHERE id = ?', [$id]);
        Support::noContent();
    }

    private function uploadCompetitionLogo(int $id): void
    {
        $this->auth->requireSuperAdmin();
        if (empty($_FILES['logo'])) {
            Support::json(['error' => 'No file uploaded'], 400);
            return;
        }
        $url = $this->cloudinary->upload($_FILES['logo'], 'image', 'maegc/competitions');
        $this->update('competitions', ['logo' => $url], 'id = ?', [$id]);
        Support::json(['message' => 'Competition logo updated', 'logo' => $url]);
    }

    private function uploadCompetitionRulesPdf(int $id): void
    {
        $this->auth->requireSuperAdmin();
        if (empty($_FILES['rulesPdf'])) {
            Support::json(['error' => 'No PDF uploaded'], 400);
            return;
        }
        $url = $this->storeRulesPdf($_FILES['rulesPdf']);
        $this->update('competitions', ['rulesPdfUrl' => $url], 'id = ?', [$id]);
        Support::json(['message' => 'Competition rules PDF updated', 'rulesPdfUrl' => $url]);
    }

    private function downloadCompetitionRulesPdf(int $id): void
    {
        $competition = $this->one('SELECT name, rulesPdfUrl FROM competitions WHERE id = ?', [$id]);
        if (!$competition || !$competition['rulesPdfUrl']) {
            Support::json(['error' => 'Competition rules PDF not found'], 404);
            return;
        }
        $filename = preg_replace('/[^a-z0-9_-]+/i', '-', $competition['name'] ?: 'competition') . '-rules.pdf';
        $this->sendPdfUrl((string) $competition['rulesPdfUrl'], $filename);
    }

    private function createMatch(): void
    {
        $this->auth->requireSuperAdmin();
        $body = Support::body();
        $id = $this->insert('matches', [
            'competitionId' => (int) $body['competitionId'],
            'homeTeamId' => (int) $body['homeTeamId'],
            'awayTeamId' => (int) $body['awayTeamId'],
            'matchDate' => Support::mysqlDate($body['matchDate'] ?? null),
            'referee' => $body['referee'] ?: null,
            'round' => Support::intOrNull($body['round'] ?? null),
            'homeScore' => Support::intOrNull($body['homeScore'] ?? null),
            'awayScore' => Support::intOrNull($body['awayScore'] ?? null),
            'createdAt' => gmdate('Y-m-d H:i:s'),
            'updatedAt' => gmdate('Y-m-d H:i:s'),
        ]);
        Support::json(['message' => 'Match created successfully', 'match' => $this->matchRow($this->one('SELECT * FROM matches WHERE id = ?', [$id]), true)]);
    }

    private function updateMatch(int $id): void
    {
        $this->auth->requireSuperAdmin();
        $body = Support::body();
        $data = [];
        foreach (['competitionId', 'homeTeamId', 'awayTeamId', 'round', 'homeScore', 'awayScore'] as $field) {
            if (array_key_exists($field, $body)) {
                $data[$field] = Support::intOrNull($body[$field]);
            }
        }
        if (array_key_exists('matchDate', $body)) {
            $data['matchDate'] = Support::mysqlDate($body['matchDate']);
        }
        if (array_key_exists('referee', $body)) {
            $data['referee'] = $body['referee'] ?: null;
        }
        $data['updatedAt'] = gmdate('Y-m-d H:i:s');
        $this->update('matches', $data, 'id = ?', [$id]);
        Support::json(['message' => 'Match updated successfully', 'match' => $this->matchRow($this->one('SELECT * FROM matches WHERE id = ?', [$id]), true)]);
    }

    private function deleteMatch(int $id): void
    {
        $this->auth->requireSuperAdmin();
        if (!$this->one('SELECT id FROM matches WHERE id = ?', [$id])) {
            Support::json(['message' => 'Match not found'], 404);
            return;
        }
        $this->run('DELETE FROM matches WHERE id = ?', [$id]);
        Support::noContent();
    }

    private function getMyContracts(): void
    {
        $user = $this->auth->requireAdmin();
        if (!$user['teamId']) {
            Support::json(['error' => 'Admin has no team assigned.'], 403);
            return;
        }
        $contracts = $this->all('SELECT c.* FROM contracts c JOIN players p ON p.id = c.playerId WHERE p.teamId = ? ORDER BY c.endDate ASC', [$user['teamId']]);
        Support::json(array_map(function ($contract) {
            $row = $this->contractRow($contract);
            $player = $this->one('SELECT id, fullName, position, teamId FROM players WHERE id = ?', [$contract['playerId']]);
            $row['player'] = $player ? [
                'id' => (int) $player['id'],
                'fullName' => $player['fullName'],
                'position' => $player['position'],
                'team' => $this->teamSummary($player['teamId']),
            ] : null;
            return $row;
        }, $contracts));
    }

    private function createContract(): void
    {
        $user = $this->auth->requireAdmin();
        if (!$user['teamId'] && $user['role'] !== 'SUPERADMIN') {
            Support::json(['error' => 'Admin has no team assigned.'], 403);
            return;
        }
        $body = Support::body();
        if (empty($body['playerId']) || empty($body['startDate']) || empty($body['endDate'])) {
            Support::json(['error' => 'Missing fields.'], 400);
            return;
        }
        $player = $this->one('SELECT * FROM players WHERE id = ?', [(int) $body['playerId']]);
        if (!$player) {
            Support::json(['error' => 'Player not found.'], 404);
            return;
        }
        if ($user['role'] === 'ADMIN' && (int) $player['teamId'] !== (int) $user['teamId']) {
            Support::json(['error' => 'You can create contracts only for your own players'], 403);
            return;
        }
        $id = $this->insert('contracts', [
            'playerId' => (int) $body['playerId'],
            'startDate' => Support::mysqlDate($body['startDate']),
            'endDate' => Support::mysqlDate($body['endDate']),
            'createdAt' => gmdate('Y-m-d H:i:s'),
        ]);
        Support::json(['message' => 'Contract created', 'contract' => $this->contractRow($this->one('SELECT * FROM contracts WHERE id = ?', [$id]))]);
    }

    private function updateContract(int $id): void
    {
        $user = $this->auth->requireAdmin();
        $settings = $this->settings();
        if (!(bool) $settings['editMode']) {
            Support::json(['error' => 'Edit mode is OFF. Contracts cannot be edited.'], 403);
            return;
        }
        $contract = $this->one('SELECT * FROM contracts WHERE id = ?', [$id]);
        if (!$contract) {
            Support::json(['error' => 'Contract not found'], 404);
            return;
        }
        $player = $this->one('SELECT * FROM players WHERE id = ?', [$contract['playerId']]);
        if ($user['role'] !== 'SUPERADMIN' && (int) $player['teamId'] !== (int) $user['teamId']) {
            Support::json(['error' => 'You can edit contracts only for your team players'], 403);
            return;
        }
        $body = Support::body();
        $data = [];
        if (!empty($body['startDate'])) {
            $data['startDate'] = Support::mysqlDate($body['startDate']);
        }
        if (!empty($body['endDate'])) {
            $data['endDate'] = Support::mysqlDate($body['endDate']);
        }
        if (!$data) {
            Support::json(['error' => 'Nothing to update (startDate/endDate missing)'], 400);
            return;
        }
        $this->update('contracts', $data, 'id = ?', [$id]);
        Support::json(['message' => 'Contract updated', 'updated' => $this->contractRow($this->one('SELECT * FROM contracts WHERE id = ?', [$id]))]);
    }

    private function downloadPlayerContractPdf(int $playerId): void
    {
        $user = $this->auth->requireAdmin();
        $contract = $this->one('SELECT * FROM contracts WHERE playerId = ?', [$playerId]);
        $player = $this->one('SELECT * FROM players WHERE id = ?', [$playerId]);
        if (!$contract || !$player) {
            Support::json(['error' => 'Contract/Player not found'], 404);
            return;
        }
        if ($user['role'] === 'ADMIN' && (int) $player['teamId'] !== (int) $user['teamId']) {
            Support::json(['error' => 'Forbidden'], 403);
            return;
        }
        $team = $player['teamId'] ? ($this->one('SELECT * FROM teams WHERE id = ?', [$player['teamId']]) ?: []) : [];
        $pdf = Pdf::simpleContract($this->playerRow($player), $this->teamRow($team), $this->contractRow($contract));
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="contract_player_' . $playerId . '.pdf"');
        echo $pdf;
    }

    private function transferPlayer(): void
    {
        $this->auth->requireSuperAdmin();
        $body = Support::body();
        if (empty($body['playerId']) || empty($body['newTeamId']) || empty($body['newContractStart']) || empty($body['newContractEnd'])) {
            Support::json(['error' => 'Missing required fields'], 400);
            return;
        }
        $settings = $this->settings();
        if (!$settings || !(bool) $settings['mercatoOpen']) {
            Support::json(['error' => 'Mercato is CLOSED'], 403);
            return;
        }
        $player = $this->one('SELECT * FROM players WHERE id = ?', [(int) $body['playerId']]);
        if (!$player) {
            Support::json(['error' => 'Player not found'], 404);
            return;
        }
        if (!$this->one('SELECT id FROM teams WHERE id = ?', [(int) $body['newTeamId']])) {
            Support::json(['error' => 'New team not found'], 404);
            return;
        }
        $this->db->beginTransaction();
        try {
            $this->run('DELETE FROM contracts WHERE playerId = ?', [$player['id']]);
            $this->update('players', ['teamId' => (int) $body['newTeamId'], 'updatedAt' => gmdate('Y-m-d H:i:s')], 'id = ?', [$player['id']]);
            $this->insert('contracts', [
                'playerId' => (int) $player['id'],
                'startDate' => Support::mysqlDate($body['newContractStart']),
                'endDate' => Support::mysqlDate($body['newContractEnd']),
                'createdAt' => gmdate('Y-m-d H:i:s'),
            ]);
            $this->insert('transfer_history', [
                'playerId' => (int) $player['id'],
                'fromTeamId' => $player['teamId'],
                'toTeamId' => (int) $body['newTeamId'],
                'date' => gmdate('Y-m-d H:i:s'),
            ]);
            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
        Support::json(['message' => 'Player transferred successfully', 'player' => $this->playerRow($this->one('SELECT * FROM players WHERE id = ?', [$player['id']]))]);
    }

    private function banExistingPlayer(): void
    {
        $this->auth->requireSuperAdmin();
        $body = Support::body();
        if (empty($body['playerId'])) {
            Support::json(['error' => 'Missing playerId'], 400);
            return;
        }
        $player = $this->one('SELECT * FROM players WHERE id = ?', [(int) $body['playerId']]);
        if (!$player) {
            Support::json(['error' => 'Player not found'], 404);
            return;
        }
        $this->insert('banned_players', [
            'phoneId' => $player['phoneId'],
            'phoneSerie' => $player['phoneSerie'],
            'efootballId' => $player['efootballId'],
            'reason' => $body['reason'] ?? null,
            'dateAdded' => gmdate('Y-m-d H:i:s'),
        ]);
        $this->run('DELETE FROM transfer_history WHERE playerId = ?', [$player['id']]);
        $this->run('DELETE FROM contracts WHERE playerId = ?', [$player['id']]);
        $this->run('DELETE FROM players WHERE id = ?', [$player['id']]);
        Support::json(['message' => 'Player banned & removed successfully']);
    }

    private function addBannedPlayer(): void
    {
        $this->auth->requireSuperAdmin();
        $body = Support::body();
        if (empty($body['phoneId']) && empty($body['efootballId'])) {
            Support::json(['error' => 'Provide phoneId or efootballId'], 400);
            return;
        }
        if ($this->identifierExists('banned_players', $body['phoneId'] ?? null, $body['efootballId'] ?? null)) {
            Support::json(['error' => 'Identifiers already banned'], 400);
            return;
        }
        $this->insert('banned_players', [
            'phoneId' => $body['phoneId'] ?? null,
            'efootballId' => $body['efootballId'] ?? null,
            'reason' => $body['reason'] ?? ('Banned: ' . ($body['fullName'] ?? 'Unknown')),
            'dateAdded' => gmdate('Y-m-d H:i:s'),
        ]);
        $player = $this->findByIdentifiers('players', $body['phoneId'] ?? null, $body['efootballId'] ?? null);
        if ($player) {
            $this->run('DELETE FROM transfer_history WHERE playerId = ?', [$player['id']]);
            $this->run('DELETE FROM contracts WHERE playerId = ?', [$player['id']]);
            $this->run('DELETE FROM players WHERE id = ?', [$player['id']]);
        }
        Support::json(['message' => 'Player banned successfully.']);
    }

    private function removeBannedPlayer(int $id): void
    {
        $this->auth->requireSuperAdmin();
        if (!$this->one('SELECT id FROM banned_players WHERE id = ?', [$id])) {
            Support::json(['error' => 'Banned record not found'], 404);
            return;
        }
        $this->run('DELETE FROM banned_players WHERE id = ?', [$id]);
        Support::json(['message' => 'Banned entry removed.']);
    }

    private function updateSettings(): void
    {
        $this->auth->requireSuperAdmin();
        $body = Support::body();
        $current = $this->settings();
        $data = [];
        foreach (['editMode', 'mercatoOpen', 'playerCreateOpen'] as $field) {
            if (array_key_exists($field, $body)) {
                $data[$field] = Support::boolValue($body[$field]) ? 1 : 0;
            } else {
                $data[$field] = $current[$field];
            }
        }
        if (array_key_exists('generalRulesPdfUrl', $body)) {
            $data['generalRulesPdfUrl'] = $body['generalRulesPdfUrl'];
        }
        $this->update('settings', $data, 'id = ?', [$current['id']]);
        Support::json(['message' => 'Settings updated successfully', 'settings' => $this->settingsRow($this->settings())]);
    }

    private function uploadGeneralRulesPdf(): void
    {
        $this->auth->requireSuperAdmin();
        if (empty($_FILES['generalRulesPdf'])) {
            Support::json(['error' => 'No PDF uploaded'], 400);
            return;
        }
        $url = $this->storeRulesPdf($_FILES['generalRulesPdf']);
        $this->update('settings', ['generalRulesPdfUrl' => $url], 'id = ?', [$this->settings()['id']]);
        Support::json(['message' => 'General rules PDF updated', 'generalRulesPdfUrl' => $url]);
    }

    private function downloadGeneralRulesPdf(): void
    {
        $settings = $this->settings();
        if (empty($settings['generalRulesPdfUrl'])) {
            Support::json(['error' => 'Rules PDF not found'], 404);
            return;
        }
        $this->sendPdfUrl((string) $settings['generalRulesPdfUrl'], 'general-rules.pdf');
    }

    private function getCompetitionStandings(int $competitionId): void
    {
        $matches = $this->all('SELECT * FROM matches WHERE competitionId = ?', [$competitionId]);
        $table = [];
        foreach ($matches as $match) {
            $home = $this->teamSummary($match['homeTeamId']);
            $away = $this->teamSummary($match['awayTeamId']);
            if (!$home || !$away) {
                continue;
            }
            foreach ([$home, $away] as $team) {
                if (!isset($table[$team['id']])) {
                    $table[$team['id']] = [
                        'id' => $team['id'], 'name' => $team['name'],
                        'P' => 0, 'W' => 0, 'D' => 0, 'L' => 0, 'F' => 0, 'A' => 0, 'GD' => 0, 'Pts' => 0,
                    ];
                }
            }
            if ($match['homeScore'] === null || $match['awayScore'] === null) {
                continue;
            }
            $h = &$table[$home['id']];
            $a = &$table[$away['id']];
            $hs = (int) $match['homeScore'];
            $as = (int) $match['awayScore'];
            $h['P']++; $a['P']++;
            $h['F'] += $hs; $h['A'] += $as;
            $a['F'] += $as; $a['A'] += $hs;
            if ($hs > $as) {
                $h['W']++; $a['L']++; $h['Pts'] += 3;
            } elseif ($hs < $as) {
                $a['W']++; $h['L']++; $a['Pts'] += 3;
            } else {
                $h['D']++; $a['D']++; $h['Pts']++; $a['Pts']++;
            }
            unset($h, $a);
        }
        foreach ($table as &$team) {
            $team['GD'] = $team['F'] - $team['A'];
        }
        unset($team);
        $standings = array_values($table);
        usort($standings, function ($a, $b) {
            if ($b['Pts'] !== $a['Pts']) {
                return $b['Pts'] <=> $a['Pts'];
            }
            if ($b['GD'] !== $a['GD']) {
                return $b['GD'] <=> $a['GD'];
            }
            return $b['F'] <=> $a['F'];
        });
        Support::json($standings);
    }

    private function createNews(): void
    {
        $this->auth->requireSuperAdmin();
        $body = Support::body();
        $title = Support::cleanString($body['title'] ?? null);
        $text = Support::cleanString($body['text'] ?? null);
        $date = Support::mysqlDate($body['date'] ?? 'now');
        if (!$title || !$text) {
            Support::json(['error' => 'Title and text are required'], 400);
            return;
        }
        if (!$date) {
            Support::json(['error' => 'Invalid news date'], 400);
            return;
        }
        $id = $this->insert('news', [
            'title' => $title,
            'text' => $text,
            'date' => $date,
            'createdAt' => gmdate('Y-m-d H:i:s'),
            'updatedAt' => gmdate('Y-m-d H:i:s'),
        ]);
        Support::json($this->newsRow($this->one('SELECT * FROM news WHERE id = ?', [$id])), 201);
    }

    private function updateNews(int $id): void
    {
        $this->auth->requireSuperAdmin();
        $body = Support::body();
        $data = [];
        if (array_key_exists('title', $body)) {
            $title = Support::cleanString($body['title']);
            if (!$title) {
                Support::json(['error' => 'Title is required'], 400);
                return;
            }
            $data['title'] = $title;
        }
        if (array_key_exists('text', $body)) {
            $text = Support::cleanString($body['text']);
            if (!$text) {
                Support::json(['error' => 'Text is required'], 400);
                return;
            }
            $data['text'] = $text;
        }
        if (array_key_exists('date', $body)) {
            $date = Support::mysqlDate($body['date']);
            if (!$date) {
                Support::json(['error' => 'Invalid news date'], 400);
                return;
            }
            $data['date'] = $date;
        }
        $data['updatedAt'] = gmdate('Y-m-d H:i:s');
        $this->update('news', $data, 'id = ?', [$id]);
        Support::json($this->newsRow($this->one('SELECT * FROM news WHERE id = ?', [$id])));
    }

    private function uploadNewsImage(int $id): void
    {
        $this->auth->requireSuperAdmin();
        if (empty($_FILES['image'])) {
            Support::json(['error' => 'No image uploaded'], 400);
            return;
        }
        $url = $this->cloudinary->upload($_FILES['image'], 'image', 'maegc/news');
        $this->update('news', ['image' => $url, 'updatedAt' => gmdate('Y-m-d H:i:s')], 'id = ?', [$id]);
        Support::json(['message' => 'News image updated', 'news' => $this->newsRow($this->one('SELECT * FROM news WHERE id = ?', [$id]))]);
    }

    private function deleteNews(int $id): void
    {
        $this->auth->requireSuperAdmin();
        $this->run('DELETE FROM news WHERE id = ?', [$id]);
        Support::noContent();
    }

    private function calendarEvents(bool $admin): void
    {
        $where = [];
        $params = [];
        if (!empty($_GET['from'])) {
            $where[] = 'startDate >= ?';
            $params[] = Support::mysqlDate($_GET['from']);
        }
        if (!empty($_GET['to'])) {
            $where[] = 'startDate <= ?';
            $params[] = Support::mysqlDate($_GET['to']);
        }
        $sql = 'SELECT * FROM calendar_events';
        if ($where) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY startDate ASC';
        Support::json(array_map(fn ($r) => $this->calendarRow($r), $this->all($sql, $params)));
    }

    private function createCalendarEvent(): void
    {
        $user = $this->auth->requireSuperAdmin();
        $body = Support::body();
        if (empty($body['title']) || empty($body['startDate'])) {
            Support::json(['message' => 'title and startDate are required'], 400);
            return;
        }
        $id = $this->insert('calendar_events', [
            'title' => $body['title'],
            'details' => $body['details'] ?? null,
            'startDate' => Support::mysqlDate($body['startDate']),
            'endDate' => Support::mysqlDate($body['endDate'] ?? null),
            'userId' => $user['id'],
            'createdAt' => gmdate('Y-m-d H:i:s'),
        ]);
        Support::json($this->calendarRow($this->one('SELECT * FROM calendar_events WHERE id = ?', [$id])), 201);
    }

    private function updateCalendarEvent(int $id): void
    {
        $this->auth->requireSuperAdmin();
        $body = Support::body();
        $data = [];
        if (array_key_exists('title', $body)) {
            $data['title'] = $body['title'];
        }
        if (array_key_exists('details', $body)) {
            $data['details'] = $body['details'] ?: null;
        }
        if (array_key_exists('startDate', $body)) {
            $data['startDate'] = Support::mysqlDate($body['startDate']);
        }
        if (array_key_exists('endDate', $body)) {
            $data['endDate'] = Support::mysqlDate($body['endDate']);
        }
        $this->update('calendar_events', $data, 'id = ?', [$id]);
        Support::json($this->calendarRow($this->one('SELECT * FROM calendar_events WHERE id = ?', [$id])));
    }

    private function deleteCalendarEvent(int $id): void
    {
        $this->auth->requireSuperAdmin();
        $this->run('DELETE FROM calendar_events WHERE id = ?', [$id]);
        Support::json(['success' => true]);
    }

    private function respondMatches(string $sql, array $params = []): void
    {
        Support::json(array_map(fn ($r) => $this->matchRow($r, true), $this->all($sql, $params)));
    }

    private function settings(): array
    {
        $settings = $this->one('SELECT * FROM settings ORDER BY id ASC LIMIT 1');
        if (!$settings) {
            $this->insert('settings', ['id' => 1, 'editMode' => 0, 'mercatoOpen' => 0, 'playerCreateOpen' => 0]);
            $settings = $this->one('SELECT * FROM settings WHERE id = 1');
        }
        return $settings;
    }

    private function normalizeCoachAge(mixed $value): int|null|false
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (filter_var($value, FILTER_VALIDATE_INT) === false || (int) $value < 0) {
            return false;
        }
        return (int) $value;
    }

    private function normalizeCompetitionType(mixed $type): ?string
    {
        $type = (string) ($type ?? '');
        $map = ['SUPERCUP' => 'SUPER_CUP', 'FRIENDLY_TOURNAMENT' => 'FRIENDLY'];
        $type = $map[$type] ?? $type;
        return in_array($type, ['LEAGUE', 'CUP', 'SUPER_CUP', 'FRIENDLY'], true) ? $type : null;
    }

    private function normalizeRules(mixed $rules): ?string
    {
        if ($rules === null) {
            return null;
        }
        $value = trim((string) $rules);
        return $value === '' ? null : $value;
    }

    private function storeRulesPdf(array $file): string
    {
        if (empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
            throw new \RuntimeException('No PDF file provided');
        }
        $name = (string) ($file['name'] ?? 'rules.pdf');
        if (!str_ends_with(strtolower($name), '.pdf')) {
            throw new \RuntimeException('Only PDF files are allowed');
        }
        $dir = dirname(__DIR__) . '/public/uploads/rules';
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $filename = Support::safeFilename($name, 'rules', 'pdf');
        $target = $dir . '/' . $filename;
        if (!move_uploaded_file($file['tmp_name'], $target)) {
            throw new \RuntimeException('Could not store PDF');
        }
        return Support::publicApiBase($this->config) . '/uploads/rules/' . rawurlencode($filename);
    }

    private function sendPdfUrl(string $url, string $filename): void
    {
        $path = $this->localUploadPath($url);
        if ($path && is_file($path)) {
            header('Content-Type: application/pdf');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            readfile($path);
            return;
        }

        $data = $this->fetchRemote($url);
        if ($data === null && str_contains($url, 'res.cloudinary.com') && str_contains($url, '/raw/upload/')) {
            $data = $this->fetchRemote(str_replace('/raw/upload/', '/raw/upload/fl_attachment/', $url));
        }
        if ($data === null) {
            Support::json(['error' => 'Could not download rules PDF'], 502);
            return;
        }
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        echo $data;
    }

    private function localUploadPath(string $url): ?string
    {
        $marker = '/uploads/rules/';
        $path = parse_url($url, PHP_URL_PATH) ?: '';
        $pos = strpos($path, $marker);
        if ($pos === false) {
            return null;
        }
        $filename = basename(urldecode(substr($path, $pos + strlen($marker))));
        return dirname(__DIR__) . '/public/uploads/rules/' . $filename;
    }

    private function fetchRemote(string $url): ?string
    {
        if (!preg_match('/^https?:\/\//i', $url)) {
            return null;
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 60,
        ]);
        $data = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return ($data !== false && $status >= 200 && $status < 300) ? (string) $data : null;
    }

    private function identifierExists(string $table, mixed $phoneId, mixed $efootballId, ?int $excludeId = null): bool
    {
        return (bool) $this->findByIdentifiers($table, $phoneId, $efootballId, $excludeId);
    }

    private function findByIdentifiers(string $table, mixed $phoneId, mixed $efootballId, ?int $excludeId = null): ?array
    {
        $clauses = [];
        $params = [];
        if ($phoneId) {
            $clauses[] = 'phoneId = ?';
            $params[] = $phoneId;
        }
        if ($efootballId) {
            $clauses[] = 'efootballId = ?';
            $params[] = $efootballId;
        }
        if (!$clauses) {
            return null;
        }
        $sql = 'SELECT * FROM ' . $table . ' WHERE (' . implode(' OR ', $clauses) . ')';
        if ($excludeId !== null) {
            $sql .= ' AND id <> ?';
            $params[] = $excludeId;
        }
        $sql .= ' LIMIT 1';
        return $this->one($sql, $params);
    }

    private function userSelect(?array $row): ?array
    {
        if (!$row) {
            return null;
        }
        return [
            'id' => (int) $row['id'],
            'email' => $row['email'],
            'role' => $row['role'],
            'teamId' => $row['teamId'] !== null ? (int) $row['teamId'] : null,
            'coachName' => $row['coachName'],
            'coachAge' => $row['coachAge'] !== null ? (int) $row['coachAge'] : null,
            'team' => $this->teamSummary($row['teamId']),
        ];
    }

    private function teamSummary(mixed $teamId): ?array
    {
        if ($teamId === null || $teamId === '') {
            return null;
        }
        $team = $this->one('SELECT id, name FROM teams WHERE id = ?', [(int) $teamId]);
        return $team ? ['id' => (int) $team['id'], 'name' => $team['name']] : null;
    }

    private function teamRow(array $row): array
    {
        return [
            'id' => isset($row['id']) ? (int) $row['id'] : null,
            'name' => $row['name'] ?? null,
            'fullName' => $row['fullName'] ?? null,
            'logo' => $row['logo'] ?? null,
            'createdAt' => Support::isoDate($row['createdAt'] ?? null),
            'updatedAt' => Support::isoDate($row['updatedAt'] ?? null),
        ];
    }

    private function playerRow(array $row, bool $includeTeam = false, bool $includeContract = false): array
    {
        $player = [
            'id' => (int) $row['id'],
            'fullName' => $row['fullName'],
            'photo' => $row['photo'],
            'age' => $row['age'] !== null ? (int) $row['age'] : null,
            'position' => $row['position'],
            'phoneId' => $row['phoneId'],
            'efootballId' => $row['efootballId'],
            'phoneSerie' => $row['phoneSerie'],
            'cin' => $row['cin'],
            'phone' => $row['phone'],
            'address' => $row['address'],
            'salary' => $row['salary'] !== null ? (int) $row['salary'] : null,
            'notes' => $row['notes'],
            'teamId' => $row['teamId'] !== null ? (int) $row['teamId'] : null,
            'banned' => (bool) $row['banned'],
            'createdAt' => Support::isoDate($row['createdAt']),
            'updatedAt' => Support::isoDate($row['updatedAt']),
        ];
        if ($includeTeam) {
            $player['team'] = $this->teamSummary($row['teamId']);
        }
        if ($includeContract) {
            $player['contract'] = $this->contractForPlayer((int) $row['id']);
        }
        return $player;
    }

    private function contractForPlayer(int $playerId): ?array
    {
        $contract = $this->one('SELECT * FROM contracts WHERE playerId = ?', [$playerId]);
        return $contract ? $this->contractRow($contract) : null;
    }

    private function contractRow(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'playerId' => (int) $row['playerId'],
            'startDate' => Support::isoDate($row['startDate']),
            'endDate' => Support::isoDate($row['endDate']),
            'createdAt' => Support::isoDate($row['createdAt']),
        ];
    }

    private function competitionRow(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'name' => $row['name'],
            'type' => $row['type'],
            'logo' => $row['logo'],
            'rules' => $row['rules'],
            'rulesPdfUrl' => $row['rulesPdfUrl'],
            'createdAt' => Support::isoDate($row['createdAt']),
        ];
    }

    private function matchRow(array $row, bool $include = false): array
    {
        $match = [
            'id' => (int) $row['id'],
            'competitionId' => (int) $row['competitionId'],
            'homeTeamId' => (int) $row['homeTeamId'],
            'awayTeamId' => (int) $row['awayTeamId'],
            'round' => $row['round'] !== null ? (int) $row['round'] : null,
            'matchDate' => Support::isoDate($row['matchDate']),
            'referee' => $row['referee'],
            'homeScore' => $row['homeScore'] !== null ? (int) $row['homeScore'] : null,
            'awayScore' => $row['awayScore'] !== null ? (int) $row['awayScore'] : null,
            'createdAt' => Support::isoDate($row['createdAt']),
            'updatedAt' => Support::isoDate($row['updatedAt']),
        ];
        if ($include) {
            $match['homeTeam'] = $this->teamSummary($row['homeTeamId']);
            $match['awayTeam'] = $this->teamSummary($row['awayTeamId']);
            $competition = $this->one('SELECT * FROM competitions WHERE id = ?', [$row['competitionId']]);
            $match['competition'] = $competition ? $this->competitionRow($competition) : null;
        }
        return $match;
    }

    private function bannedRow(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'phoneId' => $row['phoneId'],
            'phoneSerie' => $row['phoneSerie'] ?? null,
            'efootballId' => $row['efootballId'],
            'reason' => $row['reason'],
            'dateAdded' => Support::isoDate($row['dateAdded']),
        ];
    }

    private function settingsRow(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'editMode' => (bool) $row['editMode'],
            'mercatoOpen' => (bool) $row['mercatoOpen'],
            'playerCreateOpen' => (bool) $row['playerCreateOpen'],
            'generalRulesPdfUrl' => $row['generalRulesPdfUrl'],
        ];
    }

    private function calendarRow(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'title' => $row['title'],
            'details' => $row['details'],
            'startDate' => Support::isoDate($row['startDate']),
            'endDate' => Support::isoDate($row['endDate']),
            'createdAt' => Support::isoDate($row['createdAt']),
            'userId' => $row['userId'] !== null ? (int) $row['userId'] : null,
        ];
    }

    private function newsRow(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'title' => $row['title'],
            'text' => $row['text'],
            'image' => $row['image'],
            'date' => Support::isoDate($row['date']),
            'createdAt' => Support::isoDate($row['createdAt']),
            'updatedAt' => Support::isoDate($row['updatedAt']),
        ];
    }

    private function one(string $sql, array $params = []): ?array
    {
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    private function all(string $sql, array $params = []): array
    {
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    private function value(string $sql, array $params = []): mixed
    {
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchColumn();
    }

    private function run(string $sql, array $params = []): void
    {
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
    }

    private function insert(string $table, array $data): int
    {
        $columns = array_keys($data);
        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $table,
            implode(', ', array_map(fn ($c) => "`$c`", $columns)),
            implode(', ', array_fill(0, count($columns), '?'))
        );
        $this->run($sql, array_values($data));
        return (int) $this->db->lastInsertId();
    }

    private function update(string $table, array $data, string $where, array $params): void
    {
        if (!$data) {
            return;
        }
        $sets = implode(', ', array_map(fn ($c) => "`$c` = ?", array_keys($data)));
        $sql = "UPDATE $table SET $sets WHERE $where";
        $this->run($sql, array_merge(array_values($data), $params));
    }
}
