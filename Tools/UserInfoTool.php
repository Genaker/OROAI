<?php

declare(strict_types=1);

namespace Genaker\Bundle\OroAI\Tools;

use Doctrine\DBAL\Connection;
use Genaker\Bundle\OroAI\Core\Contract\AiToolInterface;
use Genaker\Bundle\OroAI\Core\Model\ToolDefinition;
use Genaker\Bundle\OroAI\Core\Model\ToolResult;

/** AI tool to look up admin users, customer users, roles, and user counts via DBAL. */
final class UserInfoTool implements AiToolInterface
{
    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    public function getName(): string
    {
        return 'user_info';
    }

    public function getDefinition(): ToolDefinition
    {
        return new ToolDefinition(
            'user_info',
            'Look up admin users, customer users, or check user roles and permissions. '
            . 'Quick way to answer "do we have user X" or "what role does user Y have" without writing raw SQL.',
            [
                'type' => 'object',
                'properties' => [
                    'action' => [
                        'type' => 'string',
                        'enum' => ['find_admin_user', 'find_customer_user', 'list_roles', 'user_roles', 'count_users'],
                        'description' => 'Action to perform.',
                    ],
                    'email' => [
                        'type' => 'string',
                        'description' => 'Email address to search for (for find_admin_user and find_customer_user).',
                    ],
                    'username' => [
                        'type' => 'string',
                        'description' => 'Username to search for (for find_admin_user).',
                    ],
                    'user_id' => [
                        'type' => 'integer',
                        'description' => 'User ID (for user_roles).',
                    ],
                    'user_type' => [
                        'type' => 'string',
                        'enum' => ['admin', 'customer'],
                        'description' => 'User type for count_users (default: both).',
                    ],
                ],
                'required' => ['action'],
            ],
        );
    }

    public function execute(array $arguments): ToolResult
    {
        $action = $arguments['action'] ?? '';

        try {
            return match ($action) {
                'find_admin_user' => $this->findAdminUser($arguments),
                'find_customer_user' => $this->findCustomerUser($arguments),
                'list_roles' => $this->listRoles(),
                'user_roles' => $this->userRoles($arguments),
                'count_users' => $this->countUsers($arguments),
                default => ToolResult::error('Unknown action.'),
            };
        } catch (\Throwable $e) {
            return ToolResult::error('Database error: ' . $e->getMessage());
        }
    }

    private function findAdminUser(array $args): ToolResult
    {
        $qb = $this->connection->createQueryBuilder()
            ->select('u.id', 'u.username', 'u.email', 'u.first_name', 'u.last_name', 'u.enabled', 'u.last_login')
            ->from('oro_user', 'u');

        if (!empty($args['email'])) {
            $qb->andWhere('LOWER(u.email) = :email')
                ->setParameter('email', strtolower($args['email']));
        } elseif (!empty($args['username'])) {
            $qb->andWhere('LOWER(u.username) = :username')
                ->setParameter('username', strtolower($args['username']));
        } else {
            return ToolResult::error('Provide "email" or "username" to search.');
        }

        $rows = $qb->setMaxResults(5)->execute()->fetchAllAssociative();

        if ($rows === []) {
            return ToolResult::success(['found' => false, 'message' => 'No admin user found.']);
        }

        return ToolResult::success(['found' => true, 'users' => $rows]);
    }

    private function findCustomerUser(array $args): ToolResult
    {
        $email = $args['email'] ?? '';
        if ($email === '') {
            return ToolResult::error('Parameter "email" is required for customer user search.');
        }

        $qb = $this->connection->createQueryBuilder()
            ->select(
                'cu.id',
                'cu.email',
                'cu.first_name',
                'cu.last_name',
                'cu.enabled',
                'cu.confirmed',
                'cu.last_login',
                'c.name AS customer_name'
            )
            ->from('oro_customer_user', 'cu')
            ->leftJoin('cu', 'oro_customer', 'c', 'cu.customer_id = c.id')
            ->andWhere('LOWER(cu.email) = :email')
            ->setParameter('email', strtolower($email))
            ->setMaxResults(5);

        $rows = $qb->execute()->fetchAllAssociative();

        if ($rows === []) {
            return ToolResult::success(['found' => false, 'message' => 'No customer user found with that email.']);
        }

        return ToolResult::success(['found' => true, 'users' => $rows]);
    }

    private function listRoles(): ToolResult
    {
        $rows = $this->connection->createQueryBuilder()
            ->select('r.id', 'r.role', 'r.label')
            ->from('oro_access_role', 'r')
            ->orderBy('r.label')
            ->execute()
            ->fetchAllAssociative();

        return ToolResult::success(['roles' => $rows]);
    }

    private function userRoles(array $args): ToolResult
    {
        $userId = (int) ($args['user_id'] ?? 0);
        if ($userId === 0) {
            return ToolResult::error('Parameter "user_id" is required.');
        }

        $rows = $this->connection->createQueryBuilder()
            ->select('r.id', 'r.role', 'r.label')
            ->from('oro_user_access_role', 'ur')
            ->innerJoin('ur', 'oro_access_role', 'r', 'ur.role_id = r.id')
            ->andWhere('ur.user_id = :uid')
            ->setParameter('uid', $userId)
            ->execute()
            ->fetchAllAssociative();

        return ToolResult::success(['user_id' => $userId, 'roles' => $rows]);
    }

    private function countUsers(array $args): ToolResult
    {
        $type = $args['user_type'] ?? '';
        $result = [];

        if ($type === '' || $type === 'admin') {
            $result['admin_users'] = (int) $this->connection->executeQuery(
                'SELECT COUNT(*) FROM oro_user'
            )->fetchOne();
        }

        if ($type === '' || $type === 'customer') {
            $result['customer_users'] = (int) $this->connection->executeQuery(
                'SELECT COUNT(*) FROM oro_customer_user'
            )->fetchOne();
        }

        return ToolResult::success($result);
    }
}
