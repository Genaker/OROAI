<?php

declare(strict_types=1);

namespace Genaker\Bundle\OroAI\Tests\Unit\Core;

use Genaker\Bundle\OroAI\Core\Model\ChatMessage;
use Genaker\Bundle\OroAI\Core\Model\LlmResponse;
use Genaker\Bundle\OroAI\Core\Model\Role;
use Genaker\Bundle\OroAI\Core\Model\ToolCall;
use PHPUnit\Framework\TestCase;

final class ChatMessageTest extends TestCase
{
    public function testSystemFactory(): void
    {
        $msg = ChatMessage::system('You are a helpful assistant.');

        self::assertSame(Role::System, $msg->role);
        self::assertSame('You are a helpful assistant.', $msg->content);
        self::assertNull($msg->toolCallId);
        self::assertNull($msg->name);
        self::assertSame([], $msg->toolCalls);
    }

    public function testUserFactory(): void
    {
        $msg = ChatMessage::user('Hello, world!');

        self::assertSame(Role::User, $msg->role);
        self::assertSame('Hello, world!', $msg->content);
        self::assertNull($msg->toolCallId);
        self::assertNull($msg->name);
        self::assertSame([], $msg->toolCalls);
    }

    public function testAssistantToolCallsFactory(): void
    {
        $toolCalls = [
            new ToolCall(id: 'tc-1', name: 'sql_query', argsJson: '{"sql":"SELECT 1"}'),
            new ToolCall(id: 'tc-2', name: 'entity_url', argsJson: '{"entity":"order"}'),
        ];

        $response = new LlmResponse(
            content: 'Let me look that up.',
            toolCalls: $toolCalls,
            finishReason: 'tool_calls',
            usage: ['prompt_tokens' => 10, 'completion_tokens' => 20],
        );

        $msg = ChatMessage::assistantToolCalls($response);

        self::assertSame(Role::Assistant, $msg->role);
        self::assertSame('Let me look that up.', $msg->content);
        self::assertNull($msg->toolCallId);
        self::assertCount(2, $msg->toolCalls);
        self::assertSame('tc-1', $msg->toolCalls[0]->id);
        self::assertSame('sql_query', $msg->toolCalls[0]->name);
        self::assertSame('tc-2', $msg->toolCalls[1]->id);
        self::assertSame('entity_url', $msg->toolCalls[1]->name);
    }

    public function testAssistantToolCallsFactoryWithEmptyContent(): void
    {
        $response = new LlmResponse(
            content: '',
            toolCalls: [new ToolCall(id: 'tc-1', name: 'test', argsJson: '{}')],
            finishReason: 'tool_calls',
            usage: [],
        );

        $msg = ChatMessage::assistantToolCalls($response);

        self::assertSame(Role::Assistant, $msg->role);
        self::assertSame('', $msg->content);
        self::assertCount(1, $msg->toolCalls);
    }

    public function testToolResultFactory(): void
    {
        $msg = ChatMessage::toolResult('call-123', '{"success":true,"data":42}', 'sql_query');

        self::assertSame(Role::Tool, $msg->role);
        self::assertSame('{"success":true,"data":42}', $msg->content);
        self::assertSame('call-123', $msg->toolCallId);
        self::assertSame('sql_query', $msg->name);
        self::assertSame([], $msg->toolCalls);
    }

    public function testConstructorDirectly(): void
    {
        $msg = new ChatMessage(
            role: Role::User,
            content: 'direct',
            toolCallId: 'tc-x',
            name: 'my_tool',
            toolCalls: [],
        );

        self::assertSame(Role::User, $msg->role);
        self::assertSame('direct', $msg->content);
        self::assertSame('tc-x', $msg->toolCallId);
        self::assertSame('my_tool', $msg->name);
    }

    public function testRoleEnumValues(): void
    {
        self::assertSame('system', Role::System->value);
        self::assertSame('user', Role::User->value);
        self::assertSame('assistant', Role::Assistant->value);
        self::assertSame('tool', Role::Tool->value);
    }
}
