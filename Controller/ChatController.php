<?php

declare(strict_types=1);

namespace Genaker\Bundle\OroAI\Controller;

use Genaker\Bundle\OroAI\Agent\OroAiAgent;
use Genaker\Bundle\OroAI\Core\Model\ChatMessage;
use Genaker\Bundle\OroAI\Core\Model\Role;
use Genaker\Bundle\OroAI\Service\OroAiConfig;
use Oro\Bundle\SecurityBundle\Attribute\AclAncestor;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Twig\Environment;

final class ChatController
{
    public function __construct(
        private readonly OroAiAgent $agent,
        private readonly OroAiConfig $config,
        private readonly Environment $twig,
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
                'error' => 'AI Assistant is not configured. Please set the API key in System → Configuration → General Setup → Oro AI Assistant.',
                'not_configured' => true,
            ], Response::HTTP_BAD_REQUEST);
        }

        $data = json_decode($request->getContent(), true);

        $message = trim($data['message'] ?? '');
        if ($message === '') {
            return new JsonResponse(['error' => 'Message is required.'], Response::HTTP_BAD_REQUEST);
        }

        $history = $this->parseHistory($data['history'] ?? []);

        try {
            $result = $this->agent->run($message, $history);

            return new JsonResponse([
                'reply' => $result->reply,
                'tool_trace' => $result->toolTrace,
                'links' => $result->links,
            ]);
        } catch (\Throwable $e) {
            return new JsonResponse(
                ['error' => $this->humanizeError($e)],
                Response::HTTP_INTERNAL_SERVER_ERROR,
            );
        }
    }

    #[Route(
        path: '/admin/oroai/widget/chat',
        name: 'genaker_oroai_widget_chat',
        methods: ['GET'],
    )]
    #[AclAncestor('genaker_oroai_chat')]
    public function widgetAction(): Response
    {
        return new Response(
            $this->twig->render('@GenakerOroAI/Widget/aiChat.html.twig', [
                'configured' => $this->config->isConfigured(),
                'provider'   => $this->config->getProvider(),
            ])
        );
    }

    /** @return ChatMessage[] */
    private function parseHistory(array $raw): array
    {
        $messages = [];
        foreach ($raw as $entry) {
            $role = Role::tryFrom($entry['role'] ?? '');
            if ($role === null) {
                continue;
            }
            $messages[] = new ChatMessage(
                role: $role,
                content: $entry['content'] ?? '',
            );
        }

        return $messages;
    }

    private function humanizeError(\Throwable $e): string
    {
        $msg = $e->getMessage();

        if (str_contains($msg, '403 Forbidden')) {
            $provider = $this->config->getProvider();
            $host = match ($provider) {
                'anthropic' => 'api.anthropic.com',
                'gemini'    => 'generativelanguage.googleapis.com',
                default     => 'api.openai.com',
            };

            return sprintf(
                'The AI service (%s) is blocked by a network firewall or corporate proxy (Zscaler). '
                . 'Ask your IT administrator to allow outbound HTTPS access to %s.',
                $provider,
                $host,
            );
        }

        if (str_contains($msg, '401 Unauthorized')) {
            return 'Invalid API key. Please check the key in System → Configuration → General Setup → Oro AI Assistant.';
        }

        if (str_contains($msg, '429')) {
            return 'API rate limit exceeded. Please wait a moment and try again.';
        }

        if (str_contains($msg, '500') || str_contains($msg, '502') || str_contains($msg, '503')) {
            return 'The AI provider is temporarily unavailable. Please try again in a few minutes.';
        }

        if (
            str_contains($msg, 'cURL error')
            || str_contains($msg, 'Connection refused')
            || str_contains($msg, 'timed out')
            || str_contains($msg, 'Could not resolve host')
        ) {
            return 'Cannot connect to the AI service. Check that the server has outbound internet access.';
        }

        return 'An error occurred: ' . $msg;
    }
}
