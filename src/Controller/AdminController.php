<?php

namespace App\Controller;

use App\Repository\GameRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMIN')]
#[Route('/admin', name: 'app_admin_')]
class AdminController extends AbstractController
{
    // ── Overview ──────────────────────────────────────────────────────────────

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(
        UserRepository $userRepository,
        GameRepository $gameRepository,
        EntityManagerInterface $em,
    ): Response {
        $conn = $em->getConnection();

        $totalUsers    = $userRepository->count([]);
        $verifiedCount = (int) $conn->fetchOne('SELECT COUNT(*) FROM user WHERE is_verified = 1');
        $games         = $gameRepository->findBy([], ['createdAt' => 'DESC']);
        $totalGuesses  = (int) $conn->fetchOne('SELECT COUNT(*) FROM guess');
        $recentResets  = (int) $conn->fetchOne(
            "SELECT COUNT(*) FROM reset_password_request WHERE requested_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)"
        );

        $gamesByStatus = [];
        foreach ($games as $game) {
            $s = $game->getStatus()->value;
            $gamesByStatus[$s] = ($gamesByStatus[$s] ?? 0) + 1;
        }

        $avgGuessesPerGame = count($games) > 0 ? round($totalGuesses / count($games), 1) : 0;

        $gamesTrend = $this->buildTrend(
            $conn->fetchAllAssociative(
                "SELECT DATE(created_at) as day, COUNT(*) as cnt
                 FROM game WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
                 GROUP BY DATE(created_at) ORDER BY day ASC"
            )
        );

        $guessesTrend = $this->buildTrend(
            $conn->fetchAllAssociative(
                "SELECT DATE(created_at) as day, COUNT(*) as cnt
                 FROM guess WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
                 GROUP BY DATE(created_at) ORDER BY day ASC"
            )
        );

        $recentGuesses = $conn->fetchAllAssociative(
            "SELECT g.player_name, g.created_at,
                    COALESCE(gm.title, CONCAT('Baby ', u.last_name)) as display_title,
                    gm.token, gm.slug
             FROM guess g
             JOIN game gm ON g.game_id = gm.id
             JOIN user u ON gm.user_id = u.id
             ORDER BY g.created_at DESC LIMIT 10"
        );

        $recentGames = $conn->fetchAllAssociative(
            "SELECT gm.status, gm.created_at,
                    COALESCE(gm.title, CONCAT('Baby ', u.last_name)) as display_title,
                    gm.token, gm.slug,
                    u.first_name, u.last_name,
                    COUNT(g.id) as guess_count
             FROM game gm
             JOIN user u ON gm.user_id = u.id
             LEFT JOIN guess g ON g.game_id = gm.id
             GROUP BY gm.id
             ORDER BY gm.created_at DESC LIMIT 8"
        );

        return $this->render('admin/index.html.twig', [
            'totalUsers'        => $totalUsers,
            'verifiedCount'     => $verifiedCount,
            'totalGames'        => count($games),
            'totalGuesses'      => $totalGuesses,
            'gamesByStatus'     => $gamesByStatus,
            'avgGuessesPerGame' => $avgGuessesPerGame,
            'recentResets'      => $recentResets,
            'gamesTrend'        => $gamesTrend,
            'guessesTrend'      => $guessesTrend,
            'recentGuesses'     => $recentGuesses,
            'recentGames'       => $recentGames,
        ]);
    }

    // ── Users ─────────────────────────────────────────────────────────────────

    #[Route('/users', name: 'users', methods: ['GET'])]
    public function users(
        UserRepository $userRepository,
        EntityManagerInterface $em,
    ): Response {
        $users         = $userRepository->findBy([], ['id' => 'DESC']);
        $totalVerified = (int) $em->getConnection()->fetchOne('SELECT COUNT(*) FROM user WHERE is_verified = 1');

        $totalAdmins = 0;
        foreach ($users as $user) {
            if (in_array('ROLE_ADMIN', $user->getRoles())) {
                ++$totalAdmins;
            }
        }

        return $this->render('admin/users.html.twig', [
            'users'         => $users,
            'totalVerified' => $totalVerified,
            'totalAdmins'   => $totalAdmins,
        ]);
    }

    // ── Games ─────────────────────────────────────────────────────────────────

    #[Route('/games', name: 'games', methods: ['GET'])]
    public function games(
        GameRepository $gameRepository,
        EntityManagerInterface $em,
    ): Response {
        $conn  = $em->getConnection();
        $games = $gameRepository->findBy([], ['createdAt' => 'DESC']);

        $gamesByStatus = [];
        foreach ($games as $game) {
            $s = $game->getStatus()->value;
            $gamesByStatus[$s] = ($gamesByStatus[$s] ?? 0) + 1;
        }

        $totalGuesses = (int) $conn->fetchOne('SELECT COUNT(*) FROM guess');
        $avgGuesses   = count($games) > 0 ? round($totalGuesses / count($games), 1) : 0;

        $catStats = $conn->fetchAssociative(
            "SELECT
                SUM(guess_gender) as gender,
                SUM(guess_name)   as name,
                SUM(guess_date)   as date_cat,
                SUM(guess_weight) as weight,
                SUM(guess_height) as height
             FROM game"
        ) ?: [];

        return $this->render('admin/games.html.twig', [
            'games'         => $games,
            'gamesByStatus' => $gamesByStatus,
            'totalGuesses'  => $totalGuesses,
            'avgGuesses'    => $avgGuesses,
            'catStats'      => $catStats,
        ]);
    }

    // ── Activity logs ─────────────────────────────────────────────────────────

    #[Route('/logs', name: 'logs', methods: ['GET'])]
    public function logs(EntityManagerInterface $em): Response
    {
        $conn = $em->getConnection();

        $recentGuesses = $conn->fetchAllAssociative(
            "SELECT g.player_name, g.player_email, g.created_at,
                    COALESCE(gm.title, CONCAT('Baby ', u.last_name)) as display_title,
                    gm.token, gm.slug, u.first_name, u.last_name
             FROM guess g
             JOIN game gm ON g.game_id = gm.id
             JOIN user u ON gm.user_id = u.id
             ORDER BY g.created_at DESC LIMIT 50"
        );

        $recentGames = $conn->fetchAllAssociative(
            "SELECT gm.status, gm.created_at,
                    COALESCE(gm.title, CONCAT('Baby ', u.last_name)) as display_title,
                    gm.token, gm.slug, u.first_name, u.last_name, u.email,
                    COUNT(g.id) as guess_count
             FROM game gm
             JOIN user u ON gm.user_id = u.id
             LEFT JOIN guess g ON g.game_id = gm.id
             GROUP BY gm.id
             ORDER BY gm.created_at DESC LIMIT 30"
        );

        $recentReveals = $conn->fetchAllAssociative(
            "SELECT gm.updated_at, gm.answer_gender, gm.answer_name,
                    COALESCE(gm.title, CONCAT('Baby ', u.last_name)) as display_title,
                    gm.token, gm.slug, u.first_name, u.last_name,
                    COUNT(g.id) as guess_count
             FROM game gm
             JOIN user u ON gm.user_id = u.id
             LEFT JOIN guess g ON g.game_id = gm.id
             WHERE gm.status = 'revealed'
             GROUP BY gm.id
             ORDER BY gm.updated_at DESC LIMIT 30"
        );

        $errorLogs = $conn->fetchAllAssociative(
            "SELECT status_code, url, message, user_email, created_at
             FROM app_error_log
             ORDER BY created_at DESC LIMIT 100"
        );

        $errorStats = $conn->fetchAllAssociative(
            "SELECT status_code, COUNT(*) as cnt
             FROM app_error_log
             WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
             GROUP BY status_code
             ORDER BY cnt DESC"
        );

        return $this->render('admin/logs.html.twig', [
            'recentGuesses' => $recentGuesses,
            'recentGames'   => $recentGames,
            'recentReveals' => $recentReveals,
            'errorLogs'     => $errorLogs,
            'errorStats'    => $errorStats,
        ]);
    }

    // ── Security ──────────────────────────────────────────────────────────────

    #[Route('/security', name: 'security', methods: ['GET'])]
    public function security(
        UserRepository $userRepository,
        EntityManagerInterface $em,
    ): Response {
        $conn = $em->getConnection();

        $resetRequests = $conn->fetchAllAssociative(
            "SELECT r.requested_at, r.expires_at,
                    u.email, u.first_name, u.last_name
             FROM reset_password_request r
             JOIN user u ON r.user_id = u.id
             ORDER BY r.requested_at DESC LIMIT 50"
        );

        $unverifiedUsers = $conn->fetchAllAssociative(
            "SELECT id, email, first_name, last_name
             FROM user WHERE is_verified = 0
             ORDER BY id DESC"
        );

        $adminUsers = [];
        foreach ($userRepository->findAll() as $user) {
            if (in_array('ROLE_ADMIN', $user->getRoles())) {
                $adminUsers[] = $user;
            }
        }

        $totalResets30d = (int) $conn->fetchOne(
            "SELECT COUNT(*) FROM reset_password_request WHERE requested_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)"
        );
        $expiredResets = (int) $conn->fetchOne(
            "SELECT COUNT(*) FROM reset_password_request WHERE expires_at < NOW()"
        );

        return $this->render('admin/security.html.twig', [
            'resetRequests'   => $resetRequests,
            'unverifiedUsers' => $unverifiedUsers,
            'adminUsers'      => $adminUsers,
            'totalResets30d'  => $totalResets30d,
            'expiredResets'   => $expiredResets,
        ]);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function buildTrend(array $rawData, int $days = 7): array
    {
        $trend = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $day = (new \DateTimeImmutable("-{$i} days"))->format('Y-m-d');
            $trend[$day] = 0;
        }
        foreach ($rawData as $row) {
            $day = substr((string) $row['day'], 0, 10);
            if (isset($trend[$day])) {
                $trend[$day] = (int) $row['cnt'];
            }
        }

        return $trend;
    }
}
