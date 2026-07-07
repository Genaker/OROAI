<?php

declare(strict_types=1);

namespace Genaker\Bundle\OroAI\Command;

use Doctrine\Persistence\ManagerRegistry;
use Oro\Bundle\OrganizationBundle\Entity\BusinessUnit;
use Oro\Bundle\OrganizationBundle\Entity\Organization;
use Oro\Bundle\UserBundle\Entity\Role;
use Oro\Bundle\UserBundle\Entity\User;
use Oro\Bundle\UserBundle\Entity\UserManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Idempotently creates the dedicated backend admin user used by the OroAI
 * chat integration/e2e test suites, so those suites never depend on the real
 * "admin" account's password.
 *
 * Username/password default to the same OROAI_TEST_ADMIN_USERNAME /
 * OROAI_TEST_ADMIN_PASSWORD env vars phpunit-dev.xml sets for
 * ChatMessageEndpointTest, so both the PHP integration suite and any e2e
 * suite provision (and authenticate as) the exact same account.
 */
#[AsCommand(
    name: 'genaker:oroai:test:ensure-admin',
    description: 'Create the OroAI chat test admin user if it does not already exist',
)]
final class EnsureTestAdminUserCommand extends Command
{
    public function __construct(
        private readonly ManagerRegistry $doctrine,
        private readonly UserManager $userManager,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('username', null, InputOption::VALUE_REQUIRED, 'Test admin username')
            ->addOption('password', null, InputOption::VALUE_REQUIRED, 'Test admin password');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $username = $input->getOption('username') ?: (getenv('OROAI_TEST_ADMIN_USERNAME') ?: 'oroai_test_admin');
        $password = $input->getOption('password') ?: (getenv('OROAI_TEST_ADMIN_PASSWORD') ?: 'OroAiTest123!');

        $entityManager = $this->doctrine->getManagerForClass(User::class);
        $userRepository = $entityManager->getRepository(User::class);

        $organization = $entityManager->getRepository(Organization::class)->findOneBy(['enabled' => true]);
        $administratorRole = $entityManager->getRepository(Role::class)->findOneBy(['role' => 'ROLE_ADMINISTRATOR']);
        $businessUnit = $organization !== null
            ? $entityManager->getRepository(BusinessUnit::class)->findOneBy(['organization' => $organization])
            : null;

        if ($organization === null || $administratorRole === null || $businessUnit === null) {
            $io->error(
                'Cannot provision test admin user: no enabled Organization, ROLE_ADMINISTRATOR role, '
                . 'or BusinessUnit found in the database.'
            );

            return Command::FAILURE;
        }

        $user = $userRepository->findOneBy(['username' => $username]);
        if ($user !== null) {
            // Login requires at least one assigned business unit (Oro's UserChecker throws
            // EmptyBusinessUnitsException otherwise) -- backfill it if an earlier run of this
            // command predates that requirement being handled.
            if ($user->getBusinessUnits()->isEmpty()) {
                $user->addBusinessUnit($businessUnit);
                $user->setOwner($businessUnit);
                $this->userManager->updateUser($user);
                $io->success(sprintf('Test admin user "%s" (id=%d) was missing a business unit -- fixed.', $username, $user->getId()));

                return Command::SUCCESS;
            }

            $io->success(sprintf('Test admin user "%s" already exists (id=%d).', $username, $user->getId()));

            return Command::SUCCESS;
        }

        $user = new User();
        $user->setUsername($username);
        $user->setEmail($username . '@example.test');
        $user->setFirstName('OroAI');
        $user->setLastName('TestAdmin');
        $user->setEnabled(true);
        $user->setOrganization($organization);
        $user->addOrganization($organization);
        $user->addUserRole($administratorRole);
        $user->addBusinessUnit($businessUnit);
        $user->setOwner($businessUnit);
        $user->setPlainPassword($password);

        $this->userManager->updateUser($user);

        $io->success(sprintf('Created test admin user "%s" (id=%d).', $username, $user->getId()));

        return Command::SUCCESS;
    }
}
