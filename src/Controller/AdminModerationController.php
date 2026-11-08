<?php

namespace App\Controller;

use App\Enum\ModerationAction;
use App\Repository\ModerationLogRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMIN')]
#[Route('/admin', name: 'app_admin_')]
class AdminModerationController extends AbstractController
{
    #[Route('/moderation', name: 'moderation', methods: ['GET'])]
    public function index(Request $request, ModerationLogRepository $logs): Response
    {
        $filters = [
            'q'      => trim($request->query->getString('q')),
            'action' => $request->query->getString('action'),
        ];

        return $this->render('admin/moderation.html.twig', [
            'page'    => $logs->searchForAdmin($filters['q'], ModerationAction::tryFrom($filters['action']), $request->query->getInt('page', 1)),
            'filters' => $filters,
            'actions' => ModerationAction::cases(),
        ]);
    }
}
