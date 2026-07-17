<?php

declare(strict_types=1);

namespace Genaker\Bundle\OroAI\Controller;

use Genaker\Bundle\OroAI\Agent\ChatProgressStore;
use Genaker\Bundle\OroAI\Service\ChatOrchestrator;
use Genaker\Bundle\OroAI\Service\ChatSessionStore;
use Genaker\Bundle\OroAI\Service\LlmErrorPresenter;
use Genaker\Bundle\OroAI\Service\OroAiConfig;
use Oro\Bundle\DashboardBundle\Model\WidgetConfigs;
use Oro\Bundle\SecurityBundle\Attribute\AclAncestor;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Twig\Environment;

/**
 * HTTP endpoints for the AI chat widget — request parsing and JSON responses
 * only. Running the turn (agent/harness, history, transcript, cost, session
 * persistence) is ChatOrchestrator's job; explaining failures is
 * LlmErrorPresenter's.
 */
final class ChatController
{
    public function __construct(
        private readonly ChatOrchestrator $orchestrator,
        private readonly LlmErrorPresenter $errorPresenter,
        private readonly OroAiConfig $config,
        private readonly Environment $twig,
        private readonly ChatProgressStore $progressStore,
        private readonly WidgetConfigs $widgetConfigs,
        private readonly ChatSessionStore $sessionStore,
    ) {
    }

    #[Route(
        path: '/admin/oroai/chat/bar',
        name: 'genaker_oroai_chat_bar',
        methods: ['GET'],
    )]
    #[AclAncestor('genaker_oroai_chat')]
    public function barAction(): Response
    {
        return new Response('', Response::HTTP_NO_CONTENT);
    }

    #[Route(
        path: '/admin/oroai/chat/status',
        name: 'genaker_oroai_chat_status',
        methods: ['GET'],
        options: ['expose' => true],
    )]
    #[AclAncestor('genaker_oroai_chat')]
    public function statusAction(): JsonResponse
    {
        return new JsonResponse([
            'configured' => $this->config->isConfigured(),
            'provider' => $this->config->getProvider(),
        ]);
    }

    #[Route(
        path: '/admin/oroai/chat/message',
        name: 'genaker_oroai_chat_message',
        methods: ['POST'],
        options: ['expose' => true],
    )]
    #[AclAncestor('genaker_oroai_chat')]
    public function messageAction(Request $request): JsonResponse
    {
        if (!$this->config->isConfigured()) {
            return new JsonResponse([
                'error' => 'AI Assistant is not configured. '
                    . 'Please set the API key in System → Configuration → General Setup → Oro AI Assistant.',
                'not_configured' => true,
            ], Response::HTTP_BAD_REQUEST);
        }

        $data = json_decode($request->getContent(), true);

        $message = trim($data['message'] ?? '');
        if ($message === '') {
            return new JsonResponse(['error' => 'Message is required.'], Response::HTTP_BAD_REQUEST);
        }

        // Conversation history is loaded server-side from the session store by
        // session_id — a 'history' field in the payload (older cached widget
        // JS) is deliberately ignored.
        $sessionId = (string) ($data['session_id'] ?? '');

        // Optional: the widget generates a request id per message and polls
        // GET .../chat/progress with it, rendering a live checklist while
        // this (blocking) request is still running. No id means no progress
        // reporting -- older cached JS still works, just without the checklist.
        $requestId = (string) ($data['request_id'] ?? '');
        $onProgress = $requestId !== ''
            ? function (array $step) use ($requestId): void {
                $this->progressStore->addStep($requestId, $step);
            }
            : null;

        try {
            return new JsonResponse(
                $this->orchestrator->handle($message, $sessionId, $onProgress)->toArray(),
            );
        } catch (\Throwable $e) {
            return new JsonResponse(
                [
                    'error' => $this->errorPresenter->humanize($e),
                    'error_detail' => $this->errorPresenter->detail($e),
                ],
                Response::HTTP_INTERNAL_SERVER_ERROR,
            );
        } finally {
            if ($requestId !== '') {
                $this->progressStore->clear($requestId);
            }
        }
    }

    #[Route(
        path: '/admin/oroai/chat/progress',
        name: 'genaker_oroai_chat_progress',
        methods: ['GET'],
        options: ['expose' => true],
    )]
    #[AclAncestor('genaker_oroai_chat')]
    public function progressAction(Request $request): JsonResponse
    {
        $requestId = (string) $request->query->get('request_id', '');

        return new JsonResponse(['steps' => $this->progressStore->getSteps($requestId)]);
    }

    #[Route(
        path: '/admin/oroai/chat/sessions',
        name: 'genaker_oroai_chat_sessions',
        methods: ['GET'],
        options: ['expose' => true],
    )]
    #[AclAncestor('genaker_oroai_chat')]
    public function sessionsAction(): JsonResponse
    {
        return new JsonResponse(['sessions' => $this->sessionStore->getSessions()]);
    }

    #[Route(
        path: '/admin/oroai/chat/session',
        name: 'genaker_oroai_chat_session',
        methods: ['GET'],
        options: ['expose' => true],
    )]
    #[AclAncestor('genaker_oroai_chat')]
    public function sessionAction(Request $request): JsonResponse
    {
        $sessionId = (string) $request->query->get('id', '');

        return new JsonResponse([
            'id' => $sessionId,
            'messages' => $this->sessionStore->getMessages($sessionId),
        ]);
    }

    #[Route(
        path: '/admin/oroai/widget/chat',
        name: 'genaker_oroai_widget_chat',
        methods: ['GET'],
        options: ['expose' => true],
    )]
    #[AclAncestor('genaker_oroai_chat')]
    public function widgetAction(): Response
    {
        return new Response(
            $this->twig->render('@GenakerOroAI/Widget/aiChat.html.twig', array_merge(
                $this->widgetConfigs->getWidgetAttributesForTwig('genaker_oroai_chat'),
                [
                    'configured' => $this->config->isConfigured(),
                    'provider'   => $this->config->getProvider(),
                ]
            ))
        );
    }
}
