<?php

namespace App\Controller;

use App\Entity\ModerationLog;
use App\Entity\User;
use App\Enum\ModerationAction;
use App\Repository\GameRepository;
use App\Repository\ModerationLogRepository;
use App\Repository\UserRepository;
use App\Service\ModerationLogger;
use App\Service\UserAccountRemover;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMIN')]
#[Route('/admin', name: 'app_admin_')]
class AdminUserController extends AbstractController
{
    #[Route('/users', name: 'users', methods: ['GET'])]
    public function index(Request $request, UserRepository $users): Response
    {
        $filters = [
            'q'        => trim($request->query->getString('q')),
            'verified' => $request->query->getString('verified'),   // '' | '1' | '0'
            'role'     => $request->query->getString('role'),       // '' | 'admin' | 'user'
            'sort'     => $request->query->getString('sort', 'id'),
            'dir'      => $request->query->getString('dir', 'desc'),
        ];

        $page = $users->searchForAdmin(
            $filters['q'],
            match ($filters['verified']) { '1' => true, '0' => false, default => null },
            match ($filters['role']) { 'admin' => true, 'user' => false, default => null },
            $filters['sort'],
            $filters['dir'],
            $request->query->getInt('page', 1),
        );

        return $this->render('admin/users.html.twig', [
            'page'          => $page,
            'filters'       => $filters,
            'totalUsers'    => $users->count([]),
            'totalVerified' => $users->countVerified(),
            'totalAdmins'   => $users->countAdmins(),
            'newUsers7d'    => $users->countCreatedSince(new \DateTimeImmutable('-7 days')),
        ]);
    }

    #[Route('/users/{id}', name: 'user_show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(User $user, GameRepository $games, ModerationLogRepository $logs): Response
    {
        return $this->render('admin/user_show.html.twig', [
            'user'    => $user,
            'games'   => $games->searchForAdmin(owner: $user, perPage: 100)->items,
            'history' => $logs->forTarget(ModerationLog::TARGET_USER, (int) $user->getId()),
            'canDelete' => $this->deletionRefusal($user) === null,
            'deleteRefusal' => $this->deletionRefusal($user),
        ]);
    }

    #[Route('/users/{id}/delete', name: 'user_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(User $user, Request $request, ModerationLogger $logger, UserAccountRemover $remover): Response
    {
        $back = $this->redirectToRoute('app_admin_user_show', ['_locale' => $request->getLocale(), 'id' => $user->getId()]);

        if (!$this->isCsrfTokenValid('admin_user_delete_' . $user->getId(), $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }

        if ($refusal = $this->deletionRefusal($user)) {
            $this->addFlash('error', $refusal);

            return $back;
        }

        // Garde-fou contre les clics malheureux : il faut retaper l'email du compte
        if (mb_strtolower(trim($request->request->getString('confirm_email'))) !== mb_strtolower((string) $user->getEmail())) {
            $this->addFlash('error', "L'email saisi ne correspond pas à celui du compte : suppression annulée.");

            return $back;
        }

        $id    = (int) $user->getId();
        $label = sprintf('%s (%s %s)', $user->getEmail(), $user->getFirstName(), $user->getLastName());

        // La trace et la suppression sont validées ensemble
        $logger->log($this->adminUser(), ModerationAction::UserDeleted, ModerationLog::TARGET_USER, $id, $label, trim($request->request->getString('reason')));
        $remover->remove($user);

        $this->addFlash('success', sprintf('Compte %s supprimé.', $label));

        return $this->redirectToRoute('app_admin_users', ['_locale' => $request->getLocale()]);
    }

    /** Message expliquant pourquoi la suppression est refusée, ou null si elle est permise. */
    private function deletionRefusal(User $user): ?string
    {
        if ($user->getId() === $this->adminUser()->getId()) {
            return 'Vous ne pouvez pas supprimer votre propre compte depuis l\'administration.';
        }

        if ($user->isAdmin()) {
            return 'Un compte administrateur ne peut pas être supprimé ici : retirez d\'abord son rôle.';
        }

        return null;
    }

    private function adminUser(): User
    {
        $user = $this->getUser();
        \assert($user instanceof User);

        return $user;
    }
}
